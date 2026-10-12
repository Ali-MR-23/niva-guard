<?php
/*
Plugin Name: NIVA Guard
Plugin URI: https://www.nivahost.net
Description: Control the outgoing connections of plugins and themes: log every connection with its timing (txt file and report), block ads, notices and news feeds, cache license responses (without cutting them off), take chosen plugins offline, remove promotional dashboard widgets and notices, and skip failing hosts automatically. Every item has its own switch and setup is automatic on every new site.
Version: 2.6.3
Author: Ali Mohajer
Author URI: https://www.nivahost.net/wordpress-plugin-development
Requires at least: 6.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: niva-guard
*/

if (!defined('ABSPATH')) {
    exit;
}

// The legacy "NIVA HTTP Guard" plugin is still active: two copies must not run together.
if (defined('NHG_VERSION')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-warning"><p><b>NIVA Guard:</b> ' . esc_html__('The old “NIVA HTTP Guard” plugin is still active. Deactivate it so the new version can work. Your settings are migrated automatically and you can delete the old plugin after the first load.', 'niva-guard') . '</p></div>';
    });

    return;
}

// Another copy of this plugin (for example the pre-2.6 folder "niva-wp-guard") is already running.
if (defined('NIVAWPG_VERSION')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-warning"><p><b>NIVA Guard:</b> ' . esc_html__('Another copy of this plugin is already active. Deactivate the older one (folder “niva-wp-guard”); your settings are shared and nothing is lost.', 'niva-guard') . '</p></div>';
    });

    return;
}

define('NIVAWPG_VERSION', '2.6.3');
define('NIVAWPG_FILE', __FILE__);
define('NIVAWPG_DIR', plugin_dir_path(__FILE__));

require_once NIVAWPG_DIR . 'includes/class-nhg-settings.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-rules.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-fs.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-setup.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-server.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-direct.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-engine.php';
require_once NIVAWPG_DIR . 'includes/class-nhg-ui.php';

// The connection engine must register its filter before any other request is made
// (the optional mu-plugin loader includes this file before the other plugins).
NIVAWPG_Engine::boot();
NIVAWPG_Ui::boot();
NIVAWPG_Server::boot_cron_watch();

// Activation: create the log folder
register_activation_hook(NIVAWPG_FILE, static function () {
    NIVAWPG_Setup::ensure_log_dir();
    // No file outside the plugin folder (mu-plugins, 404.html, .htaccess) is created unless the user turns an option on.
    delete_transient('nivawpg_heal');
});

// Deactivation: remove the generated files (log and settings are kept)
register_deactivation_hook(NIVAWPG_FILE, static function () {
    NIVAWPG_Setup::remove_loader();
    NIVAWPG_Server::remove_all();
});

if (is_admin()) {
    require_once NIVAWPG_DIR . 'includes/class-nhg-admin.php';
    NIVAWPG_Admin::boot();

    // Self-heal: recreate the loader after an update or an accidental delete (at most every 12 hours)
    add_action('admin_init', static function () {
        if (!get_transient('nivawpg_heal')) {
            NIVAWPG_Setup::self_heal();
            NIVAWPG_Setup::ensure_log_dir();
            NIVAWPG_Server::heal();
            set_transient('nivawpg_heal', 1, 12 * HOUR_IN_SECONDS);
        }
    });
}
