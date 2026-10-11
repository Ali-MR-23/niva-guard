<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * فهرست اتصال‌های شناخته‌شده. هر مورد در تنظیمات یک تیک جدا دارد.
 *
 * type:  ad (تبلیغ) | news (اعلان/فید خبر) | license (لایسنس/تأیید) | update (بررسی نسخه) | service (کارکرد افزونه) | third (سرور شخص ثالث)
 * cache: true = پاسخ موفق این آدرس را می‌شود به‌جای مسدود کردن، کش کرد (لایسنس را قطع نمی‌کند).
 */
class NIVAWPG_Rules {

    /**
     * قانون ⇒ تکه‌هایی از نام پوشه‌ی افزونه یا قالب (حروف کوچک). اگر یکی از افزونه‌ها/قالب‌های فعال شامل یکی از آنها باشد، قانون «مرتبط» است.
     * '*' = همیشه (مثل بررسی نسخه‌ی خود وردپرس). قانونی که اینجا نیست همیشه مرتبط است.
     */
    private const FOR = [
        'pwsms_notice' => ['persian-woocommerce-sms', 'pwsms'], 'pwsms_hash' => ['persian-woocommerce-sms', 'pwsms'],
        'pw_feed' => ['persian-woocommerce'],
        'digits_ads' => ['digits'], 'rtl_ads' => ['rtl'],
        'mweb_license' => ['milano', 'mahdis'], 'mweb_auth' => ['milano', 'mahdis'], 'mweb_feed' => ['milano', 'mahdis'], 'mweb_notifier' => ['milano', 'mahdis'],
        'bwdk_notice' => ['digikala', 'bwdk', 'buy-with'], 'bwdk_meta' => ['digikala', 'bwdk', 'buy-with'], 'bwdk_orders' => ['digikala', 'bwdk', 'buy-with'],
        'wc_promo' => ['woocommerce'], 'wc_wcpay' => ['woocommerce'], 'wc_tracking' => ['woocommerce'], 'wc_paypal' => ['woocommerce'],
        'wc_productkey' => ['woocommerce'], 'wc_readme' => ['woocommerce'], 'wc_update' => ['woocommerce'],
        'wc_wporg_core' => ['*'], 'wc_wporg_theme' => ['*'],
        'jp_wpcom' => ['jetpack', 'woocommerce'], 'jp_xmlrpc' => ['jetpack', 'woocommerce'], 'jp_com' => ['jetpack', 'woocommerce'],
        'jp_stats' => ['jetpack'], 'jp_pixel' => ['jetpack'],
        'lscwp_news' => ['litespeed'], 'lscwp_ver' => ['litespeed'], 'lscwp_dash' => ['litespeed'],
        'solid_news' => ['better-wp-security', 'solid-security', 'ithemes-security'], 'yoast_ips' => ['better-wp-security', 'solid-security', 'ithemes-security'],
        'rankmath_news' => ['rank-math', 'seo-by-rank-math'],
        'el_assets' => ['elementor'], 'el_remote' => ['elementor'],
        'yoast_trans_free' => ['wordpress-seo'], 'yoast_trans_prem' => ['wordpress-seo'], 'yoast_trans_woo' => ['wpseo-woocommerce', 'yoast-woo'],
        'yoast_trans' => ['wordpress-seo', 'wpseo'], 'yoast_third' => ['wordpress-seo'], 'yoast_indexnow' => ['wordpress-seo'], 'yoast_site' => ['wordpress-seo'],
        'wincher_ads' => ['wordpress-seo'], 'wincher_all' => ['wordpress-seo'],
        'wpforms_addons' => ['wpforms'], 'smtp_notice' => ['wp-mail-smtp'],
        'parsi_sponsors' => ['parsidate'], 'parsi_feed' => ['parsidate'],
        'ace_ads' => ['advanced-shipping'], 'codesnip_ad' => ['code-snippets'],
        'emalls' => ['emalls'], 'nv_license' => ['niva'], 'sms_payamak' => ['payamak'], 'torob' => ['torob'],
    ];

    /** @var string[]|null */
    private static ?array $active = null;

    /** @return string[] نام پوشه‌ی افزونه‌ها (عادی، شبکه‌ای، mu) و قالب‌های فعال، حروف کوچک */
    public static function active_slugs(): array {
        if (self::$active !== null) {
            return self::$active;
        }

        $files = (array) get_option('active_plugins', []);

        if (is_multisite()) {
            $files = array_merge($files, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }

        foreach ((array) glob(WPMU_PLUGIN_DIR . '/*.php') as $mu) {
            $files[] = basename($mu);
        }

        $slugs = [];

        foreach ($files as $file) {
            $file = (string) $file;
            $slugs[] = strtolower(strpos($file, '/') !== false ? dirname($file) : basename($file, '.php'));
        }

        $slugs[] = strtolower((string) get_template());
        $slugs[] = strtolower((string) get_stylesheet());

        return self::$active = array_values(array_unique(array_filter($slugs)));
    }

    /** آیا افزونه/قالبِ این قانون روی همین سایت فعال است؟ */
    public static function applies(string $id): bool {
        if (!isset(self::FOR[$id])) {
            return true;
        }

        foreach (self::FOR[$id] as $token) {
            if ($token === '*') {
                return true;
            }

            foreach (self::active_slugs() as $slug) {
                if (strpos($slug, $token) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /** متن قانون‌ها (فقط در صفحه‌ی ادمین، بعد از init): id ⇒ [گروه، توضیح] */
    public static function texts(): array {
        return [
            'pwsms_notice' => [__('Persian WooCommerce SMS', 'niva-wp-guard'), __('Notices and ads (pwsms.json / pwsms.hash)', 'niva-wp-guard')],
            'pwsms_hash' => [__('Persian WooCommerce SMS', 'niva-wp-guard'), __('Notice signature', 'niva-wp-guard')],
            'pw_feed' => [__('Persian WooCommerce', 'niva-wp-guard'), __('News feed on the dashboard', 'niva-wp-guard')],
            'digits_ads' => [__('Digits (Mersa Web)', 'niva-wp-guard'), __('Advertising panel (ads-api)', 'niva-wp-guard')],
            'rtl_ads' => [__('RTL (RTL-CareUnit)', 'niva-wp-guard'), __('Notices and ads', 'niva-wp-guard')],
            'mweb_license' => [__('Mahdis Web theme', 'niva-wp-guard'), __('License verification (every few seconds)', 'niva-wp-guard')],
            'mweb_auth' => [__('Mahdis Web theme', 'niva-wp-guard'), __('Product authorization', 'niva-wp-guard')],
            'mweb_feed' => [__('Mahdis Web theme', 'niva-wp-guard'), __('News feed', 'niva-wp-guard')],
            'mweb_notifier' => [__('Mahdis Web theme', 'niva-wp-guard'), __('Version notice (notifier.xml)', 'niva-wp-guard')],
            'bwdk_notice' => [__('Buy with Digikala', 'niva-wp-guard'), __('Notices (notifications.json)', 'niva-wp-guard')],
            'bwdk_meta' => [__('Buy with Digikala', 'niva-wp-guard'), __('Version check (metadata.json)', 'niva-wp-guard')],
            'bwdk_orders' => [__('Buy with Digikala', 'niva-wp-guard'), __('Order registration and tracking (plugin function)', 'niva-wp-guard')],
            'wc_promo' => [__('WooCommerce', 'niva-wp-guard'), __('Promotional offers and suggested extensions (promotions / obw-free-extensions)', 'niva-wp-guard')],
            'jp_wpcom' => [__('Jetpack / WordPress.com', 'niva-wp-guard'), __('Connection to WordPress.com (public-api): account check, suggestions, payments', 'niva-wp-guard')],
            'jp_xmlrpc' => [__('Jetpack / WordPress.com', 'niva-wp-guard'), __('Jetpack server communication (jetpack.wordpress.com)', 'niva-wp-guard')],
            'jp_com' => [__('Jetpack / WordPress.com', 'niva-wp-guard'), __('The jetpack.com site — product recommendations and ads', 'niva-wp-guard')],
            'jp_stats' => [__('Jetpack / WordPress.com', 'niva-wp-guard'), __('Visit statistics (stats.wp.com)', 'niva-wp-guard')],
            'jp_pixel' => [__('Jetpack / WordPress.com', 'niva-wp-guard'), __('Tracking pixel (pixel.wp.com)', 'niva-wp-guard')],
            'wc_wcpay' => [__('WooCommerce', 'niva-wp-guard'), __('WooPayments ads (incentives) — the “Set up WooPayments” banner; unrelated to your own payment gateways', 'niva-wp-guard')],
            'wc_tracking' => [__('WooCommerce', 'niva-wp-guard'), __('Sending usage statistics to WooCommerce (usage tracking), about half a second', 'niva-wp-guard')],
            'wc_paypal' => [__('WooCommerce', 'niva-wp-guard'), __('PayPal (webscr): IPN validation and connecting to PayPal — can wait up to 15 seconds from servers that cannot reach PayPal', 'niva-wp-guard')],
            'wc_productkey' => [__('WooCommerce', 'niva-wp-guard'), __('Product key check for WooCommerce.com products (product-key-api)', 'niva-wp-guard')],
            'wc_wporg_core' => [__('WordPress — version and plugin updates', 'niva-wp-guard'), __('WordPress version check from api.wordpress.org (“System status” page; up to 10 seconds on slow hosts)', 'niva-wp-guard')],
            'wc_wporg_theme' => [__('WordPress — version and plugin updates', 'niva-wp-guard'), __('Theme information from api.wordpress.org (“System status” page)', 'niva-wp-guard')],
            'lscwp_news' => [__('LiteSpeed Cache', 'niva-wp-guard'), __('QUIC.cloud news and notices (wpapi.quic.cloud/news)', 'niva-wp-guard')],
            'lscwp_ver' => [__('LiteSpeed Cache', 'niva-wp-guard'), __('QUIC.cloud version check (ver_check)', 'niva-wp-guard')],
            'lscwp_dash' => [__('LiteSpeed Cache', 'niva-wp-guard'), __('QUIC.cloud dashboard (api.quic.cloud/d/dash): statistics and cloud service suggestions', 'niva-wp-guard')],
            'solid_news' => [__('Better WP Security (Solid Security)', 'niva-wp-guard'), __('solidwp.com news and promotional posts (dashboard widget and notice), up to 10 seconds', 'niva-wp-guard')],
            'rankmath_news' => [__('Rank Math SEO', 'niva-wp-guard'), __('rankmath.com blog news and posts (news widget and dashboard ad)', 'niva-wp-guard')],
            'wc_readme' => [__('WooCommerce', 'niva-wp-guard'), __('Reading the readme from the WordPress svn (up to 10 seconds)', 'niva-wp-guard')],
            'wc_update' => [__('WooCommerce', 'niva-wp-guard'), __('Plugin version check', 'niva-wp-guard')],
            'el_assets' => [__('Elementor', 'niva-wp-guard'), __('Editor ads and notices: upgrade to Pro (free-to-pro-upsell), notifications and promotions — when the editor opens', 'niva-wp-guard')],
            'el_remote' => [__('Elementor', 'niva-wp-guard'), __('my.elementor.com connections (news, library, suggested plugins)', 'niva-wp-guard')],
            'yoast_trans_free' => [__('Yoast', 'niva-wp-guard'), __('Yoast SEO (free) translation packages (translationspress)', 'niva-wp-guard')],
            'yoast_trans_prem' => [__('Yoast', 'niva-wp-guard'), __('Yoast SEO Premium translation packages (translationspress)', 'niva-wp-guard')],
            'yoast_trans_woo' => [__('Yoast', 'niva-wp-guard'), __('Yoast WooCommerce SEO translation packages (translationspress)', 'niva-wp-guard')],
            'yoast_trans' => [__('Yoast', 'niva-wp-guard'), __('All Yoast translation packages in one (including News, Local, Video and others)', 'niva-wp-guard')],
            'yoast_third' => [__('Yoast (unofficial server)', 'niva-wp-guard'), __('vip-themes.com — a third-party server, not Yoast', 'niva-wp-guard')],
            'yoast_ips' => [__('Better WP Security (Solid Security)', 'niva-wp-guard'), __('Visitor IP check (ipcheck) — also runs on site pages and can wait up to 10 seconds', 'niva-wp-guard')],
            'wpforms_addons' => [__('WPForms', 'niva-wp-guard'), __('Add-ons list (addons) — up to 10 seconds', 'niva-wp-guard')],
            'smtp_notice' => [__('WP Mail SMTP', 'niva-wp-guard'), __('Notices (feeds/notifications)', 'niva-wp-guard')],
            'parsi_sponsors' => [__('ParsiDate', 'niva-wp-guard'), __('Sponsors list (sponsors.json from GitHub)', 'niva-wp-guard')],
            'parsi_feed' => [__('ParsiDate', 'niva-wp-guard'), __('News feed (wp-parsi.com)', 'niva-wp-guard')],
            'ace_ads' => [__('Advanced Shipping (Ace Plugins)', 'niva-wp-guard'), __('Advertising page aceplugins.com', 'niva-wp-guard')],
            'yoast_indexnow' => [__('Yoast', 'niva-wp-guard'), __('IndexNow — notifying search engines about page changes (up to 10 seconds)', 'niva-wp-guard')],
            'yoast_site' => [__('Yoast', 'niva-wp-guard'), __('my.yoast.com — site information', 'niva-wp-guard')],
            'wincher_ads' => [__('Yoast ← Wincher', 'niva-wp-guard'), __('Upgrade campaign (upgrade-campaign) — advertising; up to 10 seconds', 'niva-wp-guard')],
            'wincher_all' => [__('Yoast ← Wincher', 'niva-wp-guard'), __('The entire Wincher connection (keyword tracking: account and connect/token) — up to 10 seconds; block it if you do not use Wincher', 'niva-wp-guard')],
            'codesnip_ad' => [__('Code Snippets', 'niva-wp-guard'), __('Welcome page and ad (cs_welcome.json)', 'niva-wp-guard')],
            'emalls' => [__('Emalls', 'niva-wp-guard'), __('Emalls product extraction service (plugin function)', 'niva-wp-guard')],
            'nv_license' => [__('Niva plugins', 'niva-wp-guard'), __('Niva license server (validate)', 'niva-wp-guard')],
            'sms_payamak' => [__('SMS', 'niva-wp-guard'), __('payamak-panel SMS gateway', 'niva-wp-guard')],
            'torob' => [__('Torob', 'niva-wp-guard'), __('Price update webhook (Torob)', 'niva-wp-guard')],
        ];
    }

    public static function all(): array {
        return [
            // ── افزونه‌ی پیامک ووکامرس فارسی و ووکامرس فارسی
            ['id' => 'pwsms_notice', 'match' => 'woonotice.ir/', 'type' => 'ad'],
            ['id' => 'pwsms_hash', 'match' => 'woohash.ir/', 'type' => 'ad'],
            ['id' => 'pw_feed', 'match' => 'woocommerce.ir/feed', 'type' => 'news'],

            // ── Digits و راست‌چین
            ['id' => 'digits_ads', 'match' => 'rtl-plugin.ir/ads-api/', 'type' => 'ad'],
            ['id' => 'rtl_ads', 'match' => 'files.rtl-theme.com/', 'type' => 'ad'],

            // ── قالب مهدیس وب
            ['id' => 'mweb_license', 'match' => 'mahdisweb.net/wp-json/verification/', 'type' => 'license', 'cache' => true],
            ['id' => 'mweb_auth', 'match' => 'mahdisweb.net/wp-json/authorization/', 'type' => 'license', 'cache' => true],
            ['id' => 'mweb_feed', 'match' => 'mahdisweb.net/feed', 'type' => 'news'],
            ['id' => 'mweb_notifier', 'match' => 'mahdisweb.net//themes/', 'type' => 'news'],

            // ── خرید با دیجی‌کالا
            ['id' => 'bwdk_notice', 'match' => 'plugins.digifyprime.net/bwdk/notifications.json', 'type' => 'news'],
            ['id' => 'bwdk_meta', 'match' => 'plugins.digifyprime.net/bwdk/metadata.json', 'type' => 'update'],
            ['id' => 'bwdk_orders', 'match' => 'bwdk-backend.digify.shop/', 'type' => 'service'],

            // ── ووکامرس و المنتور
            ['id' => 'wc_promo', 'match' => 'woocommerce.com/wp-json/wccom', 'type' => 'ad'],
            // ── Jetpack و اتصال WordPress.com (در ایران معمولاً بی‌استفاده؛ ووکامرس هم بسته‌ی آن را دارد)
            ['id' => 'jp_wpcom', 'match' => 'public-api.wordpress.com/', 'type' => 'service'],
            ['id' => 'jp_xmlrpc', 'match' => 'jetpack.wordpress.com/', 'type' => 'service'],
            ['id' => 'jp_com', 'match' => 'jetpack.com/', 'type' => 'ad'],
            ['id' => 'jp_stats', 'match' => 'stats.wp.com/', 'type' => 'service'],
            ['id' => 'jp_pixel', 'match' => 'pixel.wp.com/', 'type' => 'service'],
            ['id' => 'wc_wcpay', 'match' => 'public-api.wordpress.com/wpcom/v2/wcpay/', 'type' => 'ad'],
            ['id' => 'wc_tracking', 'match' => 'tracking.woocommerce.com/', 'type' => 'service'],
            ['id' => 'wc_paypal', 'match' => 'www.paypal.com/cgi-bin/webscr', 'type' => 'service'],
            ['id' => 'wc_productkey', 'match' => 'woocommerce.com/wc-api/product-key-api', 'type' => 'license', 'cache' => true],
            ['id' => 'wc_wporg_core', 'match' => 'api.wordpress.org/core/version-check/', 'type' => 'update', 'cache' => true],
            ['id' => 'wc_wporg_theme', 'match' => 'api.wordpress.org/themes/info/', 'type' => 'update', 'cache' => true],
            ['id' => 'lscwp_news', 'match' => 'wpapi.quic.cloud/news', 'type' => 'news'],
            ['id' => 'lscwp_ver', 'match' => 'wpapi.quic.cloud/ver_check', 'type' => 'update', 'cache' => true],
            ['id' => 'lscwp_dash', 'match' => 'api.quic.cloud/', 'type' => 'service'],
            ['id' => 'solid_news', 'match' => 'solidwp.com/wp-json/wp/v2/posts', 'type' => 'news'],
            ['id' => 'rankmath_news', 'match' => 'rankmath.com/wp-json/wp/v2/posts', 'type' => 'news'],
            ['id' => 'wc_readme', 'match' => 'plugins.svn.wordpress.org/woocommerce/', 'type' => 'update'],
            ['id' => 'wc_update', 'match' => 'woocommerce.com/wp-json/helper/', 'type' => 'update'],
            ['id' => 'el_assets', 'match' => 'assets.elementor.com/', 'type' => 'ad'],
            ['id' => 'el_remote', 'match' => 'my.elementor.com/', 'type' => 'ad'],

            // ── Yoast
            ['id' => 'yoast_trans_free', 'match' => 'packages.translationspress.com/yoast/wordpress-seo/', 'type' => 'update'],
            ['id' => 'yoast_trans_prem', 'match' => 'packages.translationspress.com/yoast/wordpress-seo-premium/', 'type' => 'update'],
            ['id' => 'yoast_trans_woo', 'match' => 'packages.translationspress.com/yoast/yoast-woo-seo/', 'type' => 'update'],
            ['id' => 'yoast_trans', 'match' => 'packages.translationspress.com/', 'type' => 'update'],
            ['id' => 'yoast_third', 'match' => 'vip-themes.com/', 'type' => 'third'],
            ['id' => 'yoast_ips', 'match' => 'ipcheck-api.ithemes.com/', 'type' => 'service', 'cache' => true],

            // ── WPForms، WP Mail SMTP، پارسی‌دیت، Advanced Shipping، IndexNow (لاگ ویولت)
            ['id' => 'wpforms_addons', 'match' => 'wpformsapi.com/feeds/', 'type' => 'ad'],
            ['id' => 'smtp_notice', 'match' => 'wpmailsmtpapi.com/feeds/', 'type' => 'news'],
            ['id' => 'parsi_sponsors', 'match' => 'raw.githubusercontent.com/wordpress-parsi/', 'type' => 'ad'],
            ['id' => 'parsi_feed', 'match' => 'wp-parsi.com/', 'type' => 'news'],
            ['id' => 'ace_ads', 'match' => 'aceplugins.com/', 'type' => 'ad'],
            ['id' => 'yoast_indexnow', 'match' => 'api.indexnow.org/', 'type' => 'service'],
            ['id' => 'yoast_site', 'match' => 'my.yoast.com/', 'type' => 'license'],

            // ── لاگ سایت سوم: Wincher (داخل Yoast)، Code Snippets، Emalls
            ['id' => 'wincher_ads', 'match' => 'api.wincher.com/v1/yoast/upgrade-campaign', 'type' => 'ad'],
            ['id' => 'wincher_all', 'match' => 'wincher.com/', 'type' => 'service'],
            ['id' => 'codesnip_ad', 'match' => 'codesnippets.pro/', 'type' => 'ad'],
            ['id' => 'emalls', 'match' => 'emalls.ir/', 'type' => 'service'],

            // ── سرویس‌های کارکردی (برای اطلاع؛ پیش‌فرض باز می‌مانند)
            ['id' => 'nv_license', 'match' => 'license.nivahost.com/', 'type' => 'license', 'cache' => true],
            ['id' => 'sms_payamak', 'match' => 'rest.payamak-panel.com/', 'type' => 'service'],
            ['id' => 'torob', 'match' => 'api.torob.com/', 'type' => 'service'],
        ];
    }

    public static function types(): array {
        return [
            'ad'      => [__('Ad', 'niva-wp-guard'), '#c0282a', true],
            'news'    => [__('Notice / news', 'niva-wp-guard'), '#d98a00', true],
            'license' => [__('License', 'niva-wp-guard'), '#6d3fa8', false],
            'update'  => [__('Version check', 'niva-wp-guard'), '#168591', false],
            'service' => [__('Plugin function', 'niva-wp-guard'), '#0b8a3e', false],
            'third'   => [__('Third party', 'niva-wp-guard'), '#555', false],
        ];
    }

    /** آیا آدرس host+path با این پیشوند شروع می‌شود؟ (برای Yoast: شامل زیردامنه‌ها هم می‌شود) */
    public static function matches(string $needle, string $hostpath): bool {
        // یکسان‌سازی: حروف کوچک، حذف www.، ادغام اسلش‌های پشت‌سرهم، و «دامنه‌ی بدون مسیر» = «دامنه/»
        $norm = static function (string $s): string {
            $s = strtolower(trim($s));
            $s = (string) preg_replace('#/{2,}#', '/', $s);
            $s = (string) preg_replace('#^www\.#', '', $s);

            return strpos($s, '/') === false ? $s . '/' : $s;
        };
        $needle = $norm($needle);
        $hostpath = $norm($hostpath);
        if (strpos($hostpath, $needle) === 0) {
            return true;
        }

        // دامنه‌ی بدون مسیر (مثل vip-themes.com/) زیردامنه‌ها را هم بگیرد: cdn.vip-themes.com/...
        if (substr($needle, -1) === '/' && substr_count($needle, '/') === 1) {
            $host = rtrim($needle, '/');

            return (bool) preg_match('#^([a-z0-9-]+\.)*' . preg_quote($host, '#') . '/#i', $hostpath);
        }

        return false;
    }

    /** حدس نوع یک اتصال کشف‌شده از روی آدرس */
    public static function guess_type(string $hostpath): string {
        if (preg_match('/licen|verif|validat|activat|authoriz|token|serial/i', $hostpath)) {
            return 'license';
        }

        if (preg_match('/sms|otp|payamak|kavenegar|pay(ment)?\b|gateway|order/i', $hostpath)) {
            return 'service';
        }

        if (preg_match('/update|metadata|version|packages|readme|check/i', $hostpath)) {
            return 'update';
        }

        if (preg_match('/feed|news|notif|notice|promo|\bads?\b|banner|campaign|rss/i', $hostpath)) {
            return 'news';
        }

        return 'service';
    }
}
