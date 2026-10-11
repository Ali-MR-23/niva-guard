<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registry of known connections. Every item has its own switch in the settings.
 *
 * type:  ad | news (notice/feed) | license | update (version check) | service (plugin function) | third (third party)
 * cache: true = a successful response can be cached instead of blocking the address (the license is not cut off).
 */
class NIVAWPG_Rules {

    /** Address prefix of the WP-ParsiDate sponsor feed (matched only to block it). */
    public const PARSI_RAW = 'raw.' . 'githubusercontent.com/wordpress-parsi/';

    /**
     * Rule => fragments of a plugin or theme folder name (lower case). When an active plugin or theme contains one of them, the rule is "relevant".
     * '*' = always (like WordPress's own version check). A rule that is not listed here is always relevant.
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

    /** @return string[] Folder names of the active plugins (normal, network, mu) and themes, lower case. */
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

    /** Is the plugin or theme of this rule active on this site? */
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

    /** Rule texts (admin page only, after init): id => [group, description]. */
    public static function texts(): array {
        return [
            'pwsms_notice' => [__('Persian WooCommerce SMS', 'niva-guard'), __('Notices and ads (pwsms.json / pwsms.hash)', 'niva-guard')],
            'pwsms_hash' => [__('Persian WooCommerce SMS', 'niva-guard'), __('Notice signature', 'niva-guard')],
            'pw_feed' => [__('Persian WooCommerce', 'niva-guard'), __('News feed on the dashboard', 'niva-guard')],
            'digits_ads' => [__('Digits (Mersa Web)', 'niva-guard'), __('Advertising panel (ads-api)', 'niva-guard')],
            'rtl_ads' => [__('RTL (RTL-CareUnit)', 'niva-guard'), __('Notices and ads', 'niva-guard')],
            'mweb_license' => [__('Mahdis Web theme', 'niva-guard'), __('License verification (every few seconds)', 'niva-guard')],
            'mweb_auth' => [__('Mahdis Web theme', 'niva-guard'), __('Product authorization', 'niva-guard')],
            'mweb_feed' => [__('Mahdis Web theme', 'niva-guard'), __('News feed', 'niva-guard')],
            'mweb_notifier' => [__('Mahdis Web theme', 'niva-guard'), __('Version notice (notifier.xml)', 'niva-guard')],
            'bwdk_notice' => [__('Buy with Digikala', 'niva-guard'), __('Notices (notifications.json)', 'niva-guard')],
            'bwdk_meta' => [__('Buy with Digikala', 'niva-guard'), __('Version check (metadata.json)', 'niva-guard')],
            'bwdk_orders' => [__('Buy with Digikala', 'niva-guard'), __('Order registration and tracking (plugin function)', 'niva-guard')],
            'wc_promo' => [__('WooCommerce', 'niva-guard'), __('Promotional offers and suggested extensions (promotions / obw-free-extensions)', 'niva-guard')],
            'jp_wpcom' => [__('Jetpack / WordPress.com', 'niva-guard'), __('Connection to WordPress.com (public-api): account check, suggestions, payments', 'niva-guard')],
            'jp_xmlrpc' => [__('Jetpack / WordPress.com', 'niva-guard'), __('Jetpack server communication (jetpack.wordpress.com)', 'niva-guard')],
            'jp_com' => [__('Jetpack / WordPress.com', 'niva-guard'), __('The jetpack.com site — product recommendations and ads', 'niva-guard')],
            'jp_stats' => [__('Jetpack / WordPress.com', 'niva-guard'), __('Visit statistics (stats.wp.com)', 'niva-guard')],
            'jp_pixel' => [__('Jetpack / WordPress.com', 'niva-guard'), __('Tracking pixel (pixel.wp.com)', 'niva-guard')],
            'wc_wcpay' => [__('WooCommerce', 'niva-guard'), __('WooPayments ads (incentives) — the “Set up WooPayments” banner; unrelated to your own payment gateways', 'niva-guard')],
            'wc_tracking' => [__('WooCommerce', 'niva-guard'), __('Sending usage statistics to WooCommerce (usage tracking), about half a second', 'niva-guard')],
            'wc_paypal' => [__('WooCommerce', 'niva-guard'), __('PayPal (webscr): IPN validation and connecting to PayPal — can wait up to 15 seconds from servers that cannot reach PayPal', 'niva-guard')],
            'wc_productkey' => [__('WooCommerce', 'niva-guard'), __('Product key check for WooCommerce.com products (product-key-api)', 'niva-guard')],
            'wc_wporg_core' => [__('WordPress — version and plugin updates', 'niva-guard'), __('WordPress version check from api.wordpress.org (“System status” page; up to 10 seconds on slow hosts)', 'niva-guard')],
            'wc_wporg_theme' => [__('WordPress — version and plugin updates', 'niva-guard'), __('Theme information from api.wordpress.org (“System status” page)', 'niva-guard')],
            'lscwp_news' => [__('LiteSpeed Cache', 'niva-guard'), __('QUIC.cloud news and notices (wpapi.quic.cloud/news)', 'niva-guard')],
            'lscwp_ver' => [__('LiteSpeed Cache', 'niva-guard'), __('QUIC.cloud version check (ver_check)', 'niva-guard')],
            'lscwp_dash' => [__('LiteSpeed Cache', 'niva-guard'), __('QUIC.cloud dashboard (api.quic.cloud/d/dash): statistics and cloud service suggestions', 'niva-guard')],
            'solid_news' => [__('Better WP Security (Solid Security)', 'niva-guard'), __('solidwp.com news and promotional posts (dashboard widget and notice), up to 10 seconds', 'niva-guard')],
            'rankmath_news' => [__('Rank Math SEO', 'niva-guard'), __('rankmath.com blog news and posts (news widget and dashboard ad)', 'niva-guard')],
            'wc_readme' => [__('WooCommerce', 'niva-guard'), __('Reading the readme from the WordPress svn (up to 10 seconds)', 'niva-guard')],
            'wc_update' => [__('WooCommerce', 'niva-guard'), __('Plugin version check', 'niva-guard')],
            'el_assets' => [__('Elementor', 'niva-guard'), __('Editor ads and notices: upgrade to Pro (free-to-pro-upsell), notifications and promotions — when the editor opens', 'niva-guard')],
            'el_remote' => [__('Elementor', 'niva-guard'), __('my.elementor.com connections (news, library, suggested plugins)', 'niva-guard')],
            'yoast_trans_free' => [__('Yoast', 'niva-guard'), __('Yoast SEO (free) translation packages (translationspress)', 'niva-guard')],
            'yoast_trans_prem' => [__('Yoast', 'niva-guard'), __('Yoast SEO Premium translation packages (translationspress)', 'niva-guard')],
            'yoast_trans_woo' => [__('Yoast', 'niva-guard'), __('Yoast WooCommerce SEO translation packages (translationspress)', 'niva-guard')],
            'yoast_trans' => [__('Yoast', 'niva-guard'), __('All Yoast translation packages in one (including News, Local, Video and others)', 'niva-guard')],
            'yoast_third' => [__('VIP Theme', 'niva-guard'), __('vip-themes.com — a third-party server', 'niva-guard')],
            'yoast_ips' => [__('Better WP Security (Solid Security)', 'niva-guard'), __('Visitor IP check (ipcheck) — also runs on site pages and can wait up to 10 seconds', 'niva-guard')],
            'wpforms_addons' => [__('WPForms', 'niva-guard'), __('Add-ons list (addons) — up to 10 seconds', 'niva-guard')],
            'smtp_notice' => [__('WP Mail SMTP', 'niva-guard'), __('Notices (feeds/notifications)', 'niva-guard')],
            'parsi_sponsors' => [__('ParsiDate', 'niva-guard'), __('Sponsors list (sponsors.json from GitHub)', 'niva-guard')],
            'parsi_feed' => [__('ParsiDate', 'niva-guard'), __('News feed (wp-parsi.com)', 'niva-guard')],
            'ace_ads' => [__('Advanced Shipping (Ace Plugins)', 'niva-guard'), __('Advertising page aceplugins.com', 'niva-guard')],
            'yoast_indexnow' => [__('Yoast', 'niva-guard'), __('IndexNow — notifying search engines about page changes (up to 10 seconds)', 'niva-guard')],
            'yoast_site' => [__('Yoast', 'niva-guard'), __('my.yoast.com — site information', 'niva-guard')],
            'wincher_ads' => [__('Yoast ← Wincher', 'niva-guard'), __('Upgrade campaign (upgrade-campaign) — advertising; up to 10 seconds', 'niva-guard')],
            'wincher_all' => [__('Yoast ← Wincher', 'niva-guard'), __('The entire Wincher connection (keyword tracking: account and connect/token) — up to 10 seconds; block it if you do not use Wincher', 'niva-guard')],
            'codesnip_ad' => [__('Code Snippets', 'niva-guard'), __('Welcome page and ad (cs_welcome.json)', 'niva-guard')],
            'emalls' => [__('Emalls', 'niva-guard'), __('Emalls product extraction service (plugin function)', 'niva-guard')],
            'nv_license' => [__('Niva plugins', 'niva-guard'), __('Niva license server (validate)', 'niva-guard')],
            'sms_payamak' => [__('SMS', 'niva-guard'), __('payamak-panel SMS gateway', 'niva-guard')],
            'torob' => [__('Torob', 'niva-guard'), __('Price update webhook (Torob)', 'niva-guard')],
        ];
    }

    public static function all(): array {
        return [
            // ── Persian WooCommerce SMS and Persian WooCommerce
            ['id' => 'pwsms_notice', 'match' => 'woonotice.ir/', 'type' => 'ad'],
            ['id' => 'pwsms_hash', 'match' => 'woohash.ir/', 'type' => 'ad'],
            ['id' => 'pw_feed', 'match' => 'woocommerce.ir/feed', 'type' => 'news'],

            // ── Digits and RTL plugins
            ['id' => 'digits_ads', 'match' => 'rtl-plugin.ir/ads-api/', 'type' => 'ad'],
            ['id' => 'rtl_ads', 'match' => 'files.rtl-theme.com/', 'type' => 'ad'],

            // ── Mahdis Web theme
            ['id' => 'mweb_license', 'match' => 'mahdisweb.net/wp-json/verification/', 'type' => 'license', 'cache' => true],
            ['id' => 'mweb_auth', 'match' => 'mahdisweb.net/wp-json/authorization/', 'type' => 'license', 'cache' => true],
            ['id' => 'mweb_feed', 'match' => 'mahdisweb.net/feed', 'type' => 'news'],
            ['id' => 'mweb_notifier', 'match' => 'mahdisweb.net//themes/', 'type' => 'news'],

            // ── Digikala checkout plugin
            ['id' => 'bwdk_notice', 'match' => 'plugins.digifyprime.net/bwdk/notifications.json', 'type' => 'news'],
            ['id' => 'bwdk_meta', 'match' => 'plugins.digifyprime.net/bwdk/metadata.json', 'type' => 'update'],
            ['id' => 'bwdk_orders', 'match' => 'bwdk-backend.digify.shop/', 'type' => 'service'],

            // ── WooCommerce and Elementor
            ['id' => 'wc_promo', 'match' => 'woocommerce.com/wp-json/wccom', 'type' => 'ad'],
            // ── Jetpack and the WordPress.com connection (often unused; WooCommerce also bundles it)
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

            // ── WPForms, WP Mail SMTP, ParsiDate, Advanced Shipping, IndexNow
            ['id' => 'wpforms_addons', 'match' => 'wpformsapi.com/feeds/', 'type' => 'ad'],
            ['id' => 'smtp_notice', 'match' => 'wpmailsmtpapi.com/feeds/', 'type' => 'news'],
            ['id' => 'parsi_sponsors', 'match' => self::PARSI_RAW, 'type' => 'ad'],
            ['id' => 'parsi_feed', 'match' => 'wp-parsi.com/', 'type' => 'news'],
            ['id' => 'ace_ads', 'match' => 'aceplugins.com/', 'type' => 'ad'],
            ['id' => 'yoast_indexnow', 'match' => 'api.indexnow.org/', 'type' => 'service'],
            ['id' => 'yoast_site', 'match' => 'my.yoast.com/', 'type' => 'license'],

            // ── Wincher (inside Yoast), Code Snippets, Emalls
            ['id' => 'wincher_ads', 'match' => 'api.wincher.com/v1/yoast/upgrade-campaign', 'type' => 'ad'],
            ['id' => 'wincher_all', 'match' => 'wincher.com/', 'type' => 'service'],
            ['id' => 'codesnip_ad', 'match' => 'codesnippets.pro/', 'type' => 'ad'],
            ['id' => 'emalls', 'match' => 'emalls.ir/', 'type' => 'service'],

            // ── Plugin functions (shown for information; open by default)
            ['id' => 'nv_license', 'match' => 'license.nivahost.com/', 'type' => 'license', 'cache' => true],
            ['id' => 'sms_payamak', 'match' => 'rest.payamak-panel.com/', 'type' => 'service'],
            ['id' => 'torob', 'match' => 'api.torob.com/', 'type' => 'service'],
        ];
    }

    public static function types(): array {
        return [
            'ad'      => [__('Ad', 'niva-guard'), '#c0282a', true],
            'news'    => [__('Notice / news', 'niva-guard'), '#d98a00', true],
            'license' => [__('License', 'niva-guard'), '#6d3fa8', false],
            'update'  => [__('Version check', 'niva-guard'), '#168591', false],
            'service' => [__('Plugin function', 'niva-guard'), '#0b8a3e', false],
            'third'   => [__('Third party', 'niva-guard'), '#555', false],
        ];
    }

    /** Does host+path start with this prefix? (A bare domain also matches its subdomains.) */
    public static function matches(string $needle, string $hostpath): bool {
        // Normalise: lower case, drop www., collapse repeated slashes; a bare domain equals "domain/"
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

        // A bare domain (like vip-themes.com/) also matches its subdomains: cdn.vip-themes.com/...
        if (substr($needle, -1) === '/' && substr_count($needle, '/') === 1) {
            $host = rtrim($needle, '/');

            return (bool) preg_match('#^([a-z0-9-]+\.)*' . preg_quote($host, '#') . '/#i', $hostpath);
        }

        return false;
    }

    /** Guess the type of a discovered connection from its address. */
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
