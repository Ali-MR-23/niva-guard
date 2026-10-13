<?php
/**
 * Full clean-up on delete: loader and server-tool files, log files and options.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function nivawpg_uninstall_cleanup(): void
{
    require_once plugin_dir_path(__FILE__) . 'includes/class-nhg-fs.php';

    $mu_dir = rtrim(WPMU_PLUGIN_DIR, '/\\');

    // mu-plugin files (current and earlier names)
    foreach (['niva-guard-', 'niva-wp-guard-'] as $prefix) {
        foreach (['loader', 'heartbeat', 'fast-404', 'shortlink'] as $name) {
            $file = $mu_dir . '/' . $prefix . $name . '.php';

            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
    }

    // Log folder in uploads
    $up = wp_upload_dir(null, false);

    foreach (['niva-guard', 'niva-wp-guard', 'niva-http-guard'] as $folder) {
        $basedir = rtrim((string) $up['basedir'], '/\\');
        $dir = $basedir . '/' . $folder;

        if ($basedir === '' || !is_dir($dir)) {
            continue;
        }

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

    if (!function_exists('get_home_path')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $root = trailingslashit(get_home_path());

    // 404.html (only if this plugin created it)
    $html = $root . '404.html';

    if (is_file($html)) {
        $head = NIVAWPG_Fs::head($html, 400);

        if (strpos($head, 'nivawpg-404') !== false || strpos($head, 'nwg-404') !== false) {
            wp_delete_file($html);
        }
    }

    // .htaccess block
    $ht = $root . '.htaccess';

    if (is_file($ht) && NIVAWPG_Fs::writable($ht)) {
        $cur = NIVAWPG_Fs::get($ht);
        $new = preg_replace('/# BEGIN NIVA WP Guard.*?# END NIVA WP Guard\n\n?/s', '', str_replace("\r\n", "\n", $cur), 1);

        if (is_string($new) && $new !== str_replace("\r\n", "\n", $cur)) {
            NIVAWPG_Fs::put($ht, $new);
        }
    }

    // Options (current and earlier prefixes)
    foreach (['nivawpg_', 'nwg_', 'nhg_'] as $prefix) {
        foreach (['settings', 'seen', 'widgets', 'menus', 'breaker', 'scan', 'cron_last'] as $key) {
            delete_option($prefix . $key);
        }

        delete_transient($prefix . 'heal');
    }
}

nivawpg_uninstall_cleanup();