<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores and reads the settings (a single option) and the report of the connections seen.
 */
class NIVAWPG_Settings {

    public const OPTION = 'nivawpg_settings';
    public const SEEN = 'nivawpg_seen';
    public const WIDGETS = 'nivawpg_widgets';
    public const MENUS = 'nivawpg_menus';
    public const BREAKER = 'nivawpg_breaker';

    /** Menus that can never be hidden, so the way to these settings stays open. */
    public const PROTECTED_MENUS = ['index.php', 'options-general.php', 'plugins.php'];

    private static ?array $cache = null;

    public static function defaults(): array {
        return [
            'enabled'       => 1,   // Master switch
            'log'           => 1,   // Log connections
            'block'         => [],  // known rule id => 1 (blocked)
            'cache'         => [],  // known rule id => 1 (response cached)
            'custom'        => [],  // custom addresses (host/path) to block
            'offline'       => [],  // slugs of plugins taken fully offline
            'ui_core_news'  => 0,   // WordPress core news widgets
            'ui_elementor'  => 0,   // Elementor news widget
            'ui_widget_dirs' => [], // remove dashboard widgets coded by these plugins
            'ui_widgets'    => [],  // ids of the dashboard widgets ticked for removal (from the detected list)
            'ui_notices'    => 0,   // hide promotional notices by keyword
            'ui_keywords'   => '',  // notice keywords (one per line)
            'ui_yoast_feed' => 0,   // hide the Yoast widget feed
            'ui_pwsms'      => 0,   // switch off the Persian WooCommerce SMS ads
            'ui_parsidate'  => 0,   // ParsiDate: remove the dashboard widget (sponsor banner and feed) and close its connections
            'ui_menus'      => [],  // slugs of admin menu items that are only hidden (not removed)
            'ui_jetpack'    => 0,   // official Jetpack offline mode (no connection to WordPress.com)
            'deep'          => 0,   // deep tracking: direct PHP connections (file_get_contents / fopen over http)
            'breaker'       => 0,   // auto circuit breaker: a host that keeps failing or is slow is skipped for a while
            'breaker_hours' => 6,   // how long a failing host is skipped
            'breaker_fails' => 2,   // failures in a row before it activates
            'breaker_off'   => [],  // hosts the breaker never skips (exempt)
            'breaker_slow'  => 5,   // a response slower than this (seconds) also counts as a failure
            'loader_on'     => 0,   // mu-plugin loader (created only with the user's consent)
            'files_ok'      => 0,   // the user saved the settings; enabled files may now be repaired automatically
            'hb_on'         => 0,   // Heartbeat control (separate mu-plugin)
            'hb_interval'   => 300, // interval of the "slow" mode in seconds
            'hb_dash'       => 'interval', // default | interval | off
            'hb_editor'     => 'interval',
            'hb_admin'      => 'interval',
            'hb_front'      => 'off',
            'f404_on'       => 0,   // fast 404 (mu-plugin + 404.html + .htaccess)
            'f404_strict'   => 0,   // also close existing script files outside core/plugins/themes
            'cron_off'      => 0,   // DISABLE_WP_CRON in wp-config.php (a real server cron takes over)
            'cron_every'    => 5,   // interval of the server cron (minutes)
            'cache_hours'   => 6,
        ];
    }

    public static function get(): array {
        if (self::$cache === null) {
            $saved = get_option(self::OPTION, null);

            if ($saved === null) {
                $saved = self::migrate_legacy();
            }
            $saved = is_array($saved) ? $saved : [];
            self::$cache = array_merge(self::defaults(), $saved);

            // Older installs that already have the loader or mu files keep that state
            if (!array_key_exists('loader_on', $saved) && class_exists('NIVAWPG_Setup') && is_file(NIVAWPG_Setup::loader_path())) {
                self::$cache['loader_on'] = 1;
            }

            if (!array_key_exists('files_ok', $saved) && class_exists('NIVAWPG_Server')) {
                foreach (array_keys(NIVAWPG_Server::MU) as $k) {
                    if (is_file(NIVAWPG_Server::mu_path($k))) {
                        self::$cache['files_ok'] = 1;
                    }
                }
            }
        }

        return self::$cache;
    }

    /** One-time copy of settings and reports from the earlier option names: nwg_* (2.x) or nhg_* (NIVA HTTP Guard). */
    private static function migrate_legacy(): array {
        foreach (['nwg_', 'nhg_'] as $old_prefix) {
            $old = get_option($old_prefix . 'settings', null);

            if (!is_array($old)) {
                continue;
            }

            update_option(self::OPTION, $old);

            foreach (['seen' => self::SEEN, 'widgets' => self::WIDGETS, 'menus' => self::MENUS, 'breaker' => self::BREAKER, 'scan' => 'nivawpg_scan'] as $k => $new) {
                $v = get_option($old_prefix . $k, null);

                if ($v !== null && get_option($new, null) === null) {
                    update_option($new, $v, false);
                }
            }

            return $old;
        }

        return [];
    }
    public static function save(array $new): void {
        update_option(self::OPTION, array_merge(self::defaults(), $new));
        self::$cache = null;
    }

    /** @return array<string,array{title:string,source:string,context:string}> Widgets seen on this site's dashboard. */
    public static function widgets(): array {
        $w = get_option(self::WIDGETS, []);

        return is_array($w) ? $w : [];
    }

    /** @return array<string,string> Top-level admin menus seen: slug => title. */
    public static function menus(): array {
        $m = get_option(self::MENUS, []);

        return is_array($m) ? $m : [];
    }

    /** @return array<string,array{fails:int,until:int,trips:int,t:int,err:string}> Breaker state per host. */
    public static function breaker(): array {
        $b = get_option(self::BREAKER, []);

        return is_array($b) ? $b : [];
    }

    /** @return array<string,array> Report of the connections seen; key = md5(plugin|host|path). */
    public static function seen(): array {
        $s = get_option(self::SEEN, []);

        return is_array($s) ? $s : [];
    }
}
