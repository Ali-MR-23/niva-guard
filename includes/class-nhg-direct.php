<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ردیابی عمیق (پیش‌فرض خاموش):
 *  ۱) NIVAWPG_Stream: لایه‌ی میانی پروتکل http/https برای اتصال‌هایی که API وردپرس را دور می‌زنند
 *     (file_get_contents('http…')، fopen، copy، simplexml_load_file و ...). ثبت و در صورت قانون، مسدود می‌شود.
 *  ۲) NIVAWPG_Scan: اسکن ایستای کد افزونه‌ها برای curl_exec / fsockopen و ...؛ این‌ها در PHP خالص زنده قابل شنود نیستند.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions -- wrapper پروتکل باید توابع بومی stream را صدا بزند
final class NIVAWPG_Stream {

    /** @var resource|null اضافه‌شده خودکار توسط PHP */
    public $context;

    /** @var resource|false */
    private $fh = false;

    private static bool $on = false;

    private const PROTOS = ['http', 'https'];

    public static function enable(): void {
        if (self::$on || !ini_get('allow_url_fopen')) {
            return;
        }

        foreach (self::PROTOS as $p) {
            if (!in_array($p, stream_get_wrappers(), true)) {
                continue;
            }

            stream_wrapper_unregister($p);
            stream_wrapper_register($p, self::class, STREAM_IS_URL);
        }

        self::$on = true;
    }

    /** برای باز کردن واقعی آدرس، لحظه‌ای wrapper داخلی PHP را برمی‌گردانیم */
    private static function native(): void {
        foreach (self::PROTOS as $p) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- اگر wrapper از قبل برگردانده شده باشد PHP هشدار می‌دهد
            @stream_wrapper_unregister($p);
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- همان دلیل
            @stream_wrapper_restore($p);
        }
    }

    private static function mine(): void {
        foreach (self::PROTOS as $p) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- wrapper ممکن است ثبت نشده باشد
            @stream_wrapper_unregister($p);
            stream_wrapper_register($p, self::class, STREAM_IS_URL);
        }
    }

    public function stream_open($path, $mode, $options, &$opened_path): bool {
        $method = 'GET';

        if (is_resource($this->context)) {
            $o = stream_context_get_options($this->context);
            $method = strtoupper((string) ($o['http']['method'] ?? 'GET'));
        }

        $info = null;

        try {
            $info = NIVAWPG_Engine::direct((string) $path, $method);
        } catch (\Throwable $e) {
            $info = null; // خطای ثبت هرگز نباید اتصال را بشکند
        }

        if ($info && $info['block']) {
            if ($options & STREAM_REPORT_ERRORS) {
                trigger_error('Blocked by NIVA WP Guard: ' . esc_html($path), E_USER_WARNING); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- باید مثل خطای عادی wrapper گزارش شود
            }

            return false;
        }

        $t0 = microtime(true);
        self::native();

        try {
            $this->fh = is_resource($this->context) ? fopen($path, $mode, false, $this->context) : fopen($path, $mode);
        } finally {
            self::mine();
        }

        if ($info) {
            NIVAWPG_Engine::direct_done($info['id'], microtime(true) - $t0, $this->fh !== false);
        }

        return $this->fh !== false;
    }

    public function stream_read($count) {
        return fread($this->fh, $count);
    }

    public function stream_write($data) {
        return fwrite($this->fh, $data);
    }

    public function stream_eof(): bool {
        return feof($this->fh);
    }

    public function stream_close(): void {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    public function stream_flush(): bool {
        return is_resource($this->fh) ? fflush($this->fh) : false;
    }

    public function stream_tell() {
        return ftell($this->fh);
    }

    public function stream_seek($offset, $whence): bool {
        return fseek($this->fh, $offset, $whence) === 0;
    }

    public function stream_stat() {
        return is_resource($this->fh) ? fstat($this->fh) : false;
    }

    public function stream_set_option($option, $arg1, $arg2): bool {
        if (!is_resource($this->fh)) {
            return false;
        }

        if ($option === STREAM_OPTION_BLOCKING) {
            return stream_set_blocking($this->fh, (bool) $arg1);
        }

        if ($option === STREAM_OPTION_READ_TIMEOUT) {
            return stream_set_timeout($this->fh, (int) $arg1, (int) $arg2);
        }

        return false;
    }

    public function url_stat($path, $flags) {
        return false; // file_exists('http://…') در PHP همیشه false است
    }
}

// phpcs:enable WordPress.WP.AlternativeFunctions

/**
 * اسکن ایستا: فقط می‌خواند و چیزی را اجرا یا مسدود نمی‌کند. نتیجه «احتمال» است، نه اتصال انجام‌شده.
 */
final class NIVAWPG_Scan {

    public const OPTION = 'nivawpg_scan';

    private const MAX_FILE = 2097152; // ۲ مگابایت
    private const MAX_FILES = 40;

    /** @return array{time:int,partial:bool,files:int,plugins:array<string,array>} */
    public static function get(): array {
        $r = get_option(self::OPTION, []);

        return is_array($r) && isset($r['plugins']) ? $r : ['time' => 0, 'partial' => false, 'files' => 0, 'plugins' => []];
    }

    public static function run(): array {
        if (function_exists('set_time_limit')) {
            set_time_limit(90);
        }

        $deadline = microtime(true) + 60;
        $out = ['time' => time(), 'partial' => false, 'files' => 0, 'plugins' => []];
        $roots = [
            WP_PLUGIN_DIR => '',
            WPMU_PLUGIN_DIR => 'mu:',
            get_theme_root() => 'theme:',
        ];

        foreach ($roots as $root => $prefix) {
            if (!is_dir($root)) {
                continue;
            }

            foreach ((array) scandir($root) as $name) {
                if ($name === '.' || $name === '..' || $name === 'index.php' || $name === 'niva-wp-guard' || $name === 'niva-http-guard' || $name === 'niva-wp-guard-loader.php' || $name === 'niva-http-guard-loader.php') {
                    continue;
                }

                if (strpos((string) $name, 'nhg-') === 0) {
                    continue; // لودر خود افزونه
                }

                $path = $root . '/' . $name;
                $slug = $prefix . preg_replace('/\.php$/', '', $name);

                if (is_dir($path)) {
                    self::scan_dir($path, $slug, $out, $deadline);
                } elseif (substr($name, -4) === '.php') {
                    self::scan_file($path, $slug, $out, false);
                }

                if (microtime(true) > $deadline) {
                    $out['partial'] = true;
                    break 2;
                }
            }
        }

        // فقط افزونه‌هایی که چیزی در آنها پیدا شد، به ترتیب «کد خودِ افزونه»
        uasort($out['plugins'], static fn($a, $b) => [$b['own_n'], $b['lib_n']] <=> [$a['own_n'], $a['lib_n']]);
        update_option(self::OPTION, $out, false);

        return $out;
    }

    private static function scan_dir(string $dir, string $slug, array &$out, float $deadline): void {
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    static function ($cur) {
                        $n = $cur->getFilename();

                        return !($cur->isDir() && in_array($n, ['node_modules', '.git', 'tests', 'test', 'languages'], true));
                    }
                )
            );
        } catch (\Throwable $e) {
            return;
        }

        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php' || $f->getSize() > self::MAX_FILE) {
                continue;
            }

            $p = str_replace('\\', '/', $f->getPathname());
            // کتابخانه‌های همراه (Composer، Guzzle، nusoap و ...) جدا از کد خود افزونه شمرده می‌شوند
            $is_lib = (bool) preg_match('#/(?:vendor|vendor_prefixed|vendors|libs?|libraries|third[-_]?party|sdk)/|nusoap|guzzle|phpseclib|/requests/#i', substr($p, strlen($dir)));
            self::scan_file($p, $slug, $out, $is_lib);

            if (microtime(true) > $deadline) {
                return;
            }
        }
    }

    private static function scan_file(string $file, string $slug, array &$out, bool $lib): void {
        $out['files']++;
        $lines = is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;

        if (!$lines) {
            return;
        }

        $pats = [
            'curl'  => '/\b(?:curl_init|curl_exec|curl_multi_exec)\s*\(/',
            'sock'  => '/\b(?:fsockopen|pfsockopen|stream_socket_client)\s*\(/',
            'fopen' => '/\b(?:file_get_contents|fopen|readfile|copy|get_headers|simplexml_load_file)\s*\(\s*[\'"]https?:\/\//i',
        ];
        $rel = ltrim(str_replace(str_replace('\\', '/', WP_CONTENT_DIR), '', str_replace('\\', '/', $file)), '/');

        $hits = [];

        foreach ($lines as $i => $line) {
            $t = ltrim($line);

            if ($t === '' || $t[0] === '*' || $t[0] === '#' || substr($t, 0, 2) === '//' || substr($t, 0, 2) === '/*') {
                continue;
            }

            foreach ($pats as $kind => $re) {
                if (preg_match($re, $line)) {
                    $hits[$kind][] = $i + 1;
                }
            }
        }

        if (!$hits) {
            return;
        }

        $e = &$out['plugins'][$slug];
        $e ??= ['own_n' => 0, 'lib_n' => 0, 'kinds' => [], 'files' => []];
        $n = 0;

        foreach ($hits as $kind => $ls) {
            $n += count($ls);
            $e['kinds'][$kind] = true;
        }

        $e[$lib ? 'lib_n' : 'own_n'] += $n;

        if (count($e['files']) < self::MAX_FILES) {
            $e['files'][] = ['f' => $rel, 'lib' => $lib, 'k' => array_map(static fn($ls) => array_slice($ls, 0, 4), $hits), 'n' => $n, 'h' => self::hosts(implode("\n", $lines))];
        }

        unset($e);
    }

    /** آدرس‌ها و IPهای ثابتی که در متن فایل نوشته شده (مقصدهای احتمالی، نه اتصال انجام‌شده) */
    private static function hosts(string $code): array {
        $found = [];

        // خط‌های توضیحات (لینک مستندات و ...) مقصد واقعی نیستند
        $code = implode("\n", array_filter(explode("\n", $code), static function ($l) {
            $t = ltrim($l);

            return $t !== '' && $t[0] !== '*' && $t[0] !== '#' && substr($t, 0, 2) !== '//' && substr($t, 0, 2) !== '/*';
        }));

        $hosts = [];

        if (preg_match_all('#https?://([a-z0-9][a-z0-9.-]*\.[a-z]{2,}|\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?#i', $code, $m)) {
            $hosts = $m[1];
        }

        // دامنه‌ی بدون http در رشته‌ی نقل‌قول (مثل 'api.mixpanel.com')
        if (preg_match_all('#[\'"]((?:[a-z0-9-]+\.)+(?:com|net|org|io|ir|co|ai|me|app|dev|cloud))(?::\d+)?(?:/[^\'"\s]*)?[\'"]#i', $code, $m2)) {
            $hosts = array_merge($hosts, $m2[1]);
        }

        {
            foreach ($hosts as $h) {
                $h = strtolower($h);

                if (preg_match('#(^|\.)(w3\.org|php\.net|example\.(com|org)|schemas?\.|xmlsoap\.org|xmlns|apache\.org|gnu\.org|github\.com|wordpress\.org|localhost|127\.0\.0\.1|0\.0\.0\.0)$#', $h) || strpos($h, 'schemas.') === 0) {
                    continue;
                }

                $found[$h] = true;
            }
        }

        return array_slice(array_keys($found), 0, 10);
    }
}
