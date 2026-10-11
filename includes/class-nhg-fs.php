<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and writes the files the plugin creates outside its own folder (mu-plugins, 404.html, .htaccess) through WP_Filesystem.
 * If WordPress cannot write directly (for example it needs FTP credentials) everything returns false and the settings page
 * shows download buttons and manual instructions instead of writing automatically.
 */
final class NIVAWPG_Fs {

    /** @return WP_Filesystem_Base|null */
    private static function fs() {
        global $wp_filesystem;

        if (!($wp_filesystem instanceof WP_Filesystem_Base)) {
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            WP_Filesystem();
        }

        return $wp_filesystem instanceof WP_Filesystem_Base ? $wp_filesystem : null;
    }

    public static function get(string $path): string {
        $fs = self::fs();
        $c = $fs && $fs->exists($path) ? $fs->get_contents($path) : false;

        return is_string($c) ? $c : '';
    }

    /** Only the beginning of the file (to read the marker). */
    public static function head(string $path, int $bytes = 400): string {
        return substr(self::get($path), 0, $bytes);
    }

    public static function put(string $path, string $data): bool {
        $fs = self::fs();

        if (!$fs) {
            return false;
        }

        $mode = $fs->exists($path) ? (int) $fs->getchmod($path) : 0;
        $mode = $mode > 0 ? octdec((string) $mode) : (defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);

        return (bool) $fs->put_contents($path, $data, $mode);
    }

    public static function copy(string $from, string $to): bool {
        $fs = self::fs();

        return $fs ? (bool) $fs->copy($from, $to, true) : false;
    }

    public static function move(string $from, string $to): bool {
        $fs = self::fs();

        return $fs ? (bool) $fs->move($from, $to, true) : false;
    }

    public static function writable(string $path): bool {
        $fs = self::fs();

        return $fs ? (bool) $fs->is_writable($path) : false;
    }
}
