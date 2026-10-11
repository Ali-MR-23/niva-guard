<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * موتور اتصال‌ها: ثبت (با زمان)، مسدودسازی، کش پاسخ لایسنس و آفلاین کردن افزونه.
 * فقط درخواست‌هایی را بررسی می‌کند که از یک افزونه یا قالب آمده‌اند؛ درخواست‌های خود هسته‌ی وردپرس (آپدیت و ...) دست‌نخورده می‌مانند.
 */
class NIVAWPG_Engine {

    /** @var array<string,array> */
    private static array $buffer = [];

    /** @var array<string,array{0:float,1:string}> */
    private static array $timers = [];

    /** @var array<string,array{0:string,1:int}> */
    private static array $pending = [];

    private static bool $booted = false;

    /** @var string[] خط‌های کندِ این درخواست برای فایل txt */
    private static array $slow_lines = [];

    public static function boot(): void {
        if (self::$booted) {
            return; // لودر زودتر و بارگذاری عادی هر دو صدا می‌زنند؛ فقط یک‌بار فعال می‌شود
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

    /** افزونه یا قالب فراخوان از روی backtrace؛ null = هسته‌ی وردپرس */
    private static function caller(): ?string {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- برای پیدا کردن افزونه‌ی درخواست‌دهنده لازم است
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $f) {
            $file = isset($f['file']) ? str_replace('\\', '/', $f['file']) : '';

            if (strpos($file, '/plugins/niva-wp-guard/') !== false || strpos($file, '/plugins/niva-http-guard/') !== false) {
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
            return $pre; // درخواست داخلی
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

        // ۱) آدرس‌های دلخواه
        foreach ((array) $s['custom'] as $line) {
            if ($line !== '' && NIVAWPG_Rules::matches($line, $hostpath)) {
                return self::blocked($id, $hostpath);
            }
        }

        // ۱.۵) ماژول «پارسی‌دیت»: حامیان (sponsors.json) و فید خبر wp-parsi.com
        if (!empty($s['ui_parsidate']) && (NIVAWPG_Rules::matches('raw.githubusercontent.com/wordpress-parsi/', $hostpath) || NIVAWPG_Rules::matches('wp-parsi.com/', $hostpath))) {
            return self::blocked($id, $hostpath);
        }

        // ۲) قانون‌های شناخته‌شده (مسدود یا کش)
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

                    return $hit; // پاسخ ذخیره‌شده؛ بدون اتصال
                }

                self::$pending[(string) $url] = [$key, max(1, (int) $s['cache_hours']) * HOUR_IN_SECONDS];
            }
        }

        // ۳) افزونه‌ی آفلاین: همه‌چیز به‌جز wordpress.org
        if (in_array($plugin, (array) $s['offline'], true)) {
            $org = ($host === 'wordpress.org' || substr($host, -14) === '.wordpress.org');

            if (!$org) {
                return self::blocked($id, $hostpath);
            }
        }

        // ۴) قطع‌کننده‌ی خودکار: هاستی که تازه پشت‌سرهم خطا یا کندی داشته، تا پایان مهلت بدون معطلی رد می‌شود (لایسنس‌ها معاف‌اند)
        if (!empty($s['breaker']) && !self::brk_exempt($host, $hostpath, $s)) {
            $b = NIVAWPG_Settings::breaker();

            if (isset($b[$host]) && (int) $b[$host]['until'] > time()) {
                if (isset(self::$buffer[$id])) {
                    self::$buffer[$id]['brk'] = (self::$buffer[$id]['brk'] ?? 0) + 1;
                }

                unset(self::$timers[(string) $url]);

                return new WP_Error('nivawpg_breaker', 'NIVA WP Guard: host temporarily skipped (' . $host . ')');
            }
        }

        return $pre;
    }

    /**
     * اتصال مستقیم PHP (از wrapper پروتکل). ثبت می‌کند و می‌گوید مسدود شود یا نه.
     *
     * @return array{id:string,block:bool}|null null = ثبت/بررسی نمی‌شود (هسته، آدرس داخلی)
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

        if (!$block && !empty($s['ui_parsidate']) && (NIVAWPG_Rules::matches('raw.githubusercontent.com/wordpress-parsi/', $hostpath) || NIVAWPG_Rules::matches('wp-parsi.com/', $hostpath))) {
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

    /** پایان اتصال مستقیم: ثبت مدت */
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

    /** معاف از قطع‌کننده: لایسنس‌ها همیشه، و هاست‌هایی که کاربر معاف کرده (خود هاست یا زیردامنه‌اش) */
    private static function brk_exempt(string $host, string $hostpath, array $s): bool {
        foreach ((array) ($s['breaker_off'] ?? []) as $h) {
            if ($h !== '' && ($host === $h || substr($host, -strlen($h) - 1) === '.' . $h)) {
                return true;
            }
        }

        return self::is_license($hostpath);
    }

    /** آدرس‌های «لایسنس» هرگز توسط قطع‌کننده رد نمی‌شوند */
    private static function is_license(string $hostpath): bool {
        foreach (NIVAWPG_Rules::all() as $r) {
            if ($r['type'] === 'license' && NIVAWPG_Rules::matches($r['match'], $hostpath)) {
                return true;
            }
        }

        return false;
    }

    /** ثبت نتیجه‌ی یک اتصال برای قطع‌کننده: خطا یا پاسخ کندتر از آستانه = شکست */
    private static function breaker_record(string $host, $response, float $dt): void {
        $s = NIVAWPG_Settings::get();
        $b = NIVAWPG_Settings::breaker();
        $e = $b[$host] ?? ['fails' => 0, 'until' => 0, 'trips' => 0, 't' => 0, 'err' => ''];
        $now = time();
        $err = is_wp_error($response) && strpos((string) $response->get_error_code(), 'nivawpg_') !== 0;
        $slow = $dt >= max(1, (float) $s['breaker_slow']);

        if (!$err && !$slow) {
            if (isset($b[$host])) {
                unset($b[$host]); // سالم شد
                update_option(NIVAWPG_Settings::BREAKER, $b);
            }

            return;
        }

        if (is_wp_error($response) && !$err) {
            return; // مسدودسازی خودمان خطای هاست نیست
        }

        $probe = $e['until'] > 0 && $now >= $e['until']; // اولین تلاشِ بعد از پایان مهلت

        if ($now - (int) $e['t'] > HOUR_IN_SECONDS && !$probe) {
            $e['fails'] = 0; // خطاهای قدیمی دیگر شمرده نمی‌شوند
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

        return new WP_Error('nivawpg_blocked', 'Blocked by NIVA WP Guard: ' . $hostpath);
    }

    private static function cache_key(array $args, string $url): string {
        $body = $args['body'] ?? '';
        $body = is_string($body) ? $body : wp_json_encode($body);

        return 'nivawpg_c_' . md5(($args['method'] ?? 'GET') . '|' . $url . '|' . $body);
    }

    /** ذخیره‌ی پاسخ موفق (۲۰۰) برای قانون‌هایی که کش روشن دارند */
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

    /** ثبت مدت پاسخ */
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

    /** نوشتن دسته‌جمعی گزارش در پایان درخواست (یک بار نوشتن در دیتابیس) */
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
