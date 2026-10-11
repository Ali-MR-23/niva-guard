<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * نوشتن و خواندن فایل‌های خود افزونه در بیرون از پوشه‌ی افزونه (mu-plugins، 404.html، .htaccess) از راه WP_Filesystem.
 * اگر وردپرس اجازه‌ی نوشتن مستقیم ندهد (مثلاً به اطلاعات FTP نیاز باشد) همه‌چیز «false» برمی‌گرداند و صفحه‌ی تنظیمات
 * به‌جای نوشتن خودکار، دکمه‌ی دانلود و راهنمای دستی نشان می‌دهد.
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

    /** فقط ابتدای فایل (برای خواندن نشانگر) */
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
