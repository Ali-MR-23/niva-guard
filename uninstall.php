<?php
/**
 * حذف کامل افزونه: لودر mu-plugin، فایل‌های ماژول‌های سرور، فایل‌های لاگ و تنظیمات پاک می‌شوند.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function nivawpg_uninstall_cleanup(): void
{
    require_once plugin_dir_path(__FILE__) . 'includes/class-nhg-fs.php';

    $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

    // فایل‌های mu: لودر و سه ماژول سرور
    foreach (['loader', 'heartbeat', 'fast-404', 'shortlink'] as $name) {
        $file = $mu_dir . '/niva-wp-guard-' . $name . '.php';

        if (is_file($file)) {
            wp_delete_file($file);
        }
    }

    // پوشه‌ی لاگ در uploads
    $up = wp_upload_dir(null, false);
    $dir = rtrim(!empty($up['basedir']) ? $up['basedir'] : WP_CONTENT_DIR, '/\\') . '/niva-wp-guard';

    if (is_dir($dir)) {
        foreach ((array) glob($dir . '/{,.}*', GLOB_BRACE) as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }

        global $wp_filesystem;

        if (!($wp_filesystem instanceof WP_Filesystem_Base)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ($wp_filesystem instanceof WP_Filesystem_Base) {
            $wp_filesystem->rmdir($dir);
        }
    }

    // 404.html (فقط اگر ساخته‌ی همین افزونه باشد)
    $html = ABSPATH . '404.html';

    if (is_file($html)) {
        $head = NIVAWPG_Fs::head($html, 400);

        if (strpos($head, 'nivawpg-404') !== false || strpos($head, 'nwg-404') !== false) {
            wp_delete_file($html);
        }
    }

    // بلوک .htaccess
    $ht = ABSPATH . '.htaccess';

    if (is_file($ht) && NIVAWPG_Fs::writable($ht)) {
        $cur = NIVAWPG_Fs::get($ht);
        $new = preg_replace('/# BEGIN NIVA WP Guard.*?# END NIVA WP Guard\n\n?/s', '', str_replace("\r\n", "\n", $cur), 1);

        if (is_string($new) && $new !== str_replace("\r\n", "\n", $cur)) {
            NIVAWPG_Fs::put($ht, $new);
        }
    }

    // گزینه‌ها (نام‌های فعلی و نام‌های قدیمی‌تر)
    foreach (['nivawpg_', 'nwg_', 'nhg_'] as $prefix) {
        foreach (['settings', 'seen', 'widgets', 'menus', 'breaker', 'scan', 'cron_last'] as $key) {
            delete_option($prefix . $key);
        }

        delete_transient($prefix . 'heal');
    }
}

nivawpg_uninstall_cleanup();