<?php
/*
Plugin Name: NIVA WP Guard
Plugin URI: https://www.nivahost.net
Description: Control the outgoing connections of plugins and themes: log every connection with its timing (txt file and report), block ads, notices and news feeds, cache license responses (without cutting them off), take chosen plugins offline, remove promotional dashboard widgets and notices, and skip failing hosts automatically. Every item has its own switch and setup is automatic on every new site.
Version: 2.5.1
Author: Ali Mohajer
Author URI: https://www.nivahost.net/wordpress-plugin-development
Requires at least: 6.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: niva-wp-guard
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

// نسخه‌ی قدیمی (NIVA HTTP Guard) هنوز فعال است؟ دو نسخه هم‌زمان نباید کار کنند.
if (defined('NHG_VERSION')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-warning"><p><b>NIVA WP Guard:</b> ' . esc_html__('The old “NIVA HTTP Guard” plugin is still active. Deactivate it so the new version can work. Your settings are migrated automatically and you can delete the old plugin after the first load.', 'niva-wp-guard') . '</p></div>';
    });

    return;
}

define('NIVAWPG_VERSION', '2.5.1');
define('NIVAWPG_FILE', __FILE__);
define('NIVAWPG_DIR', plugin_dir_path(__FILE__));

require_once NIVAWPG_DIR . 'includes/class-nhg-i18n.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-settings.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-rules.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-fs.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-setup.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-server.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-direct.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-engine.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-ui.php';

// موتور اتصال‌ها باید زودتر از هر درخواست دیگری فیلتر را ثبت کند
// (لودر mu-plugin که خود افزونه می‌سازد، این فایل را قبل از بقیه‌ی افزونه‌ها صدا می‌زند).
NIVAWPG_I18n::boot();
NIVAWPG_Engine::boot();
NIVAWPG_Ui::boot();
NIVAWPG_Server::boot_cron_watch();

// فعال‌سازی روی هر سایت جدید: پوشه‌ی لاگ txt + لودر زودتر
register_activation_hook(NIVAWPG_FILE, static function () {
    NIVAWPG_Setup::ensure_log_dir();
    // هیچ فایلی بیرون از پوشه‌ی افزونه (mu-plugins، 404.html، .htaccess) ساخته نمی‌شود، مگر کاربر در تنظیمات خودش بخواهد.
    delete_transient('nivawpg_heal');
});

// غیرفعال‌سازی: لودر پاک می‌شود تا چیزی به‌جا نماند (لاگ و تنظیمات می‌مانند)
register_deactivation_hook(NIVAWPG_FILE, static function () {
    NIVAWPG_Setup::remove_loader();
    NIVAWPG_Server::remove_all();
});

if (is_admin()) {
    require_once NIVAWPG_DIR . 'includes/class-nhg-admin.php';
    NIVAWPG_Admin::boot();

    // خودترمیمی: بعد از آپدیت افزونه یا پاک شدن تصادفی لودر، دوباره ساخته می‌شود (حداکثر هر ۱۲ ساعت)
    add_action('admin_init', static function () {
        if (!get_transient('nivawpg_heal')) {
            NIVAWPG_Setup::self_heal();
            NIVAWPG_Setup::ensure_log_dir();
            NIVAWPG_Server::heal();
            set_transient('nivawpg_heal', 1, 12 * HOUR_IN_SECONDS);
        }
    });
}
