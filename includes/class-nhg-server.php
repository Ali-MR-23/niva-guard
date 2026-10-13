<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server modules: Heartbeat control, fast 404 and WP-Cron control.
 * Each module creates its own standalone mu-plugin (its settings live inside the file). Fast 404 also
 * creates 404.html in the site root and adds a separate block to .htaccess (with a backup and automatic rollback).
 */
final class NIVAWPG_Server {

    public const MU = [
        'heartbeat' => 'niva-guard-heartbeat.php',
        'fast404'   => 'niva-guard-fast-404.php',
    ];

    /** mu-plugin files of earlier versions (only removed, never created). */
    private const LEGACY_MU = ['niva-wp-guard-shortlink.php', 'niva-wp-guard-heartbeat.php', 'niva-wp-guard-fast-404.php'];

    public const MODES = ['default', 'interval', 'off'];

    private const BEGIN = '# BEGIN NIVA WP Guard';
    private const END = '# END NIVA WP Guard';
    private const MARK = 'nivawpg-404';
    private const MARK_OLD = 'nwg-404';
    /** Script files that scanners probe (answered with a fast 404 when they do not exist). */
    private const EXT_SCRIPT = 'php[0-9]?|pht(ml)?|phps|phar|cgi|pl|asp|aspx|jsp';
    /** Sensitive files: never served from outside, even when they exist (debug.log, backups …). */
    private const EXT_SENS = 'env|git|svn|bak|old|sql|log|ini|conf|swp';
    private const EXT = self::EXT_SCRIPT . '|' . self::EXT_SENS;
    private const DOTS = 'git|svn|hg|env|aws|ssh|DS_Store';
    private const CORE = 'index\.php|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-comments-post\.php|wp-signup\.php|wp-activate\.php|wp-trackback\.php';

    private static function has_mark(string $head): bool {
        return strpos($head, self::MARK) !== false || strpos($head, self::MARK_OLD) !== false;
    }

    /* ───────────── Paths ───────────── */

    public static function mu_dir(): string {
        return rtrim(WPMU_PLUGIN_DIR, '/\\');
    }

    /** The WordPress root folder (where index.php lives), with a trailing slash. */
    public static function root_path(): string {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        return trailingslashit(get_home_path());
    }

    /**
     * Site folders that must stay reachable in strict mode, relative to the site root, taken from the real URLs
     * (so a renamed content folder works too).
     *
     * @return string[]
     */
    public static function core_dirs(): array {
        $base = self::base_path();
        $dirs = [];

        foreach ([admin_url(), includes_url(), plugins_url(), get_theme_root_uri()] as $url) {
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            $path = ($base !== '' && strpos($path, $base . '/') === 0) ? substr($path, strlen($base)) : $path;
            $path = trim($path, '/');

            if ($path !== '') {
                $dirs[] = $path;
            }
        }

        return array_values(array_unique($dirs));
    }

    public static function mu_path(string $k): string {
        return self::mu_dir() . '/' . self::MU[$k];
    }

    public static function html_path(): string {
        return self::root_path() . '404.html';
    }

    public static function htaccess_path(): string {
        return self::root_path() . '.htaccess';
    }

    /** WordPress root path relative to the domain (for example '' or '/blog'). */
    public static function base_path(): string {
        return rtrim((string) wp_parse_url(site_url('/'), PHP_URL_PATH), '/');
    }

    public static function is_nginx(): bool {
        $sw = strtolower(sanitize_text_field(wp_unslash((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''))));

        return strpos($sw, 'nginx') !== false && strpos($sw, 'apache') === false && strpos($sw, 'litespeed') === false;
    }

    public static function wanted(string $k, array $s): bool {
        return $k === 'heartbeat' ? !empty($s['hb_on']) : !empty($s['f404_on']);
    }

    /* ───────────── mu-plugins ───────────── */

    private static function mu_header(string $title): string {
        return "<?php\n/**\n * Plugin Name: NIVA Guard — " . $title . "\n * Description: Generated automatically by NIVA Guard. Do not edit: change it in Settings → NIVA Guard → Server. It is removed together with the plugin.\n */\n\nif (!defined('ABSPATH')) {\n    return;\n}\n\n";
    }

    public static function mu_code(string $k, array $s): string {
        if ($k === 'heartbeat') {
            $cfg = [
                'interval' => max(1, min(3600, (int) $s['hb_interval'])),
                'dash'     => in_array($s['hb_dash'], self::MODES, true) ? $s['hb_dash'] : 'default',
                'editor'   => in_array($s['hb_editor'], self::MODES, true) ? $s['hb_editor'] : 'default',
                'admin'    => in_array($s['hb_admin'], self::MODES, true) ? $s['hb_admin'] : 'default',
                'front'    => in_array($s['hb_front'], self::MODES, true) ? $s['hb_front'] : 'default',
            ];

            return self::mu_header('Heartbeat control') . '$nivawpg_hb = ' . var_export($cfg, true) /* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates the PHP code of the mu-plugin file */ . <<<'PHP'
;

$nivawpg_hb_where = static function () {
    if (!is_admin()) {
        return 'front';
    }

    global $pagenow;

    if ($pagenow === 'index.php') {
        return 'dash';
    }

    if ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
        return 'editor';
    }

    return 'admin';
};

// "Slow": a longer Heartbeat interval
add_filter('heartbeat_settings', static function ($settings) use ($nivawpg_hb, $nivawpg_hb_where) {
    if (($nivawpg_hb[$nivawpg_hb_where()] ?? 'default') === 'interval') {
        $settings['interval'] = (int) $nivawpg_hb['interval'];
    }

    return $settings;
});

// "Off": the script is replaced by an empty stub so dependent scripts do not break
$nivawpg_hb_off = static function () use ($nivawpg_hb, $nivawpg_hb_where) {
    if (($nivawpg_hb[$nivawpg_hb_where()] ?? 'default') !== 'off') {
        return;
    }

    wp_deregister_script('heartbeat');
    wp_register_script('heartbeat', '', ['jquery'], null, true);
    wp_add_inline_script('heartbeat', 'window.wp=window.wp||{};wp.heartbeat=wp.heartbeat||{interval:function(){return 0},enqueue:function(){return false},dequeue:function(){return false},isEnqueued:function(){return false},connectNow:function(){},hasFocus:function(){return true},isHostUnreachable:function(){return false},hasConnectionError:function(){return false},suspend:function(){},resume:function(){}};');
};
add_action('wp_enqueue_scripts', $nivawpg_hb_off, 1);
add_action('admin_enqueue_scripts', $nivawpg_hb_off, 1);

PHP;
        }

        // fast404
        $cfg = ['base' => self::base_path(), 'root' => self::root_path(), 'dirs' => self::core_dirs(), 'strict' => !empty($s['f404_strict']) ? 1 : 0];

        $code = self::mu_header('Fast 404') . '$nivawpg_f = ' . var_export($cfg, true) /* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates the PHP code of the mu-plugin file */ . ';' . "\n" . "\$nivawpg_ext = '" . self::EXT . "';\n" . "\$nivawpg_dots = '" . self::DOTS . "';\n" . "\$nivawpg_core = '" . addslashes(self::CORE) . "';\n" . <<<'PHP'

if (!function_exists('nivawpg_fast404_send')) {
    /** Sends the static 404 page and stops (no theme, CSS or JS). */
    function nivawpg_fast404_send()
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Robots-Tag: noindex, nofollow');
        }

        global $nivawpg_f;
        $file = $nivawpg_f['root'] . '404.html';

        if (is_readable($file)) {
            readfile($file);
        } else {
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>404</title></head><body style="font-family:Arial,sans-serif;text-align:center;padding:15vh 20px"><h1 style="font-size:72px;margin:0">404</h1><p>Not found</p></body></html>';
        }

        exit;
    }
}

// 1) Early detection of scanner addresses: before plugins and theme are loaded
if (PHP_SAPI !== 'cli' && !empty($_SERVER['REQUEST_URI'])) {
    $nivawpg_path = str_replace("\0", '', rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH)));
    $nivawpg_rel = ($nivawpg_f['base'] !== '' && strpos($nivawpg_path, $nivawpg_f['base'] . '/') === 0) ? substr($nivawpg_path, strlen($nivawpg_f['base'])) : $nivawpg_path;

    if (preg_match('~\.(?:' . $nivawpg_ext . ')$~i', $nivawpg_rel) || preg_match('~(^|/)\.(?:' . $nivawpg_dots . ')(/|$)~i', $nivawpg_rel)) {
        $nivawpg_exists = is_file($nivawpg_f['root'] . ltrim($nivawpg_rel, '/'));
        $nivawpg_dirs = implode('|', array_map(static fn($d) => preg_quote($d, '~'), $nivawpg_f['dirs']));
        $nivawpg_allowed = (bool) preg_match('~^/(?:' . $nivawpg_core . ')$|^/(?:' . $nivawpg_dirs . ')/~i', $nivawpg_rel);

        // A missing file is a scanner probe; in strict mode existing script files outside core/plugins/themes are closed too
        if (!$nivawpg_exists || ($nivawpg_f['strict'] && !$nivawpg_allowed)) {
            nivawpg_fast404_send();
        }
    }

    unset($nivawpg_path, $nivawpg_rel, $nivawpg_exists, $nivawpg_dirs, $nivawpg_allowed);
}

// 2) Every other WordPress 404 is answered with the same light page before the theme renders.
// Deliberately the last priority: old-slug redirects, URL guessing (redirect_guess_404_permalink), SEO redirects (Yoast/Rank Math/Redirection),
// sitemaps and short links get their chance first; the static 404 is sent only if none of them answered.
add_action('template_redirect', static function () {
    if (is_404() && !is_robots() && !is_favicon()) {
        nivawpg_fast404_send();
    }
}, 9999);

PHP;

        return $code . self::shortlink_php();
    }

    /** Part of the fast-404 rules, not a separate feature: without it ?p=ID would 404 for products/pages and shared short links would break. */
    private static function shortlink_php(): string {
        return <<<'PHP'
if (!defined('NIVAWPG_SHORTLINK')) {
    define('NIVAWPG_SHORTLINK', 1);

    // ?p=ID must work for every post type (product, page …), not only posts
    add_filter('request', static function ($vars) {
        if (isset($vars['p']) && !isset($vars['post_type'])) {
            $vars['post_type'] = 'any';
        }

        return $vars;
    });
}

PHP;
    }

    /** Creates, updates or removes the mu-plugin file according to the settings. */
    public static function sync_mu(string $k, array $s): string {
        $path = self::mu_path($k);
        $has = is_file($path);

        if (!self::wanted($k, $s)) {
            if ($has) {
                wp_delete_file($path);
            }

            return 'off';
        }

        $code = self::mu_code($k, $s);

        if ($has && NIVAWPG_Fs::get($path) === $code) {
            return 'ok';
        }

        $dir = self::mu_dir();

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return 'unwritable';
        }

        if (!NIVAWPG_Fs::writable($dir) && !($has && NIVAWPG_Fs::writable($path))) {
            return 'unwritable';
        }

        return NIVAWPG_Fs::put($path, $code) ? 'ok' : 'unwritable';
    }

    /* ───────────── 404.html page ───────────── */

    public static function html404(): string {
        $fa = strpos(get_locale(), 'fa') === 0;
        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        $home = $home === '' ? '/' : $home;
        $brand = esc_html(wp_strip_all_tags((string) get_bloginfo('name')));

        $t = $fa ? [
            'lang' => 'fa', 'dir' => 'rtl',
            'font' => 'Vazirmatn, "Vazir", "Vazir FD", Tahoma, "Segoe UI", sans-serif',
            'title' => '۴۰۴ — صفحه پیدا نشد',
            'h1' => 'صفحه‌ای که دنبالش بودید پیدا نشد',
            'p' => 'ممکن است نشانی اشتباه وارد شده باشد یا صفحه جابه‌جا یا حذف شده باشد. از جستجو استفاده کنید یا به صفحه‌ی اصلی برگردید.',
            'ph' => 'جستجو در سایت…', 'search' => 'جستجو', 'home' => 'بازگشت به صفحه‌ی اصلی',
        ] : [
            'lang' => 'en', 'dir' => 'ltr',
            'font' => 'Arial, Helvetica, sans-serif',
            'title' => '404 — Page not found',
            'h1' => 'We could not find that page',
            'p' => 'The address may be mistyped, or the page may have been moved or removed. Try searching, or head back to the home page.',
            'ph' => 'Search the site…', 'search' => 'Search', 'home' => 'Back to home',
        ];

        $tpl = NIVAWPG_Fs::read_own(NIVAWPG_DIR . 'templates/404.html');
        $brand_html = $brand !== '' ? '  <span class="brand">' . $brand . "</span>\n" : '';

        return strtr($tpl, [
            '{{MARK}}'      => self::MARK,
            '{{LANG}}'      => $t['lang'],
            '{{DIR}}'       => $t['dir'],
            '{{FONT}}'      => $t['font'],
            '{{TITLE}}'     => $t['title'],
            '{{BRAND}}'     => $brand_html,
            '{{H1}}'        => $t['h1'],
            '{{P}}'         => $t['p'],
            '{{HOME}}'      => esc_attr($home),
            '{{PH}}'        => esc_attr($t['ph']),
            '{{SEARCH}}'    => $t['search'],
            '{{HOMELABEL}}' => $t['home'],
        ]);
    }

    /** 404.html is written only when it is missing or is this plugin's own file (it carries a marker); a custom file stays untouched. */
    public static function sync_html(bool $force = false): string {
        $path = self::html_path();
        $has = is_file($path);

        if ($has && !self::has_mark(NIVAWPG_Fs::head($path, 400))) {
            return 'custom';
        }

        $html = self::html404();

        if ($has && !$force && NIVAWPG_Fs::get($path) === $html) {
            return 'ok';
        }

        if (($has && !NIVAWPG_Fs::writable($path)) || (!$has && !NIVAWPG_Fs::writable(dirname($path)))) {
            return 'unwritable';
        }

        return NIVAWPG_Fs::put($path, $html) ? 'ok' : 'unwritable';
    }

    /* ───────────── .htaccess ───────────── */

    public static function htaccess_block(array $s): string {
        $base = self::base_path();
        $b = preg_quote($base, '#');
        $l = [self::BEGIN, '# Fast 404 for scanner probes. Generated automatically; change it from Settings → NIVA Guard → Server.', 'ErrorDocument 404 ' . $base . '/404.html', '', '<IfModule mod_rewrite.c>', 'RewriteEngine On', ''];
        $l[] = 'RewriteCond %{REQUEST_URI} \.(' . self::EXT_SENS . ')$ [NC]';
        $l[] = 'RewriteRule ^ - [R=404,L]';
        $l[] = '';
        $l[] = 'RewriteCond %{REQUEST_URI} \.(' . self::EXT_SCRIPT . ')$ [NC]';

        if (!empty($s['f404_strict'])) {
            $dirs = implode('|', array_map(static fn($d) => preg_quote($d, '#') . '/', self::core_dirs()));
            $l[] = 'RewriteCond %{REQUEST_URI} !^' . $b . '/(' . self::CORE . '|' . $dirs . ') [NC]';
        } else {
            $l[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
        }

        $l[] = 'RewriteRule ^ - [R=404,L]';
        $l[] = 'RewriteRule (^|/)\.(' . self::DOTS . ')(/|$) - [NC,R=404,L]';
        $l[] = '</IfModule>';
        $l[] = self::END;

        return implode("\n", $l);
    }

    /** For Nginx (no .htaccess): fake PHP probe files are handled by the mu-plugin. */
    public static function nginx_snippet(): string {
        return "# NIVA Guard — Fast 404 (Nginx)\n# PHP probe files are handled by the mu-plugin; this covers the other probe types.\nerror_page 404 /404.html;\nlocation = /404.html { internal; }\nlocation ~* \\.(phar|cgi|pl|asp|aspx|jsp|env|bak|old|sql|log|ini|conf|swp)$ { return 404; }\nlocation ~ /\\.(git|svn|hg|env|aws|ssh|DS_Store) { return 404; }\n";
    }

    private static function with_block(string $cur, string $block): string {
        $cur = str_replace("\r\n", "\n", $cur);
        $re = '/' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\n?/s';

        if (preg_match($re, $cur)) {
            return (string) preg_replace_callback($re, static fn() => $block . "\n", $cur, 1);
        }

        $p = strpos($cur, '# BEGIN WordPress');

        if ($p !== false) {
            return substr($cur, 0, $p) . $block . "\n\n" . substr($cur, $p);
        }

        return $block . "\n\n" . $cur;
    }

    private static function without_block(string $cur): string {
        $cur = str_replace("\r\n", "\n", $cur);
        // The block and the blank line after it (added on install) are removed so the file returns exactly to its previous state
        $re = '/' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\n\n?/s';

        return (string) preg_replace($re, '', $cur, 1);
    }

    /** off | nginx | ok | outdated | missing | unwritable */
    public static function htaccess_state(array $s): string {
        if (self::is_nginx()) {
            return 'nginx';
        }

        $path = self::htaccess_path();
        $cur = is_file($path) ? NIVAWPG_Fs::get($path) : '';
        $has = strpos($cur, self::BEGIN) !== false;

        if (empty($s['f404_on'])) {
            return $has ? 'outdated' : 'off';
        }

        if ($has) {
            return strpos(str_replace("\r\n", "\n", $cur), self::htaccess_block($s)) !== false ? 'ok' : 'outdated';
        }

        return (is_file($path) ? NIVAWPG_Fs::writable($path) : NIVAWPG_Fs::writable(dirname($path))) ? 'missing' : 'unwritable';
    }

    private static function backup(string $path): void {
        if (!is_file($path)) {
            return;
        }

        NIVAWPG_Fs::copy($path, $path . '.nivawpg-backup-' . gmdate('Ymd-His'));
        $old = (array) glob($path . '.nivawpg-backup-*');
        sort($old);

        foreach (array_slice($old, 0, max(0, count($old) - 3)) as $f) {
            wp_delete_file($f);
        }
    }

    /** @return array{ok:bool,code:string} ok | unchanged | unwritable | write | rolled_back | nginx */
    public static function install_htaccess(array $s): array {
        if (self::is_nginx()) {
            return ['ok' => false, 'code' => 'nginx'];
        }

        $path = self::htaccess_path();
        $exists = is_file($path);
        $cur = $exists ? NIVAWPG_Fs::get($path) : '';

        if (($exists && !NIVAWPG_Fs::writable($path)) || (!$exists && !NIVAWPG_Fs::writable(dirname($path)))) {
            return ['ok' => false, 'code' => 'unwritable'];
        }

        $new = self::with_block($cur, self::htaccess_block($s));

        if ($new === str_replace("\r\n", "\n", $cur)) {
            return ['ok' => true, 'code' => 'unchanged'];
        }

        self::backup($path);

        if (!NIVAWPG_Fs::put($path, $new)) {
            return ['ok' => false, 'code' => 'write'];
        }

        // Health check: if the site answers with a server error after the change the previous file is restored (a network error is inconclusive and does not roll back)
        $r = wp_remote_get(home_url('/'), ['timeout' => 8, 'redirection' => 0, 'headers' => ['Cache-Control' => 'no-cache']]);

        if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) >= 500) {
            NIVAWPG_Fs::put($path, $cur);

            return ['ok' => false, 'code' => 'rolled_back'];
        }

        return ['ok' => true, 'code' => 'written'];
    }

    public static function remove_htaccess(): bool {
        $path = self::htaccess_path();

        if (!is_file($path)) {
            return true;
        }

        $cur = NIVAWPG_Fs::get($path);

        if (strpos($cur, self::BEGIN) === false) {
            return true;
        }

        if (!NIVAWPG_Fs::writable($path)) {
            return false;
        }

        self::backup($path);

        return NIVAWPG_Fs::put($path, self::without_block($cur));
    }

    /* ───────────── WordPress cron (wp-config.php) ───────────── */

    /** Interval in minutes (60 and up = hours). */
    public const CRON_EVERY = [1, 5, 10, 15, 30, 60, 120, 180, 240, 300, 360];

    /** Cron schedule expression for the given interval (minutes). */
    public static function cron_schedule(int $minutes): string {
        if ($minutes >= 60) {
            $h = (int) ($minutes / 60);

            return $h === 1 ? '0 * * * *' : '0 */' . $h . ' * * *';
        }

        return $minutes === 1 ? '* * * * *' : '*/' . $minutes . ' * * * *';
    }

    private const CFG_BEGIN = '// BEGIN NIVA WP Guard cron';
    private const CFG_END = '// END NIVA WP Guard cron';

    public static function config_path(): string {
        $path = ABSPATH . 'wp-config.php';
        $up = dirname(untrailingslashit(ABSPATH));

        // WordPress allows wp-config.php one folder above the root
        if (!is_file($path) && is_file($up . '/wp-config.php') && !is_file($up . '/wp-settings.php')) {
            $path = $up . '/wp-config.php';
        }

        return $path;
    }

    /** Apart from this plugin's own block, did the user define DISABLE_WP_CRON elsewhere? */
    private static function cfg_external(string $c): bool {
        return (bool) preg_match('/^(?!\s*(?:\/\/|#|\*|\/\*)).*define\s*\(\s*[\'"]DISABLE_WP_CRON[\'"]/mi', self::cfg_strip($c));
    }

    private static function cfg_strip(string $c): string {
        return (string) preg_replace('/[ \t]*' . preg_quote(self::CFG_BEGIN, '/') . '.*?' . preg_quote(self::CFG_END, '/') . '[^\r\n]*(?:\r?\n){0,2}/s', '', $c);
    }

    private static function cfg_insert(string $c): ?string {
        $eol = strpos($c, "\r\n") !== false ? "\r\n" : "\n";
        $block = self::CFG_BEGIN . $eol . "define( 'DISABLE_WP_CRON', true );" . $eol . self::CFG_END . $eol . $eol;

        foreach (['/^[ \t]*\/\*\s*That\'s all/mi', '/^[ \t]*require_once\s+ABSPATH\s*\.\s*[\'"]wp-settings\.php/mi'] as $re) {
            if (preg_match($re, $c, $m, PREG_OFFSET_CAPTURE)) {
                return substr($c, 0, $m[0][1]) . $block . substr($c, $m[0][1]);
            }
        }

        return null;
    }

    /**
     * Adds or removes DISABLE_WP_CRON in wp-config.php. No backup copy is created
     * (a wp-config backup could be downloaded from outside); instead the previous file is restored if the site returns a server error afterwards.
     *
     * @return string off | ok | removed | unchanged | custom | unwritable | write | rolled_back | nomarker
     */
    public static function sync_cron(array $s): string {
        $want = !empty($s['cron_off']);
        $path = self::config_path();
        $cur = is_file($path) ? NIVAWPG_Fs::get($path) : '';

        if ($cur === '') {
            return $want ? 'unwritable' : 'off';
        }

        $has = strpos($cur, self::CFG_BEGIN) !== false;

        if (!$want && !$has) {
            return 'off';
        }

        if ($want && !$has && self::cfg_external($cur)) {
            return 'custom';
        }

        if (!NIVAWPG_Fs::writable($path)) {
            return 'unwritable';
        }

        $new = $want ? self::cfg_insert(self::cfg_strip($cur)) : self::cfg_strip($cur);

        if ($new === null) {
            return 'nomarker';
        }

        if ($new === $cur) {
            return 'unchanged';
        }

        if (!NIVAWPG_Fs::put($path, $new)) {
            return 'write';
        }

        $r = wp_remote_get(home_url('/'), ['timeout' => 8, 'redirection' => 0, 'headers' => ['Cache-Control' => 'no-cache']]);

        if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) >= 500) {
            NIVAWPG_Fs::put($path, $cur);

            return 'rolled_back';
        }

        return $want ? 'ok' : 'removed';
    }

    /** State for display: ok | custom | missing | unwritable | stale | off */
    public static function cron_status(array $s): string {
        $path = self::config_path();
        $c = is_file($path) ? NIVAWPG_Fs::get($path) : '';
        $has = strpos($c, self::CFG_BEGIN) !== false;
        $ext = $c !== '' && self::cfg_external($c);

        if (!empty($s['cron_off'])) {
            if ($has) {
                return 'ok';
            }

            return $ext ? 'custom' : (NIVAWPG_Fs::writable($path) ? 'missing' : 'unwritable');
        }

        return $has ? 'stale' : ($ext ? 'custom' : 'off');
    }

    /**
     * Server cron commands (cPanel / DirectAdmin …) matched to this site's PHP version.
     *
     * @return array{every:int,schedule:string,command:string,alt:string,php:string,bin:string}
     */
    public static function cron_info(array $s): array {
        $every = (int) ($s['cron_every'] ?? 5);
        $every = in_array($every, self::CRON_EVERY, true) ? $every : 5;
        $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $bin = '/usr/local/bin/ea-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        $dir = rtrim(str_replace('\\', '/', ABSPATH), '/');
        $dir = preg_match('/\s/', $dir) ? '"' . $dir . '"' : $dir;

        $nd = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        $run = static fn(string $php_bin): string => $php_bin . ' -q ' . $dir . '/wp-cron.php >/dev/null 2>&1';
        $url = site_url('wp-cron.php') . '?doing_wp_cron';
        $variants = [
            ['cPanel — domain-specific PHP version (ea-php' . $nd . ')', $run($bin)],
            ['cPanel — general (server default PHP)', $run('/usr/local/bin/php')],
            ['cPanel — /opt/cpanel path', $run('/opt/cpanel/ea-php' . $nd . '/root/usr/bin/php')],
            ['cPanel / CloudLinux — alt-php', $run('/opt/alt/php' . $nd . '/usr/bin/php')],
            ['DirectAdmin', $run('/usr/local/php' . $nd . '/bin/php')],
            ['Plesk', $run('/opt/plesk/php/' . $php . '/bin/php')],
            ['PHP (any host)', $run('php')],
            ['WP-CLI', 'cd ' . $dir . ' && wp cron event run --due-now >/dev/null 2>&1'],
            ['wget', 'wget -q -O /dev/null "' . $url . '" >/dev/null 2>&1'],
            ['curl', 'curl -s -o /dev/null "' . $url . '" >/dev/null 2>&1'],
        ];

        return [
            'every'    => $every,
            'schedule' => self::cron_schedule($every),
            'command'  => $bin . ' -q ' . $dir . '/wp-cron.php >/dev/null 2>&1',
            'alt'      => 'wget -q -O /dev/null "' . site_url('wp-cron.php') . '?doing_wp_cron" >/dev/null 2>&1',
            'php'      => $php,
            'bin'      => $bin,
            'variants' => $variants,
        ];
    }

    public const CRON_LAST = 'nivawpg_cron_last';

    /**
     * Records the last cron run: active only when this request is a cron run (wp-cron.php).
     * A successful run ends without a fatal error; a failed run is recorded separately with its error message.
     */
    public static function boot_cron_watch(): void {
        if (!defined('DOING_CRON') || !DOING_CRON) {
            return;
        }

        $t0 = microtime(true);
        $jobs = 0;

        add_filter('pre_unschedule_event', static function ($pre) use (&$jobs) {
            ++$jobs;

            return $pre;
        }, 10, 1);

        register_shutdown_function(static function () use ($t0, &$jobs): void {
            $e = error_get_last();
            $fatal = $e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
            $row = ['time' => time(), 'secs' => round(microtime(true) - $t0, 1), 'jobs' => $jobs, 'how' => PHP_SAPI === 'cli' ? 'cli' : 'web'];
            $d = (array) get_option(self::CRON_LAST, []);

            if ($fatal) {
                $d['fail'] = $row + ['msg' => substr(wp_strip_all_tags((string) $e['message']), 0, 160)];
            } else {
                $d['ok'] = $row;
            }

            update_option(self::CRON_LAST, $d, false);
        });
    }

    /** @return array{ok:array<string,mixed>,fail:array<string,mixed>,next:int} */
    public static function cron_last(): array {
        $d = (array) get_option(self::CRON_LAST, []);
        $jobs = function_exists('_get_cron_array') ? _get_cron_array() : [];

        return [
            'ok'   => (array) ($d['ok'] ?? []),
            'fail' => (array) ($d['fail'] ?? []),
            'next' => is_array($jobs) && $jobs ? (int) min(array_keys($jobs)) : 0,
        ];
    }

    /** Minutes the oldest scheduled task is overdue; 0 means no problem. */
    public static function cron_overdue(int $every): int {
        $jobs = function_exists('_get_cron_array') ? _get_cron_array() : [];

        if (!is_array($jobs) || !$jobs) {
            return 0;
        }

        $late = time() - (int) min(array_keys($jobs));

        return $late > max(900, $every * 180) ? (int) floor($late / 60) : 0;
    }

    /* ───────────── Apply and maintain ───────────── */

    /**
     * After the settings are saved (and on repair): the mu files, 404.html and, when requested, .htaccess are brought in line.
     *
     * @return array{mu:array<string,string>,html:string,htaccess:array{ok:bool,code:string}}
     */
    public static function apply(?array $s = null, bool $with_htaccess = true): array {
        $s = $s ?? NIVAWPG_Settings::get();
        $out = ['mu' => [], 'html' => 'off', 'htaccess' => ['ok' => true, 'code' => 'off']];

        self::drop_legacy_mu();

        foreach (array_keys(self::MU) as $k) {
            $out['mu'][$k] = self::sync_mu($k, $s);
        }

        if (!empty($s['f404_on'])) {
            $out['html'] = self::sync_html();

            if ($with_htaccess) {
                $out['htaccess'] = self::install_htaccess($s);
            }
        } else {
            self::remove_html();

            if ($with_htaccess) {
                self::remove_htaccess();
            }
        }

        return $out;
    }

    /** Removes mu-plugin files created by earlier versions. */
    private static function drop_legacy_mu(): void {
        foreach (self::LEGACY_MU as $name) {
            $path = self::mu_dir() . '/' . $name;

            if (is_file($path)) {
                wp_delete_file($path);
            }
        }
    }

    private static function remove_html(): void {
        $path = self::html_path();

        if (is_file($path) && self::has_mark(NIVAWPG_Fs::head($path, 400))) {
            wp_delete_file($path);
        }
    }

    /** On deactivation everything the plugin created outside its folder is removed. */
    public static function remove_all(): void {
        self::drop_legacy_mu();

        foreach (array_keys(self::MU) as $k) {
            if (is_file(self::mu_path($k))) {
                wp_delete_file(self::mu_path($k));
            }
        }

        self::remove_html();
        self::remove_htaccess();
        self::sync_cron(['cron_off' => 0]);
    }

    /** Silent repair (every 12 hours in the admin): recreates missing files; .htaccess only when the block is missing. */
    public static function heal(): void {
        $s = NIVAWPG_Settings::get();

        if (empty($s['files_ok'])) {
            return; // The user has not saved the settings yet; no file is created
        }

        self::apply($s, false);

        if (!empty($s['f404_on']) && self::htaccess_state($s) === 'missing') {
            self::install_htaccess($s);
        }
    }

    /** State of each part for the tab. */
    public static function status(array $s): array {
        $mu = [];

        foreach (array_keys(self::MU) as $k) {
            $path = self::mu_path($k);
            $want = self::wanted($k, $s);

            if (!$want) {
                $mu[$k] = is_file($path) ? 'outdated' : 'off';
            } elseif (!is_file($path)) {
                $mu[$k] = NIVAWPG_Fs::writable(self::mu_dir()) || (!is_dir(self::mu_dir()) && NIVAWPG_Fs::writable(dirname(self::mu_dir()))) ? 'missing' : 'unwritable';
            } else {
                $mu[$k] = NIVAWPG_Fs::get($path) === self::mu_code($k, $s) ? 'ok' : 'outdated';
            }
        }

        $hp = self::html_path();
        $html = 'off';

        if (!empty($s['f404_on'])) {
            if (!is_file($hp)) {
                $html = NIVAWPG_Fs::writable(dirname($hp)) ? 'missing' : 'unwritable';
            } elseif (!self::has_mark(NIVAWPG_Fs::head($hp, 400))) {
                $html = 'custom';
            } else {
                $html = 'ok';
            }
        }

        return ['mu' => $mu, 'html' => $html, 'htaccess' => self::htaccess_state($s)];
    }
}
