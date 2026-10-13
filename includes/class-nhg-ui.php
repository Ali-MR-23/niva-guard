<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dashboard modules: each one runs only when its switch is on.
 * Removing a widget means its callback never runs, so its feed and banner are not fetched from an external server.
 */
class NIVAWPG_Ui {

    public static function boot(): void {
        add_action('wp_dashboard_setup', [self::class, 'dashboard'], 9999);
        add_action('admin_menu', [self::class, 'menus'], PHP_INT_MAX);
        add_action('admin_head', [self::class, 'menus'], 0); // Plugins such as WP-Parsidate add their menu at PHP_INT_MAX, after our first pass
        add_action('admin_enqueue_scripts', [self::class, 'notice_script']);
        add_action('admin_enqueue_scripts', [self::class, 'yoast_feed_script']);
        self::pwsms_ads();

        // Official Jetpack offline mode: no connection to WordPress.com (for sites that do not use Jetpack)
        add_filter('jetpack_offline_mode', static function ($v) {
            return self::on('ui_jetpack') ? true : $v;
        });
    }

    private static function on(string $key): bool {
        $s = NIVAWPG_Settings::get();

        return !empty($s['enabled']) && !empty($s[$key]);
    }

    /**
     * Admin menu: records every top-level item and only hides the ticked ones.
     * Nothing is really removed: the plugin stays active and its page still opens with a direct link.
     */
    public static function menus(): void {
        global $menu;

        if (!is_array($menu)) {
            return;
        }

        $found = [];

        foreach ($menu as $item) {
            if (empty($item[2]) || (isset($item[4]) && strpos((string) $item[4], 'wp-menu-separator') !== false)) {
                continue;
            }

            $title = trim(preg_replace(['/\s*\d+\s*(اطلاعیه|اعلان|notifications?|updates?|به‌روزرسانی).*$/ui', '/\s*\d+\s*$/u'], '', wp_strip_all_tags((string) $item[0])));
            $found[(string) $item[2]] = $title !== '' ? $title : (string) $item[2];
        }

        $old = NIVAWPG_Settings::menus();
        $merged = $found + $old;

        if ($merged !== $old) {
            update_option(NIVAWPG_Settings::MENUS, $merged, false);
        }

        $s = NIVAWPG_Settings::get();

        if (empty($s['enabled'])) {
            return;
        }

        foreach ((array) $s['ui_menus'] as $slug) {
            if (!in_array($slug, NIVAWPG_Settings::PROTECTED_MENUS, true)) {
                remove_menu_page($slug);
            }
        }
    }

    /** Records every dashboard widget of this site (id, title and source plugin) so it can be ticked for removal. */
    private static function record_widgets(): void {
        global $wp_meta_boxes;
        $found = [];

        foreach ((array) ($wp_meta_boxes['dashboard'] ?? []) as $context => $priorities) {
            foreach ((array) $priorities as $boxes) {
                foreach ((array) $boxes as $id => $box) {
                    if (!$box || empty($box['callback'])) {
                        continue;
                    }

                    $file = self::callback_file($box['callback']);
                    $source = 'WordPress core';

                    if (preg_match('#/(?:mu-)?plugins/([^/]+)/#', $file, $m)) {
                        $source = $m[1];
                    } elseif (preg_match('#/themes/([^/]+)/#', $file, $m)) {
                        $source = 'Theme: ' . $m[1];
                    }

                    $found[(string) $id] = ['title' => trim(wp_strip_all_tags((string) ($box['title'] ?? $id))), 'source' => $source, 'context' => (string) $context];
                }
            }
        }

        $old = NIVAWPG_Settings::widgets();
        $merged = $found + $old; // Widgets seen before (even if removed now) stay in the list

        if ($merged !== $old) {
            update_option(NIVAWPG_Settings::WIDGETS, $merged, false);
        }
    }

    public static function dashboard(): void {
        global $wp_meta_boxes;

        if (empty($wp_meta_boxes['dashboard'])) {
            return;
        }

        self::record_widgets(); // Always (before any removal) so the list is complete

        $s = NIVAWPG_Settings::get();

        if (empty($s['enabled'])) {
            return;
        }

        if (!empty($s['ui_parsidate'])) {
            // Any widget whose code lives in wp-parsidate (the ParsiDate widget with its external banner and feed)
            $s['ui_widget_dirs'] = array_merge((array) $s['ui_widget_dirs'], ['wp-parsidate']);
        }

        foreach ((array) $s['ui_widgets'] as $wid) {
            foreach (['normal', 'side', 'column3', 'column4'] as $ctx) {
                remove_meta_box($wid, 'dashboard', $ctx);
            }
        }

        if (!empty($s['ui_core_news'])) {
            foreach (['dashboard_primary', 'dashboard_secondary'] as $id) {
                remove_meta_box($id, 'dashboard', 'side');
                remove_meta_box($id, 'dashboard', 'normal');
            }
        }

        if (!empty($s['ui_elementor'])) {
            remove_meta_box('e-dashboard-overview', 'dashboard', 'normal');
            remove_meta_box('e-dashboard-overview', 'dashboard', 'side');
        }

        $dirs = array_filter((array) $s['ui_widget_dirs']);

        if (!$dirs) {
            return;
        }

        foreach ($wp_meta_boxes['dashboard'] as $context => $priorities) {
            foreach ($priorities as $boxes) {
                foreach ((array) $boxes as $id => $box) {
                    if (!$box || empty($box['callback'])) {
                        continue;
                    }

                    $file = self::callback_file($box['callback']);

                    foreach ($dirs as $slug) {
                        if ($file !== '' && strpos($file, '/plugins/' . $slug . '/') !== false) {
                            remove_meta_box($id, 'dashboard', $context);
                        }
                    }
                }
            }
        }
    }

    private static function callback_file($cb): string {
        try {
            if (is_array($cb)) {
                $ref = new ReflectionMethod($cb[0], $cb[1]);
            } elseif (is_string($cb) && strpos($cb, '::') !== false) {
                $ref = new ReflectionMethod($cb);
            } else {
                $ref = new ReflectionFunction($cb);
            }

            return str_replace('\\', '/', (string) $ref->getFileName());
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Small inline script of this plugin for admin pages (added with wp_add_inline_script). */
    private static function inline_js(string $js): void {
        if (!wp_script_is('nivawpg-ui', 'registered')) {
            wp_register_script('nivawpg-ui', false, [], NIVAWPG_VERSION, true);
        }

        wp_enqueue_script('nivawpg-ui');
        wp_add_inline_script('nivawpg-ui', $js);
    }

    /** Hides promotional admin notices by keyword (error and "new version" notices are left alone). */
    public static function notice_script(): void {
        if (!self::on('ui_notices')) {
            return;
        }

        $s = NIVAWPG_Settings::get();
        $words = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $s['ui_keywords']))));

        if (!$words) {
            return;
        }

        self::inline_js(
            "document.addEventListener('DOMContentLoaded', function () {\n"
            . '    var hide = ' . wp_json_encode($words, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ", keep = ['نسخه جدید', 'خطا', 'error'];\n"
            . <<<'JS'
    document.querySelectorAll('.notice, .updated, .update-nag, div.error, .is-dismissible').forEach(function (el) {
        var t = (el.textContent + ' ' + el.innerHTML).toLowerCase();
        var h = hide.some(function (k) { return t.indexOf(k.toLowerCase()) !== -1; });
        var k = keep.some(function (k2) { return t.indexOf(k2.toLowerCase()) !== -1; });
        if (h && !k) { el.style.display = 'none'; }
    });
});
JS
        );
    }

    /** Hides only the English feed part of the Yoast widget; the SEO score stays. */
    public static function yoast_feed_script(string $hook = ''): void {
        if ($hook !== 'index.php' || !self::on('ui_yoast_feed')) {
            return;
        }

        self::inline_js(<<<'JS'
(function () {
    function hideFeed() {
        document.querySelectorAll('#dashboard-widgets .postbox').forEach(function (box) {
            var head = box.querySelector('h2');
            if (!head || !/yoast/i.test(head.textContent)) { return; }
            var inside = box.querySelector('.inside') || box, all = inside.querySelectorAll('*');
            for (var i = 0; i < all.length; i++) {
                var el = all[i];
                if (el.children.length || el.tagName === 'A' || !/yoast\.com/i.test(el.textContent)) { continue; }
                var node = el;
                while (node.parentElement && node.parentElement !== inside && !node.previousElementSibling) { node = node.parentElement; }
                if (node === inside || /امتیاز سئو/.test(node.textContent)) { return; }
                for (var n = node; n; n = n.nextElementSibling) { if (n.style) { n.style.display = 'none'; } }
                return;
            }
        });
    }
    hideFeed();
    new MutationObserver(hideFeed).observe(document.getElementById('dashboard-widgets') || document.body, {childList: true, subtree: true});
})();
JS
        );
    }
    /** Switches off the built-in ads of the Persian WooCommerce SMS plugin (without touching its code). */
    private static function pwsms_ads(): void {
        add_filter('pre_option_pwoosms_ads_noticemelli', static function ($v) {
            return self::on('ui_pwsms') ? 1 : $v;
        });
        add_filter('pre_option_pwsms_notices', static function ($v) {
            return self::on('ui_pwsms') ? [] : $v;
        });
        add_filter('pre_transient_pwsms_notice_all', static function ($v) {
            return self::on('ui_pwsms') ? 'DISMISS' : $v;
        });
    }
}
