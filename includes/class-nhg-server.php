<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ماژول‌های سرور: کنترل Heartbeat، ۴۰۴ سریع و لینک کوتاه همگانی.
 * هر ماژول یک mu-plugin جدا و مستقل می‌سازد (تنظیمات داخل خود فایل ذخیره می‌شود). ۴۰۴ سریع علاوه بر آن
 * فایل 404.html را در ریشه‌ی سایت می‌سازد و یک بلوک جدا در .htaccess می‌گذارد (با بک‌آپ و بازگشت خودکار).
 */
final class NIVAWPG_Server {

    public const MU = [
        'heartbeat' => 'niva-wp-guard-heartbeat.php',
        'fast404'   => 'niva-wp-guard-fast-404.php',
    ];

    /** فایل قدیمیِ ماژول لینک کوتاه (حذف‌شده؛ فقط برای پاک‌سازی) */
    private const LEGACY_MU = 'niva-wp-guard-shortlink.php';

    public const MODES = ['default', 'interval', 'off'];

    private const BEGIN = '# BEGIN NIVA WP Guard';
    private const END = '# END NIVA WP Guard';
    private const MARK = 'nivawpg-404';
    private const MARK_OLD = 'nwg-404';
    /** فایل‌های اسکریپتی که اسکنرها کاوش می‌کنند (اگر وجود نداشته باشند ۴۰۴ سریع) */
    private const EXT_SCRIPT = 'php[0-9]?|pht(ml)?|phps|phar|cgi|pl|asp|aspx|jsp';
    /** فایل‌های حساس: حتی اگر وجود داشته باشند (debug.log، پشتیبان، ...) از بیرون نباید دیده شوند */
    private const EXT_SENS = 'env|git|svn|bak|old|sql|log|ini|conf|swp';
    private const EXT = self::EXT_SCRIPT . '|' . self::EXT_SENS;
    private const DOTS = 'git|svn|hg|env|aws|ssh|DS_Store';
    private const CORE = 'index\.php|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-comments-post\.php|wp-signup\.php|wp-activate\.php|wp-trackback\.php';

    private static function has_mark(string $head): bool {
        return strpos($head, self::MARK) !== false || strpos($head, self::MARK_OLD) !== false;
    }

    /* ───────────── مسیرها ───────────── */

    public static function mu_dir(): string {
        return rtrim(defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins', '/\\');
    }

    public static function mu_path(string $k): string {
        return self::mu_dir() . '/' . self::MU[$k];
    }

    public static function html_path(): string {
        return ABSPATH . '404.html';
    }

    public static function htaccess_path(): string {
        return ABSPATH . '.htaccess';
    }

    /** مسیر ریشه‌ی وردپرس نسبت به دامنه (مثلاً '' یا '/violet') */
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

    /* ───────────── mu-plugin ها ───────────── */

    private static function mu_header(string $title): string {
        return "<?php\n/**\n * Plugin Name: NIVA WP Guard — " . $title . "\n * Description: Generated automatically by NIVA WP Guard. Do not edit: change it in Settings → NIVA WP Guard → Server. It is removed together with the plugin.\n */\n\nif (!defined('ABSPATH')) {\n    return;\n}\n\n";
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

            return self::mu_header('Heartbeat control') . '$nivawpg_hb = ' . var_export($cfg, true) /* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- تولید کد PHP فایل mu */ . <<<'PHP'
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

// «کند»: فاصله‌ی Heartbeat بیشتر می‌شود
add_filter('heartbeat_settings', static function ($settings) use ($nivawpg_hb, $nivawpg_hb_where) {
    if (($nivawpg_hb[$nivawpg_hb_where()] ?? 'default') === 'interval') {
        $settings['interval'] = (int) $nivawpg_hb['interval'];
    }

    return $settings;
});

// «خاموش»: اسکریپت با یک نسخه‌ی خالی جایگزین می‌شود تا وابستگی بقیه‌ی اسکریپت‌ها نشکند
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
        $cfg = ['base' => self::base_path(), 'strict' => !empty($s['f404_strict']) ? 1 : 0];

        $code = self::mu_header('Fast 404') . '$nivawpg_f = ' . var_export($cfg, true) /* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- تولید کد PHP فایل mu */ . ';' . "\n" . "\$nivawpg_ext = '" . self::EXT . "';\n" . "\$nivawpg_dots = '" . self::DOTS . "';\n" . "\$nivawpg_core = '" . addslashes(self::CORE) . "';\n" . <<<'PHP'

if (!function_exists('nivawpg_fast404_send')) {
    /** صفحه‌ی ۴۰۴ ایستا را می‌فرستد و اجرا را تمام می‌کند (بدون قالب، CSS و JS) */
    function nivawpg_fast404_send()
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Robots-Tag: noindex, nofollow');
        }

        $file = ABSPATH . '404.html';

        if (is_readable($file)) {
            readfile($file);
        } else {
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>404</title></head><body style="font-family:Arial,sans-serif;text-align:center;padding:15vh 20px"><h1 style="font-size:72px;margin:0">404</h1><p>Not found</p></body></html>';
        }

        exit;
    }
}

// ۱) تشخیص زودهنگام آدرس‌های اسکنر: قبل از بارگذاری افزونه‌ها و قالب
if (PHP_SAPI !== 'cli' && !empty($_SERVER['REQUEST_URI'])) {
    $nivawpg_path = str_replace("\0", '', rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH)));
    $nivawpg_rel = ($nivawpg_f['base'] !== '' && strpos($nivawpg_path, $nivawpg_f['base'] . '/') === 0) ? substr($nivawpg_path, strlen($nivawpg_f['base'])) : $nivawpg_path;

    if (preg_match('~\.(?:' . $nivawpg_ext . ')$~i', $nivawpg_rel) || preg_match('~(^|/)\.(?:' . $nivawpg_dots . ')(/|$)~i', $nivawpg_rel)) {
        $nivawpg_exists = is_file(ABSPATH . ltrim($nivawpg_rel, '/'));
        $nivawpg_allowed = (bool) preg_match('~^/(?:' . $nivawpg_core . ')$|^/(?:wp-admin|wp-includes|wp-content/plugins|wp-content/themes)/~i', $nivawpg_rel);

        // فایلی که وجود ندارد = کاوش اسکنر؛ در حالت سخت‌گیرانه فایل‌های موجودِ بیرون از هسته/افزونه/قالب هم بسته می‌شوند
        if (!$nivawpg_exists || ($nivawpg_f['strict'] && !$nivawpg_allowed)) {
            nivawpg_fast404_send();
        }
    }

    unset($nivawpg_path, $nivawpg_rel, $nivawpg_exists, $nivawpg_allowed);
}

// ۲) هر ۴۰۴ دیگر وردپرس هم قبل از رندر قالب با همان صفحه‌ی سبک جواب داده می‌شود.
// عمداً «آخرین» اولویت: اول باید ریدایرکت اسلاگ قدیمی، حدس آدرس (redirect_guess_404_permalink)، ریدایرکت‌های سئو (Yoast/Rank Math/Redirection)،
// سایت‌مپ و لینک‌های کوتاه فرصت داشته باشند؛ فقط اگر هیچ‌کدام جواب نداد، صفحه‌ی ۴۰۴ ایستا فرستاده می‌شود.
add_action('template_redirect', static function () {
    if (is_404() && !is_robots() && !is_favicon()) {
        nivawpg_fast404_send();
    }
}, 9999);

PHP;

        return $code . self::shortlink_php();
    }

    /** بخشی از قانون‌های «۴۰۴ سریع»، نه یک قابلیت جدا: بدون آن ?p=ID برای محصول/برگه ۴۰۴ می‌شد و لینک‌های کوتاهِ به‌اشتراک‌گذاشته‌شده خراب می‌شدند */
    private static function shortlink_php(): string {
        return <<<'PHP'
if (!defined('NIVAWPG_SHORTLINK')) {
    define('NIVAWPG_SHORTLINK', 1);

    // ?p=ID برای همه‌ی انواع نوشته (محصول، برگه و ...) کار کند، نه فقط «نوشته»
    add_filter('request', static function ($vars) {
        if (isset($vars['p']) && !isset($vars['post_type'])) {
            $vars['post_type'] = 'any';
        }

        return $vars;
    });
}

PHP;
    }

    /** فایل mu را (بر اساس تنظیمات) می‌سازد، به‌روز می‌کند یا پاک می‌کند. */
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

    /* ───────────── صفحه‌ی 404.html ───────────── */

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
            'ph' => 'جستجو در سایت…', 'search' => 'جستجو', 'home' => 'بازگشت به صفحه‌ی اصلی', 'back' => 'صفحه‌ی قبل',
        ] : [
            'lang' => 'en', 'dir' => 'ltr',
            'font' => 'Arial, Helvetica, sans-serif',
            'title' => '404 — Page not found',
            'h1' => 'We could not find that page',
            'p' => 'The address may be mistyped, or the page may have been moved or removed. Try searching, or head back to the home page.',
            'ph' => 'Search the site…', 'search' => 'Search', 'home' => 'Back to home', 'back' => 'Go back',
        ];

        $css = <<<'CSS'
:root{--bg1:#0a1322;--bg2:#13294a;--fg:#eef3fb;--mut:#a8b6cb;--card:rgba(255,255,255,.07);--line:rgba(255,255,255,.15);--a:#2dd4bf;--b:#4f8cff;--field:rgba(255,255,255,.08)}
@media (prefers-color-scheme:light){:root{--bg1:#eef4fb;--bg2:#d8e7f8;--fg:#0e1a2c;--mut:#4d5f78;--card:rgba(255,255,255,.78);--line:rgba(14,26,44,.12);--a:#0d9488;--b:#2563eb;--field:#fff}}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{min-height:100vh;min-height:100dvh;display:grid;place-items:center;padding:24px;overflow-x:hidden;background:radial-gradient(900px 520px at 85% -10%,var(--bg2),transparent 62%),radial-gradient(700px 420px at 0% 110%,var(--bg2),transparent 60%),linear-gradient(160deg,var(--bg1),var(--bg1));color:var(--fg);font-family:__FONT__;line-height:1.75}
.orb{position:fixed;border-radius:50%;filter:blur(70px);opacity:.35;pointer-events:none;animation:float 14s ease-in-out infinite}
.o1{width:340px;height:340px;background:var(--a);top:-120px;inset-inline-start:-90px}
.o2{width:300px;height:300px;background:var(--b);bottom:-110px;inset-inline-end:-70px;animation-delay:-6s}
@keyframes float{0%,100%{transform:translate(0,0)}50%{transform:translate(18px,-22px)}}
.card{position:relative;z-index:1;width:min(560px,100%);padding:42px 34px 34px;text-align:center;border:1px solid var(--line);border-radius:28px;background:var(--card);-webkit-backdrop-filter:blur(16px);backdrop-filter:blur(16px);box-shadow:0 34px 90px -34px rgba(0,0,0,.6)}
.brand{display:inline-block;margin-bottom:10px;padding:4px 14px;border:1px solid var(--line);border-radius:999px;font-size:.82rem;color:var(--mut)}
.code{font-family:Arial,Helvetica,sans-serif;font-size:clamp(88px,24vw,152px);font-weight:800;line-height:1;letter-spacing:-3px;background:linear-gradient(120deg,var(--a),var(--b));-webkit-background-clip:text;background-clip:text;color:transparent;filter:drop-shadow(0 12px 28px rgba(79,140,255,.28))}
h1{margin:8px 0 10px;font-size:clamp(1.25rem,4.4vw,1.6rem);font-weight:700;line-height:1.5}
p{margin:0 auto 24px;max-width:44ch;color:var(--mut);font-size:1rem}
form{display:flex;gap:8px;margin:0 auto 20px;max-width:430px}
input[type=search]{flex:1;min-width:0;height:48px;padding:0 16px;border:1px solid var(--line);border-radius:14px;background:var(--field);color:var(--fg);font:inherit;outline:0;transition:border-color .15s,box-shadow .15s}
input[type=search]::placeholder{color:var(--mut)}
input[type=search]:focus{border-color:var(--b);box-shadow:0 0 0 4px rgba(79,140,255,.22)}
.btn{display:inline-flex;align-items:center;justify-content:center;height:48px;padding:0 22px;border:0;border-radius:14px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer;transition:transform .15s,box-shadow .15s,opacity .15s}
.btn:focus-visible{outline:3px solid var(--b);outline-offset:3px}
.go{background:linear-gradient(120deg,var(--a),var(--b));color:#06121f;box-shadow:0 12px 26px -12px var(--b)}
.go:hover{transform:translateY(-2px)}
.ghost{background:transparent;color:var(--fg);border:1px solid var(--line)}
.ghost:hover{background:var(--field)}
.row{display:flex;flex-wrap:wrap;gap:10px;justify-content:center}
@media (max-width:480px){.card{padding:34px 20px 26px}form{flex-direction:column}input[type=search]{flex:none;width:100%}.btn{width:100%}.row{flex-direction:column}}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
CSS;
        $css = str_replace('__FONT__', $t['font'], $css);
        $home_e = esc_attr($home);

        return '<!DOCTYPE html>' . "\n"
            . '<!-- ' . self::MARK . ' v1 — generated by NIVA WP Guard; delete this comment to keep your own edits -->' . "\n"
            . '<html lang="' . $t['lang'] . '" dir="' . $t['dir'] . '">' . "\n<head>\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="robots" content="noindex, nofollow">' . "\n"
            . '<meta name="color-scheme" content="dark light">' . "\n"
            . '<title>' . $t['title'] . "</title>\n<style>\n" . $css . "\n</style>\n</head>\n<body>\n"
            . '<div class="orb o1" aria-hidden="true"></div><div class="orb o2" aria-hidden="true"></div>' . "\n"
            . '<main class="card">' . "\n"
            . ($brand !== '' ? '  <span class="brand">' . $brand . "</span>\n" : '')
            . '  <div class="code" aria-hidden="true">404</div>' . "\n"
            . '  <h1>' . $t['h1'] . "</h1>\n"
            . '  <p>' . $t['p'] . "</p>\n"
            . '  <form action="' . $home_e . '" method="get" role="search"><input type="search" name="s" placeholder="' . esc_attr($t['ph']) . '" aria-label="' . esc_attr($t['search']) . '" autocomplete="off"><button class="btn go" type="submit">' . $t['search'] . "</button></form>\n"
            . '  <div class="row"><a class="btn ghost" href="' . $home_e . '">' . $t['home'] . '</a><button class="btn ghost" type="button" id="b" hidden>' . $t['back'] . "</button></div>\n"
            . "</main>\n"
            . '<script>var b=document.getElementById("b");if(b&&history.length>1){b.hidden=false;b.onclick=function(){history.back()}}</script>' . "\n"
            . "</body>\n</html>\n";
    }

    /** 404.html: فقط اگر نبود یا فایل خودِ افزونه بود (علامت دارد) نوشته می‌شود؛ فایل سفارشی کاربر دست‌نخورده می‌ماند. */
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
        $l = [self::BEGIN, '# Fast 404 for scanner probes. Generated automatically; change it from Settings → NIVA WP Guard → Server.', 'ErrorDocument 404 ' . $base . '/404.html', '', '<IfModule mod_rewrite.c>', 'RewriteEngine On', ''];
        $l[] = 'RewriteCond %{REQUEST_URI} \.(' . self::EXT_SENS . ')$ [NC]';
        $l[] = 'RewriteRule ^ - [R=404,L]';
        $l[] = '';
        $l[] = 'RewriteCond %{REQUEST_URI} \.(' . self::EXT_SCRIPT . ')$ [NC]';

        if (!empty($s['f404_strict'])) {
            $l[] = 'RewriteCond %{REQUEST_URI} !^' . $b . '/(' . self::CORE . '|wp-admin/|wp-includes/|wp-content/(plugins|themes)/) [NC]';
        } else {
            $l[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
        }

        $l[] = 'RewriteRule ^ - [R=404,L]';
        $l[] = 'RewriteRule (^|/)\.(' . self::DOTS . ')(/|$) - [NC,R=404,L]';
        $l[] = '</IfModule>';
        $l[] = self::END;

        return implode("\n", $l);
    }

    /** برای Nginx (که .htaccess ندارد): فایل‌های PHP جعلی را mu-plugin می‌گیرد */
    public static function nginx_snippet(): string {
        return "# NIVA WP Guard — Fast 404 (Nginx)\n# PHP probe files are handled by the mu-plugin; this covers the other probe types.\nerror_page 404 /404.html;\nlocation = /404.html { internal; }\nlocation ~* \\.(phar|cgi|pl|asp|aspx|jsp|env|bak|old|sql|log|ini|conf|swp)$ { return 404; }\nlocation ~ /\\.(git|svn|hg|env|aws|ssh|DS_Store) { return 404; }\n";
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
        // بلوک و یک خط خالیِ بعدش (همان که هنگام نصب اضافه شد) حذف می‌شود تا فایل دقیقاً به حالت قبل برگردد
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

        // بررسی سلامت: اگر سایت بعد از تغییر خطای سروری داد، فایل قبلی برمی‌گردد (خطای شبکه = نتیجه‌ی نامعلوم، برنمی‌گردد)
        $r = wp_remote_get(home_url('/'), ['timeout' => 8, 'redirection' => 0, 'sslverify' => false, 'headers' => ['Cache-Control' => 'no-cache']]);

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

    /* ───────────── کرون وردپرس (wp-config.php) ───────────── */

    /** فاصله‌ی اجرا به دقیقه (۶۰ به بالا = ساعتی) */
    public const CRON_EVERY = [1, 5, 10, 15, 30, 60, 120, 180, 240, 300, 360];

    /** عبارت زمان‌بندی cron برای فاصله‌ی داده‌شده (دقیقه) */
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

        // وردپرس اجازه می‌دهد wp-config.php یک پوشه بالاتر باشد
        if (!is_file($path) && is_file($up . '/wp-config.php') && !is_file($up . '/wp-settings.php')) {
            $path = $up . '/wp-config.php';
        }

        return $path;
    }

    /** بدون بلوک خودِ افزونه، آیا کاربر DISABLE_WP_CRON را جای دیگری تعریف کرده؟ */
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
     * DISABLE_WP_CRON را در wp-config.php می‌گذارد یا برمی‌دارد. نسخه‌ی پشتیبان ساخته نمی‌شود
     * (فایل پشتیبانِ wp-config از بیرون قابل دانلود است)؛ به‌جایش اگر سایت بعد از تغییر خطای سروری بدهد فایل قبلی برمی‌گردد.
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

        $r = wp_remote_get(home_url('/'), ['timeout' => 8, 'redirection' => 0, 'sslverify' => false, 'headers' => ['Cache-Control' => 'no-cache']]);

        if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) >= 500) {
            NIVAWPG_Fs::put($path, $cur);

            return 'rolled_back';
        }

        return $want ? 'ok' : 'removed';
    }

    /** وضعیت برای نمایش: ok | custom | missing | unwritable | stale | off */
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
     * دستور کرون سرور (cPanel / DirectAdmin) متناسب با نسخه‌ی PHP همین سایت.
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
     * ثبت آخرین اجرای کرون: فقط وقتی همین درخواست یک اجرای کرون باشد (wp-cron.php) فعال می‌شود.
     * اجرای موفق = بدون خطای مرگبار تا پایان درخواست؛ اجرای ناموفق جدا با پیام خطا ثبت می‌شود.
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

    /** دقیقه‌ی تأخیرِ قدیمی‌ترین کار زمان‌بندی‌شده؛ ۰ یعنی مشکلی نیست */
    public static function cron_overdue(int $every): int {
        $jobs = function_exists('_get_cron_array') ? _get_cron_array() : [];

        if (!is_array($jobs) || !$jobs) {
            return 0;
        }

        $late = time() - (int) min(array_keys($jobs));

        return $late > max(900, $every * 180) ? (int) floor($late / 60) : 0;
    }

    /* ───────────── اعمال و نگهداری ───────────── */

    /**
     * بعد از ذخیره‌ی تنظیمات (و هنگام فعال‌سازی/ترمیم): فایل‌های mu، 404.html و (در صورت درخواست) .htaccess هم‌سو می‌شوند.
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

    /** فایل mu ماژول لینک کوتاه که در نسخه‌های قبلی ساخته می‌شد */
    private static function drop_legacy_mu(): void {
        $path = self::mu_dir() . '/' . self::LEGACY_MU;

        if (is_file($path)) {
            wp_delete_file($path);
        }
    }

    private static function remove_html(): void {
        $path = self::html_path();

        if (is_file($path) && self::has_mark(NIVAWPG_Fs::head($path, 400))) {
            wp_delete_file($path);
        }
    }

    /** غیرفعال‌سازی افزونه: همه‌چیز پاک می‌شود */
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

    /** ترمیم بی‌صدا (هر ۱۲ ساعت در ادمین): فایل‌های گم‌شده را می‌سازد؛ .htaccess فقط اگر بلوک نبود */
    public static function heal(): void {
        $s = NIVAWPG_Settings::get();

        if (empty($s['files_ok'])) {
            return; // کاربر هنوز تنظیمات را ذخیره نکرده؛ فایلی ساخته نمی‌شود
        }

        self::apply($s, false);

        if (!empty($s['f404_on']) && self::htaccess_state($s) === 'missing') {
            self::install_htaccess($s);
        }
    }

    /** برای نمایش در تب: وضعیت هر بخش */
    public static function status(array $s): array {
        $mu = [];

        foreach (array_keys(self::MU) as $k) {
            $path = self::mu_path($k);
            $want = self::wanted($k, $s);

            if (!$want) {
                $mu[$k] = is_file($path) ? 'outdated' : 'off';
            } elseif (!is_file($path)) {
                $mu[$k] = NIVAWPG_Fs::writable(self::mu_dir()) || (!is_dir(self::mu_dir()) && NIVAWPG_Fs::writable(WP_CONTENT_DIR)) ? 'missing' : 'unwritable';
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
