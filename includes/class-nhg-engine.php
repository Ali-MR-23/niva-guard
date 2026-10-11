<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Connection engine: logging (with timing), blocking, license response cache and offline plugins.
 * Only requests that come from a plugin or a theme are examined; requests made by WordPress core (updates …) are left alone.
 */
class NIVAWPG_Engine {

    /** @var array<string,array> */
    private static array $buffer = [];

    /** @var array<string,array{0:float,1:string}> */
    private static array $timers = [];

    /** @var array<string,array{0:string,1:int}> */
    private static array $pending = [];

    private static bool $booted = false;

    /** @var string[] Slow lines of this request for the text file. */
    private static array $slow_lines = [];

    public static function boot(): void {
        if (self::$booted) {
            return; // Called by the loader and by the normal load; it only boots once
        }

        self::$booted = true;
        add_filter('pre_http_request', [self::class, 'pre'], 1, 3);
        add_filter('http_response', [self::class, 'store_cache'], 10, 3);
        add_action('http_api_debug', [self::class, 'timing'], 10, 5);
        add_action('shutdown', [self::class, 'flush']);

        if (!empty(NIVAWPG_Settings::get()['deep']) && !empty(NIVAWPG_Settings::get()['enabled'])) {
            NIVAWPG_Stream::enable();
        }
    }

    /** Calling plugin or theme found in the backtrace; null = WordPress core. */
    private static function caller(): ?string {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- needed to find the plugin that made the request
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $f) {
            $file = isset($f['file']) ? str_replace('\\', '/', $f['file']) : '';

            if (strpos($file, '/plugins/niva-guard/') !== false || strpos($file, '/plugins/niva-wp-guard/') !== false || strpos($file, '/plugins/niva-http-guard/') !== false) {
                continue;
            }

            if (preg_match('#/(?:mu-)?plugins/([^/]+)/#', $file, $m)) {
                return $m[1];
            }

            if (preg_match('#/themes/([^/]+)/#', $file, $m)) {
                return 'theme:' . $m[1];
            }
        }

        return null;
    }

    private static function context(): string {
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return 'cron';
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return 'ajax';
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest';
        }

        return is_admin() ? 'admin' : 'site';
    }

    public static function pre($pre, $args, $url) {
        if ($pre !== false) {
            return $pre;
        }

        $s = NIVAWPG_Settings::get();

        if (empty($s['enabled'])) {
            return $pre;
        }

        $plugin = self::caller();

        if ($plugin === null) {
            return $pre;
        }

        $p = wp_parse_url((string) $url);
        $host = strtolower($p['host'] ?? '');
        $path = $p['path'] ?? '';
        $site = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        if ($host === '' || $host === $site || $host === 'localhost') {
            return $pre; // Internal request
        }

        $hostpath = $host . $path;
        $id = md5($plugin . '|' . $hostpath);

        if (!empty($s['log'])) {
            $e = self::$buffer[$id] ?? ['plugin' => $plugin, 'host' => $host, 'path' => $path, 'count' => 0, 'blocked' => 0, 'cached' => 0, 'total' => 0.0, 'max' => 0.0, 'status' => '', 'ctx' => self::context(), 'method' => strtoupper((string) ($args['method'] ?? 'GET')), 'last' => time()];
            $e['count']++;
            self::$buffer[$id] = $e;
        }

        if (!empty($s['log']) || !empty($s['breaker'])) {
            self::$timers[(string) $url] = [microtime(true), $id, $host, $hostpath];
        }

        // 1) Custom addresses
        foreach ((array) $s['custom'] as $line) {
            if ($line !== '' && NIVAWPG_Rules::matches($line, $hostpath)) {
                return self::blocked($id, $hostpath);
            }
        }

        // 1.5) ParsiDate module: sponsors list and the wp-parsi.com news feed
        if (!empty($s['ui_parsidate']) && (NIVAWPG_Rules::matches(NIVAWPG_Rules::PARSI_RAW, $hostpath) || NIVAWPG_Rules::matches('wp-parsi.com/', $hostpath))) {
            return self::blocked($id, $hostpath);
        }

        // 2) Known rules (block or cache)
        foreach (NIVAWPG_Rules::all() as $r) {
            if (!NIVAWPG_Rules::matches($r['match'], $hostpath)) {
                continue;
            }

            if (!empty($s['block'][$r['id']])) {
                return self::blocked($id, $hostpath);
            }

            if (!empty($r['cache']) && !empty($s['cache'][$r['id']])) {
                $key = self::cache_key($args, (string) $url);
                $hit = get_transient($key);

                if (is_array($hit)) {
                    if (isset(self::$buffer[$id])) {
                        self::$buffer[$id]['cached']++;
                        unset(self::$timers[(string) $url]);
                    }

                    return $hit; // Stored response; no connection
                }

                self::$pending[(string) $url] = [$key, max(1, (int) $s['cache_hours']) * HOUR_IN_SECONDS];
            }
        }

        // 3) Offline plugin: everything except wordpress.org
        if (in_array($plugin, (array) $s['offline'], true)) {
            $org = ($host === 'wordpress.org' || substr($host, -14) === '.wordpress.org');

            if (!$org) {
                return self::blocked($id, $hostpath);
            }
        }

        // 4) Circuit breaker: a host that keeps failing or is slow is skipped until the period ends (licenses are exempt)
        if (!empty($s['breaker']) && !self::brk_exempt($host, $hostpath, $s)) {
            $b = NIVAWPG_Settings::breaker();

            if (isset($b[$host]) && (int) $b[$host]['until'] > time()) {
                if (isset(self::$buffer[$id])) {
                    self::$buffer[$id]['brk'] = (self::$buffer[$id]['brk'] ?? 0) + 1;
                }

                unset(self::$timers[(string) $url]);

                return new WP_Error('nivawpg_breaker', 'NIVA Guard: host temporarily skipped (' . $host . ')');
            }
        }

        return $pre;
    }

    /**
     * Direct PHP connection (from the stream wrapper): logs it and says whether to block it.
     *
     * @return array{id:string,block:bool}|null null = not logged or checked (core, internal address)
     */
    public static function direct(string $url, string $method = 'GET'): ?array {
        $s = NIVAWPG_Settings::get();

        if (empty($s['enabled']) || empty($s['deep'])) {
            return null;
        }

        $plugin = self::caller();

        if ($plugin === null) {
            return null;
        }

        $p = wp_parse_url($url);
        $host = strtolower($p['host'] ?? '');
        $path = $p['path'] ?? '';
        $site = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        if ($host === '' || $host === $site || $host === 'localhost') {
            return null;
        }

        $hostpath = $host . $path;
        $id = md5($plugin . '|' . $hostpath);

        if (!empty($s['log'])) {
            $e = self::$buffer[$id] ?? ['plugin' => $plugin, 'host' => $host, 'path' => $path, 'count' => 0, 'blocked' => 0, 'cached' => 0, 'total' => 0.0, 'max' => 0.0, 'status' => '', 'ctx' => self::context(), 'method' => 'PHP-' . $method, 'last' => time()];
            $e['count']++;
            self::$buffer[$id] = $e;
        }

        $block = false;

        foreach ((array) $s['custom'] as $line) {
            if ($line !== '' && NIVAWPG_Rules::matches($line, $hostpath)) {
                $block = true;
            }
        }

        if (!$block && !empty($s['ui_parsidate']) && (NIVAWPG_Rules::matches(NIVAWPG_Rules::PARSI_RAW, $hostpath) || NIVAWPG_Rules::matches('wp-parsi.com/', $hostpath))) {
            $block = true;
        }

        if (!$block) {
            foreach (NIVAWPG_Rules::all() as $r) {
                if (!empty($s['block'][$r['id']]) && NIVAWPG_Rules::matches($r['match'], $hostpath)) {
                    $block = true;
                    break;
                }
            }
        }

        if (!$block && in_array($plugin, (array) $s['offline'], true) && !($host === 'wordpress.org' || substr($host, -14) === '.wordpress.org')) {
            $block = true;
        }

        if ($block && isset(self::$buffer[$id])) {
            self::$buffer[$id]['blocked']++;
        }

        return ['id' => $id, 'block' => $block];
    }

    /** End of a direct connection: record the duration. */
    public static function direct_done(string $id, float $dt, bool $ok): void {
        if (!isset(self::$buffer[$id])) {
            return;
        }

        if ($dt >= 0.5) {
            $e = self::$buffer[$id];
            self::$slow_lines[] = gmdate('Y-m-d H:i:s') . ' | SLOW ' . number_format($dt, 2, '.', '') . 's | ' . ($ok ? 'OK' : 'ERROR') . ' | ' . $e['host'] . $e['path'];
        }

        self::$buffer[$id]['total'] += $dt;
        self::$buffer[$id]['max'] = max(self::$buffer[$id]['max'], $dt);
        self::$buffer[$id]['status'] = $ok ? 'OK' : 'ERR';
    }

    /** Exempt from the breaker: licenses always, and hosts the user exempted (the host or a subdomain). */
    private static function brk_exempt(string $host, string $hostpath, array $s): bool {
        foreach ((array) ($s['breaker_off'] ?? []) as $h) {
            if ($h !== '' && ($host === $h || substr($host, -strlen($h) - 1) === '.' . $h)) {
                return true;
            }
        }

        return self::is_license($hostpath);
    }

    /** "License" addresses are never skipped by the breaker. */
    private static function is_license(string $hostpath): bool {
        foreach (NIVAWPG_Rules::all() as $r) {
            if ($r['type'] === 'license' && NIVAWPG_Rules::matches($r['match'], $hostpath)) {
                return true;
            }
        }

        return false;
    }

    /** Record the result of a connection for the breaker: an error or a response slower than the limit is a failure. */
    private static function breaker_record(string $host, $response, float $dt): void {
        $s = NIVAWPG_Settings::get();
        $b = NIVAWPG_Settings::breaker();
        $e = $b[$host] ?? ['fails' => 0, 'until' => 0, 'trips' => 0, 't' => 0, 'err' => ''];
        $now = time();
        $err = is_wp_error($response) && strpos((string) $response->get_error_code(), 'nivawpg_') !== 0;
        $slow = $dt >= max(1, (float) $s['breaker_slow']);

        if (!$err && !$slow) {
            if (isset($b[$host])) {
                unset($b[$host]); // Healthy again
                update_option(NIVAWPG_Settings::BREAKER, $b);
            }

            return;
        }

        if (is_wp_error($response) && !$err) {
            return; // Our own blocking is not a host failure
        }

        $probe = $e['until'] > 0 && $now >= $e['until']; // First try after the skip period ended

        if ($now - (int) $e['t'] > HOUR_IN_SECONDS && !$probe) {
            $e['fails'] = 0; // Old failures no longer count
        }

        $e['fails']++;
        $e['t'] = $now;
        $e['err'] = $err ? (string) $response->get_error_message() : 'slow ' . number_format($dt, 1, '.', '') . 's';
        $e['err'] = mb_substr($e['err'], 0, 120);

        if ($probe || $e['fails'] >= max(1, (int) $s['breaker_fails'])) {
            $e['until'] = $now + max(1, (int) $s['breaker_hours']) * HOUR_IN_SECONDS;
            $e['trips']++;
            $e['fails'] = 0;
        }

        $b[$host] = $e;

        if (count($b) > 100) {
            uasort($b, static fn($x, $y) => $y['t'] <=> $x['t']);
            $b = array_slice($b, 0, 100, true);
        }

        update_option(NIVAWPG_Settings::BREAKER, $b);
    }

    private static function blocked(string $id, string $hostpath): WP_Error {
        if (isset(self::$buffer[$id])) {
            self::$buffer[$id]['blocked']++;
        }

        return new WP_Error('nivawpg_blocked', 'Blocked by NIVA Guard: ' . $hostpath);
    }

    private static function cache_key(array $args, string $url): string {
        $body = $args['body'] ?? '';
        $body = is_string($body) ? $body : wp_json_encode($body);

        return 'nivawpg_c_' . md5(($args['method'] ?? 'GET') . '|' . $url . '|' . $body);
    }

    /** Store a successful (200) response for rules with caching on. */
    public static function store_cache($response, $args, $url) {
        if (empty(self::$pending[(string) $url])) {
            return $response;
        }

        [$key, $ttl] = self::$pending[(string) $url];
        unset(self::$pending[(string) $url]);

        if (is_array($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $headers = $response['headers'] ?? [];
            set_transient($key, [
                'headers'       => is_object($headers) && method_exists($headers, 'getAll') ? $headers->getAll() : (array) $headers,
                'body'          => $response['body'] ?? '',
                'response'      => $response['response'] ?? ['code' => 200, 'message' => 'OK'],
                'cookies'       => [],
                'filename'      => null,
                'http_response' => null,
            ], $ttl);
        }

        return $response;
    }

    /** Record the response time. */
    public static function timing($response, $context, $class, $args, $url): void {
        if ($context !== 'response' || !isset(self::$timers[(string) $url])) {
            return;
        }

        [$t0, $id, $host, $hostpath] = self::$timers[(string) $url] + [null, null, '', ''];
        unset(self::$timers[(string) $url]);
        $dt = microtime(true) - $t0;

        $cfg = NIVAWPG_Settings::get();

        if ($host !== '' && !empty($cfg['breaker']) && !self::brk_exempt($host, $hostpath, $cfg)) {
            self::breaker_record($host, $response, $dt);
        }

        if (!isset(self::$buffer[$id])) {
            return;
        }
        $status = is_wp_error($response) ? 'ERROR' : (string) wp_remote_retrieve_response_code($response);

        if ($dt >= 0.5) {
            $e = self::$buffer[$id];
            self::$slow_lines[] = gmdate('Y-m-d H:i:s') . ' | SLOW ' . number_format($dt, 2, '.', '') . 's | ' . $status . ' | ' . $e['host'] . $e['path'];
        }

        self::$buffer[$id]['total'] += $dt;
        self::$buffer[$id]['max'] = max(self::$buffer[$id]['max'], $dt);
        self::$buffer[$id]['status'] = is_wp_error($response) ? 'ERR' : (string) wp_remote_retrieve_response_code($response);
    }

    /** Write the report in one batch at the end of the request (a single database write). */
    public static function flush(): void {
        if (!self::$buffer && !self::$slow_lines) {
            return;
        }

        $seen = NIVAWPG_Settings::seen();
        $lines = [];

        foreach (self::$buffer as $id => $e) {
            if (!isset($seen[$id])) {
                $lines[] = gmdate('Y-m-d H:i:s') . ' | ' . $e['plugin'] . ' | ' . $e['ctx'] . ' | ' . ($e['method'] ?? 'GET') . ' | ' . $e['host'] . $e['path'] . ' | start';
            }

            if (isset($seen[$id])) {
                $o = $seen[$id];
                $o['count'] += $e['count'];
                $o['blocked'] += $e['blocked'];
                $o['cached'] += $e['cached'];
                $o['brk'] = ($o['brk'] ?? 0) + ($e['brk'] ?? 0);
                $o['total'] += $e['total'];
                $o['max'] = max($o['max'], $e['max']);
                $o['status'] = $e['status'] !== '' ? $e['status'] : $o['status'];
                $o['ctx'] = $e['ctx'];
                $o['last'] = $e['last'];
                $seen[$id] = $o;
            } else {
                $seen[$id] = $e;
            }
        }

        if (count($seen) > 300) {
            uasort($seen, static fn($a, $b) => $b['last'] <=> $a['last']);
            $seen = array_slice($seen, 0, 300, true);
        }

        update_option(NIVAWPG_Settings::SEEN, $seen, false);
        NIVAWPG_Setup::append_log(array_merge($lines, self::$slow_lines));
        self::$buffer = [];
        self::$slow_lines = [];
    }
}
