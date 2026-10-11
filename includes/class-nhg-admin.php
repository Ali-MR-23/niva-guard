<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings page: Settings → NIVA Guard. Every connection and every module has its own switch.
 */
class NIVAWPG_Admin {

    private const PAGE = 'niva-guard';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_nivawpg_save', [self::class, 'save']);
        add_action('admin_post_nivawpg_clear', [self::class, 'clear']);
        add_action('admin_post_nivawpg_download', [self::class, 'download']);
        add_action('admin_post_nivawpg_loader', [self::class, 'reinstall_loader']);
        add_action('admin_post_nivawpg_scan', [self::class, 'scan']);
        add_action('admin_post_nivawpg_server', [self::class, 'server']);
        add_action('admin_post_nivawpg_brk_reset', [self::class, 'brk_reset']);
        add_action('in_admin_header', [self::class, 'quiet_notices'], 0);
    }

    /** On this plugin's own page, notices from other plugins are not shown. */
    public static function quiet_notices(): void {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only detects the current page

        if ($page !== self::PAGE) {
            return;
        }

        foreach (['admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices'] as $h) {
            remove_all_actions($h);
        }
    }

    /** Styles and script are loaded on this settings page only. */
    public static function assets(string $hook): void {
        if ($hook !== 'settings_page_' . self::PAGE) {
            return;
        }

        wp_enqueue_style('nivawpg-admin', plugins_url('assets/admin.css', NIVAWPG_FILE), [], NIVAWPG_VERSION);
        wp_enqueue_script('nivawpg-admin', plugins_url('assets/admin.js', NIVAWPG_FILE), [], NIVAWPG_VERSION, true);
        wp_localize_script('nivawpg-admin', 'NIVAWPG_I18N', self::i18n_strings());
    }

    /** @return array<string,string> Strings for the script. */
    private static function i18n_strings(): array {
        return [
                'items'        => /* translators: %s: number of items */ __('%s items', 'niva-guard'),
                'riskLicense'  => __('This item is of the “license” type. Blocking it may lock a theme or plugin. Turn on “cache” instead. Continue?', 'niva-guard'),
                'riskService'  => __('This item is needed for the plugin itself to work (SMS, orders, etc.). Continue?', 'niva-guard'),
                'unsaved'      => /* translators: %s: number of unsaved changes */ __('%s unsaved changes', 'niva-guard'),
                'noChange'     => __('No changes made', 'niva-guard'),
                'copied'       => __('Copied ✓', 'niva-guard'),
                'confirmClear' => __('Clear the connection report and log file?', 'niva-guard'),
            ];
    }

    public static function menu(): void {
        add_options_page('NIVA Guard', 'NIVA Guard', 'manage_options', self::PAGE, [self::class, 'render']);
    }

    /* ───────────── Saving ───────────── */

    private static function normalize(string $line): string {
        $line = trim(strtolower($line));
        $line = preg_replace('#^[a-z]+://#', '', $line);
        $line = preg_replace('/[?#].*$/', '', $line);

        return (string) $line;
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'niva-guard'));
        }

        check_admin_referer('nivawpg_save');
        $in = map_deep(wp_unslash($_POST['nhg'] ?? []), 'sanitize_textarea_field');
        $known = array_column(NIVAWPG_Rules::all(), null, 'id');
        $new = NIVAWPG_Settings::defaults();

        $new['enabled'] = !empty($in['enabled']) ? 1 : 0;
        $new['log'] = !empty($in['log']) ? 1 : 0;
        $new['deep'] = !empty($in['deep']) ? 1 : 0;
        $new['breaker'] = !empty($in['breaker']) ? 1 : 0;
        $new['breaker_hours'] = max(1, min(72, (int) ($in['breaker_hours'] ?? 6)));
        $new['breaker_fails'] = max(1, min(5, (int) ($in['breaker_fails'] ?? 2)));
        $off = [];

        foreach (array_merge((array) ($in['breaker_off'] ?? []), preg_split('/\R/u', (string) ($in['breaker_off_text'] ?? '')) ?: []) as $h) {
            $h = trim(strtolower((string) $h));
            $h = preg_replace('#^[a-z]+://#', '', $h);
            $h = (string) preg_replace('#[/?\#].*$#', '', $h);

            if ($h !== '' && preg_match('/^[a-z0-9.-]+$/', $h)) {
                $off[$h] = $h;
            }
        }

        $new['breaker_off'] = array_values($off);

        // A host that was exempted is dropped from the skipped list
        $bk = NIVAWPG_Settings::breaker();

        foreach (array_keys($bk) as $bh) {
            foreach ($off as $h) {
                if ($bh === $h || substr($bh, -strlen($h) - 1) === '.' . $h) {
                    unset($bk[$bh]);
                }
            }
        }

        update_option(NIVAWPG_Settings::BREAKER, $bk);
        $new['breaker_slow'] = max(2, min(30, (int) ($in['breaker_slow'] ?? 5)));
        $new['cache_hours'] = max(1, min(72, (int) ($in['cache_hours'] ?? 6)));

        foreach ((array) ($in['block'] ?? []) as $id => $v) {
            if (isset($known[$id])) {
                $new['block'][$id] = 1;
            }
        }

        foreach ((array) ($in['cache'] ?? []) as $id => $v) {
            if (isset($known[$id]) && !empty($known[$id]['cache']) && empty($new['block'][$id])) {
                $new['cache'][$id] = 1;
            }
        }

        $custom = [];

        foreach ((array) ($in['disc'] ?? []) as $line) {
            $custom[] = self::normalize((string) $line);
        }

        foreach (preg_split('/\R/u', (string) ($in['custom'] ?? '')) as $line) {
            $custom[] = self::normalize((string) $line);
        }

        $new['custom'] = array_values(array_unique(array_filter($custom)));
        $new['offline'] = array_values(array_unique(array_map('sanitize_text_field', array_map('strval', (array) ($in['offline'] ?? [])))));
        $known_w = NIVAWPG_Settings::widgets();
        $new['ui_widgets'] = array_values(array_filter(array_unique(array_map('strval', (array) ($in['ui_widgets'] ?? []))), static fn($w) => isset($known_w[$w])));
        $known_m = NIVAWPG_Settings::menus();
        $new['ui_menus'] = array_values(array_filter(array_unique(array_map('strval', (array) ($in['ui_menus'] ?? []))), static fn($m) => isset($known_m[$m]) && !in_array($m, NIVAWPG_Settings::PROTECTED_MENUS, true)));
        $new['ui_widget_dirs'] = array_values(array_unique(array_map('sanitize_key', array_map('strval', (array) ($in['ui_widget_dirs'] ?? [])))));

        foreach (['ui_core_news', 'ui_elementor', 'ui_notices', 'ui_yoast_feed', 'ui_pwsms', 'ui_jetpack', 'ui_parsidate'] as $k) {
            $new[$k] = !empty($in[$k]) ? 1 : 0;
        }

        $new['ui_keywords'] = sanitize_textarea_field((string) ($in['ui_keywords'] ?? ''));

        // Server modules
        $new['loader_on'] = !empty($in['loader_on']) ? 1 : 0;
        $new['files_ok'] = 1; // An explicit save allows creating and repairing the enabled files
        $new['hb_on'] = !empty($in['hb_on']) ? 1 : 0;
        $new['hb_interval'] = max(1, min(3600, (int) ($in['hb_interval'] ?? 300)));

        foreach (['hb_dash' => 'interval', 'hb_editor' => 'interval', 'hb_admin' => 'interval', 'hb_front' => 'off'] as $k => $def) {
            $v = (string) ($in[$k] ?? $def);
            $new[$k] = in_array($v, NIVAWPG_Server::MODES, true) ? $v : $def;
        }

        $new['f404_on'] = !empty($in['f404_on']) ? 1 : 0;
        $new['f404_strict'] = !empty($in['f404_strict']) ? 1 : 0;
        $new['cron_off'] = !empty($in['cron_off']) ? 1 : 0;
        $every = (int) ($in['cron_every'] ?? 5);
        $new['cron_every'] = in_array($every, NIVAWPG_Server::CRON_EVERY, true) ? $every : 5;

        NIVAWPG_Settings::save($new);
        if ($new['loader_on']) {
            NIVAWPG_Setup::ensure_log_dir();
            NIVAWPG_Setup::install_loader();
        } else {
            NIVAWPG_Setup::remove_loader();
        }

        $srv = NIVAWPG_Server::apply(NIVAWPG_Settings::get());
        $srv_flag = '';

        if (!empty($new['f404_on']) && empty($srv['htaccess']['ok'])) {
            $srv_flag = '&srv=' . rawurlencode((string) $srv['htaccess']['code']);
        }

        $cron = NIVAWPG_Server::sync_cron(NIVAWPG_Settings::get());

        if (!in_array($cron, ['off', 'unchanged', 'ok', 'removed'], true)) {
            $srv_flag .= '&cron=' . rawurlencode($cron);
        }

        if ($srv_flag !== '') {
            $srv_flag .= '#tab=server';
        }

        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '&saved=1' . $srv_flag));
        exit;
    }

    public static function clear(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'niva-guard'));
        }

        check_admin_referer('nivawpg_clear');
        delete_option(NIVAWPG_Settings::SEEN);
        NIVAWPG_Setup::clear_log();
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '&cleared=1'));
        exit;
    }

    /** Download the text log or the summary report. */
    public static function download(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'niva-guard'));
        }

        check_admin_referer('nivawpg_download');
        $what = isset($_GET['what']) ? sanitize_key(wp_unslash($_GET['what'])) : 'report';
        $host = preg_replace('/[^a-z0-9.-]/i', '-', (string) wp_parse_url(home_url(), PHP_URL_HOST));

        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');

        if ($what === 'log' && is_file(NIVAWPG_Setup::log_path())) {
            header('Content-Disposition: attachment; filename="http-log-' . $host . '.txt"');
            readfile(NIVAWPG_Setup::log_path()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams the plugin's own text log
        } else {
            header('Content-Disposition: attachment; filename="http-report-' . $host . '.txt"');
            echo "\xEF\xBB\xBF" . NIVAWPG_Setup::report_text() . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text download (text/plain)
        }

        exit;
    }

    public static function brk_reset(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'niva-guard'));
        }

        check_admin_referer('nivawpg_brk_reset');
        delete_option(NIVAWPG_Settings::BREAKER);
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '&brk=1#tab=brk'));
        exit;
    }

    /** Actions of the "Server and 404" tab: install/remove .htaccess, rebuild 404.html, downloads. */
    public static function server(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'niva-guard'));
        }

        check_admin_referer('nivawpg_server');
        $do = isset($_GET['do']) ? sanitize_key(wp_unslash($_GET['do'])) : '';
        $s = NIVAWPG_Settings::get();
        $code = 'off';

        if ($do === 'dl404' || $do === 'dlhtaccess') {
            nocache_headers();

            if ($do === 'dl404') {
                header('Content-Type: text/html; charset=utf-8');
                header('Content-Disposition: attachment; filename="404.html"');
                echo NIVAWPG_Server::html404(); // phpcs:ignore WordPress.Security.EscapeOutput
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . (NIVAWPG_Server::is_nginx() ? 'nginx-fast-404.conf' : 'htaccess-fast-404.txt') . '"');
                echo NIVAWPG_Server::is_nginx() ? NIVAWPG_Server::nginx_snippet() : NIVAWPG_Server::htaccess_block($s) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
            }

            exit;
        }

        if ($do === 'htaccess' && !empty($s['f404_on'])) {
            $r = NIVAWPG_Server::install_htaccess($s);
            $code = $r['ok'] ? 'ok' : $r['code'];
        } elseif ($do === 'htaccess_remove') {
            $code = NIVAWPG_Server::remove_htaccess() ? 'removed' : 'unwritable';
        } elseif ($do === 'regen404' && !empty($s['f404_on'])) {
            $code = NIVAWPG_Server::sync_html(true) === 'unwritable' ? 'html_fail' : 'html_ok';
        }

        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '&srv=' . rawurlencode($code) . '#tab=server'));
        exit;
    }

    public static function scan(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'niva-guard'));
        }

        check_admin_referer('nivawpg_scan');
        NIVAWPG_Scan::run();
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '&scanned=1#tab=deep'));
        exit;
    }

    public static function reinstall_loader(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'niva-guard'));
        }

        check_admin_referer('nivawpg_loader');
        NIVAWPG_Setup::ensure_log_dir();
        $r = NIVAWPG_Setup::install_loader();
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '&loader=' . ($r['ok'] ? 'ok' : 'fail')));
        exit;
    }

    /* ───────────── Helpers ───────────── */

    /** @return array<string,string> Slug => name of the active plugins. */
    private static function active_plugins(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all = get_plugins();
        $out = [];

        foreach ((array) get_option('active_plugins', []) as $file) {
            $slug = strpos($file, '/') !== false ? dirname($file) : basename($file, '.php');

            if ($slug === 'niva-guard' || $slug === 'niva-wp-guard' || $slug === 'niva-http-guard') {
                continue;
            }

            $out[$slug] = isset($all[$file]['Name']) ? wp_strip_all_tags($all[$file]['Name']) : $slug;
        }

        asort($out);

        return $out;
    }

    private static function fmt_time(float $s): string {
        return $s < 1 ? number_format($s * 1000, 0) . ' ms' : number_format($s, 1) . ' s';
    }

    private static function digits(string $s): string {
        if (strpos(determine_locale(), 'fa') !== 0) {
            return $s; // Persian digits only for Persian
        }

        return strtr($s, array_combine(str_split('0123456789'), ["\u{06F0}", "\u{06F1}", "\u{06F2}", "\u{06F3}", "\u{06F4}", "\u{06F5}", "\u{06F6}", "\u{06F7}", "\u{06F8}", "\u{06F9}"]));
    }

    /** "N times · time · max time"; the max part turns red from 5 seconds up. */
    private static function impact(int $count, float $total, float $max): string {
        $cls = $max >= 5 ? 'nhg-slow' : '';

        return '<span>' . esc_html(sprintf(/* translators: 1: number of connections, 2: total wait time */ __('%1$s times · %2$s ·', 'niva-guard'), self::digits((string) $count), self::digits(self::fmt_time($total)))) . ' <span class="' . $cls . '">' . esc_html(sprintf(/* translators: %s: longest wait time */ __('max %s', 'niva-guard'), self::digits(self::fmt_time($max)))) . '</span></span>';
    }

    /** Display label of a widget source (stored in English). */
    private static function src_label(string $s): string {
        if ($s === 'WordPress core') {
            return __('WordPress core', 'niva-guard');
        }

        if (strpos($s, 'Theme: ') === 0) {
            return sprintf(/* translators: %s: theme name */ __('Theme: %s', 'niva-guard'), substr($s, 7));
        }

        return $s;
    }
    /** Statistics of every known rule from the report. */
    private static function rule_stats(array $rule, array $seen): array {
        $st = ['count' => 0, 'total' => 0.0, 'max' => 0.0, 'blocked' => 0, 'cached' => 0];

        foreach ($seen as $e) {
            if (NIVAWPG_Rules::matches($rule['match'], $e['host'] . $e['path'])) {
                $st['count'] += $e['count'];
                $st['total'] += $e['total'];
                $st['max'] = max($st['max'], $e['max']);
                $st['blocked'] += $e['blocked'];
                $st['cached'] += $e['cached'];
            }
        }

        return $st;
    }

    private static function covered(string $hostpath): bool {
        foreach (NIVAWPG_Rules::all() as $r) {
            if (NIVAWPG_Rules::matches($r['match'], $hostpath)) {
                return true;
            }
        }

        return false;
    }

    /* ───────────── Output ───────────── */

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Display flags after a redirect (success/error messages only; nothing is stored from them)
        $q = static function (string $key): string {
            return isset($_GET[$key]) ? sanitize_key(wp_unslash($_GET[$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        };

        $s = NIVAWPG_Settings::get();
        $seen = NIVAWPG_Settings::seen();
        $types = NIVAWPG_Rules::types();
        $rules = NIVAWPG_Rules::all();

        // Label and group of every rule (translated)
        $texts = NIVAWPG_Rules::texts();

        foreach ($rules as $ri => $rv) {
            $rules[$ri]['source'] = $texts[$rv['id']][0] ?? $rv['id'];
            $rules[$ri]['label'] = $texts[$rv['id']][1] ?? $rv['id'];
        }

        $sum = ['count' => 0, 'total' => 0.0, 'blocked' => 0, 'cached' => 0];

        foreach ($seen as $e) {
            $sum['count'] += $e['count'];
            $sum['total'] += $e['total'];
            $sum['blocked'] += $e['blocked'];
            $sum['cached'] += $e['cached'];
        }

        // Stats per rule plus the maximum time for the impact bar
        $stats = [];
        $maxtotal = 0.0;
        $type_counts = [];

        foreach ($rules as $r) {
            $stats[$r['id']] = self::rule_stats($r, $seen);
            $maxtotal = max($maxtotal, $stats[$r['id']]['total']);
            $type_counts[$r['type']] = ($type_counts[$r['type']] ?? 0) + 1;
        }

        // "Relevant to this site": its plugin/theme is active, or it was seen before, or the user ticked it
        $rel_ids = [];

        foreach ($rules as $r) {
            if (NIVAWPG_Rules::applies($r['id']) || $stats[$r['id']]['count'] > 0 || !empty($s['block'][$r['id']]) || !empty($s['cache'][$r['id']])) {
                $rel_ids[$r['id']] = true;
            }
        }

        $groups = [];

        foreach ($rules as $r) {
            $groups[$r['source']][] = $r;
        }

        $disc = [];

        foreach ($seen as $e) {
            $hp = $e['host'] . $e['path'];

            if (!self::covered($hp)) {
                $disc[] = $e + ['hp' => $hp];
            }
        }

        usort($disc, static fn($a, $b) => $b['total'] <=> $a['total']);
        $disc_max = $disc ? max(array_column($disc, 'total')) : 0.0;
        $active = self::active_plugins();
        $offline_choices = $active;

        foreach ($seen as $e) {
            if (!isset($offline_choices[$e['plugin']])) {
                $offline_choices[$e['plugin']] = $e['plugin'];
            }
        }

        $blocked_n = count(array_filter((array) $s['block'])) + count((array) $s['custom']);
        $ld = NIVAWPG_Setup::loader_status();
        $ld_map = ['installed' => [__('Installed and active', 'niva-guard'), '#0b8a3e'], 'missing' => [__('Not installed', 'niva-guard'), '#d98a00'], 'outdated' => [__('Outdated', 'niva-guard'), '#d98a00'], 'unwritable' => [__('The mu-plugins folder is not writable', 'niva-guard'), '#c0282a']];
        $rep = NIVAWPG_Setup::report();
        $log_ok = is_file(NIVAWPG_Setup::log_path());
        $dl = static fn($what) => wp_nonce_url(admin_url('admin-post.php?action=nivawpg_download&what=' . $what), 'nivawpg_download');
        ?>

        <div class="wrap nhg <?php echo is_rtl() ? 'nhg-rtl' : 'nhg-ltr'; ?>">
            <!-- Header -->
            <div class="nhg-hero">
                <div>
                    <h1>NIVA Guard
                        <span class="nhg-pill <?php echo $s['enabled'] ? '' : 'off'; ?>"><i></i><?php echo $s['enabled'] ? esc_html__('Active', 'niva-guard') : esc_html__('Inactive', 'niva-guard'); ?></span>
                    </h1>
                    <p><?php esc_html_e('See which plugin and theme connects where, how much of your site\'s time it takes, and block every ad with one switch. Cache licenses without cutting them off.', 'niva-guard'); ?></p>
                </div>
                <div class="nhg-stats">
                    <div class="nhg-stat"><b><?php echo esc_html(self::digits((string) $sum['count'])); ?></b><span><?php esc_html_e('Connections recorded', 'niva-guard'); ?></span></div>
                    <div class="nhg-stat"><b><?php echo esc_html(self::digits(self::fmt_time($sum['total']))); ?></b><span><?php esc_html_e('Total wait', 'niva-guard'); ?></span></div>
                    <div class="nhg-stat"><b><?php echo esc_html(self::digits((string) $sum['blocked'])); ?></b><span><?php esc_html_e('Blocked', 'niva-guard'); ?></span></div>
                    <div class="nhg-stat"><b><?php echo esc_html(self::digits((string) $sum['cached'])); ?></b><span><?php esc_html_e('From cache', 'niva-guard'); ?></span></div>
                </div>
            </div>

            <?php if ($q('saved') !== '') : ?><div class="nhg-flash" id="nhg-flash"><?php esc_html_e('✓ Settings saved.', 'niva-guard'); ?></div><?php endif; ?>
            <?php if ($q('cleared') !== '') : ?><div class="nhg-flash" id="nhg-flash"><?php esc_html_e('✓ Connection report and log file cleared.', 'niva-guard'); ?></div><?php endif; ?>
            <?php if ($q('srv') !== '') :
                $srv_msgs = [
                    'ok'         => ['✓ ' . __('.htaccess is up to date (a backup was saved).', 'niva-guard'), 1],
                    'removed'    => ['✓ ' . __('The .htaccess block was removed.', 'niva-guard'), 1],
                    'html_ok'    => ['✓ ' . __('404.html was regenerated.', 'niva-guard'), 1],
                    'html_fail'  => ['✕ ' . __('Could not write 404.html — download it and upload it to the site root.', 'niva-guard'), 0],
                    'rolled_back' => ['✕ ' . __('The site returned a server error after the change, so .htaccess was restored from the backup.', 'niva-guard'), 0],
                    'nginx'      => ['✕ ' . __('This server uses Nginx, which does not use .htaccess. Use the Nginx snippet below.', 'niva-guard'), 0],
                    'off'        => ['✕ ' . __('Enable Fast 404 and save first.', 'niva-guard'), 0],
                ];
                $sm = $srv_msgs[$q('srv')] ?? ['✕ ' . __('Could not write .htaccess — download the snippet below and add it yourself.', 'niva-guard'), 0]; ?>
                <div class="nhg-flash<?php echo $sm[1] ? '' : ' nhg-flash-bad'; ?>" id="nhg-flash"><?php echo esc_html($sm[0]); ?></div>
            <?php endif; ?>
            <?php if ($q('cron') !== '') :
                $cron_msgs = [
                    'custom'      => __('DISABLE_WP_CRON is already defined in wp-config.php by you, so it was left untouched.', 'niva-guard'),
                    'rolled_back' => __('The site returned a server error after the change, so wp-config.php was restored.', 'niva-guard'),
                    'nomarker'    => __('Could not find the right place in wp-config.php — add the line shown in the cron card yourself.', 'niva-guard'),
                ]; ?>
                <div class="nhg-flash nhg-flash-bad" id="nhg-flash">✕ <?php echo esc_html($cron_msgs[$q('cron')] ?? __('wp-config.php could not be changed automatically — add the line shown in the cron card yourself.', 'niva-guard')); ?></div>
            <?php endif; ?>
            <?php if ($q('brk') !== '') : ?><div class="nhg-flash" id="nhg-flash"><?php esc_html_e('✓ The list of skipped hosts was released.', 'niva-guard'); ?></div><?php endif; ?>
            <?php if ($q('scanned') !== '') : ?><div class="nhg-flash" id="nhg-flash"><?php esc_html_e('✓ Plugin code scan completed.', 'niva-guard'); ?></div><?php endif; ?>
            <?php if ($q('loader') !== '') : ?><div class="nhg-flash" id="nhg-flash"><?php echo $q('loader') === 'ok' ? esc_html__('✓ Loader installed.', 'niva-guard') : esc_html__('✕ Loader installation failed (the mu-plugins folder is not writable).', 'niva-guard'); ?></div><?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="nhg-form">
                <input type="hidden" name="action" value="nivawpg_save">
                <?php wp_nonce_field('nivawpg_save'); ?>

                <!-- Tabs -->
                <nav class="nhg-tabs" id="nhg-tabs">
                    <button type="button" class="nhg-tab on" data-tab="overview"><?php esc_html_e('Overview', 'niva-guard'); ?></button>
                    <button type="button" class="nhg-tab" data-tab="known"><?php esc_html_e('Known connections', 'niva-guard'); ?> <em><?php echo esc_html(self::digits((string) count($rules))); ?></em></button>
                    <button type="button" class="nhg-tab" data-tab="seen"><?php esc_html_e('Seen on your site', 'niva-guard'); ?> <em><?php echo esc_html(self::digits((string) count($disc))); ?></em></button>
                    <button type="button" class="nhg-tab" data-tab="offline"><?php esc_html_e('Take a plugin offline', 'niva-guard'); ?> <em><?php echo esc_html(self::digits((string) count((array) $s['offline']))); ?></em></button>
                    <button type="button" class="nhg-tab" data-tab="dash"><?php esc_html_e('Dashboard', 'niva-guard'); ?></button>
                    <button type="button" class="nhg-tab" data-tab="server"><?php esc_html_e('Server and 404', 'niva-guard'); ?></button>
                    <button type="button" class="nhg-tab" data-tab="brk"><?php esc_html_e('Auto circuit breaker', 'niva-guard'); ?></button>
                    <button type="button" class="nhg-tab" data-tab="deep"><?php esc_html_e('Deep tracking', 'niva-guard'); ?></button>
                    <button type="button" class="nhg-tab" data-tab="manual"><?php esc_html_e('Manual', 'niva-guard'); ?></button>
                </nav>

                <!-- Overview -->
                <section class="nhg-panel on" data-panel="overview">
                    <div class="nhg-grid">
                        <div class="nhg-card">
                            <div class="nhg-head"><div><h2><?php esc_html_e('General status', 'niva-guard'); ?></h2><p><?php esc_html_e('The master switch turns all blocking and modules on or off at once.', 'niva-guard'); ?></p></div></div>
                            <div class="nhg-body">
                                <div class="nhg-opts">
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[enabled]" value="1" <?php checked($s['enabled'], 1); ?>><span></span></span><div><b><?php esc_html_e('Plugin enabled', 'niva-guard'); ?></b><small><?php esc_html_e('Off = no connection is touched.', 'niva-guard'); ?></small></div></label>
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[log]" value="1" <?php checked($s['log'], 1); ?>><span></span></span><div><b><?php esc_html_e('Log connections', 'niva-guard'); ?></b><small><?php esc_html_e('A timed report, in a txt file and in the database.', 'niva-guard'); ?></small></div></label>
                                </div>
                                <div class="nhg-form-row" style="margin-top:16px">
                                    <div class="nhg-field">
                                        <span class="nhg-flabel"><?php esc_html_e('License response cache time', 'niva-guard'); ?></span>
                                        <div class="nhg-st-row">
                                        <div class="nhg-stepper" data-min="1" data-max="72">
                                            <button type="button" class="nhg-st-btn" data-step="-1" aria-label="<?php echo esc_attr__('Less', 'niva-guard'); ?>">−</button>
                                            <input type="number" name="nhg[cache_hours]" min="1" max="72" value="<?php echo (int) $s['cache_hours']; ?>" inputmode="numeric" aria-label="<?php echo esc_attr__('Cache time in hours', 'niva-guard'); ?>">
                                            <button type="button" class="nhg-st-btn" data-step="1" aria-label="<?php echo esc_attr__('More', 'niva-guard'); ?>">+</button>
                                        </div>
                                        <span class="nhg-st-unit"><?php esc_html_e('hours', 'niva-guard'); ?></span>
                                        </div>
                                        <div class="nhg-presets" role="group" aria-label="<?php echo esc_attr__('Preset values', 'niva-guard'); ?>">
                                            <?php foreach ([1 => __('1 hour', 'niva-guard'), 6 => __('6 hours', 'niva-guard'), 12 => __('12 hours', 'niva-guard'), 24 => __('1 day', 'niva-guard'), 72 => __('3 days', 'niva-guard')] as $h => $lab) : ?>
                                                <button type="button" class="nhg-preset" data-val="<?php echo (int) $h; ?>"><?php echo esc_html($lab); ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                        <small class="nhg-fhint"><?php esc_html_e('A successful license response is kept for this long so the server is not contacted every time.', 'niva-guard'); ?></small>
                                    </div>
                                </div>
                                <div class="nhg-note"><?php echo wp_kses(sprintf(/* translators: 1: blocked count, 2: cached count, 3: offline plugins count */ __('Status summary: <b>%1$s</b> blocked, <b>%2$s</b> cached, <b>%3$s</b> plugins offline.', 'niva-guard'), esc_html(self::digits((string) $blocked_n)), esc_html(self::digits((string) count(array_filter((array) $s['cache'])))), esc_html(self::digits((string) count((array) $s['offline'])))), ['b' => []]); ?></div>
                            </div>
                        </div>

                        <div class="nhg-card">
                            <div class="nhg-head"><div><h2><?php esc_html_e('Automatic setup and log file', 'niva-guard'); ?></h2><p><?php esc_html_e('It is created automatically on every new site.', 'niva-guard'); ?></p></div></div>
                            <div class="nhg-body">
                                <div class="nhg-kv">
                                    <div>
                                        <label class="nhg-opt" style="margin-bottom:10px"><span class="nhg-sw"><input type="checkbox" name="nhg[loader_on]" value="1" <?php checked($s['loader_on'], 1); ?>><span></span></span><div><b><?php esc_html_e('Load before other plugins (mu-plugin loader)', 'niva-guard'); ?></b><small><?php esc_html_e('Creates one small file in wp-content/mu-plugins. Nothing is created outside the plugin folder until you turn this on and save.', 'niva-guard'); ?></small></div></label>
                                        <b><?php esc_html_e('mu-plugin loader', 'niva-guard'); ?></b>
                                        <span class="nhg-badge" style="background:<?php echo esc_attr($ld_map[$ld][1]); ?>;margin-inline-start:8px"><?php echo esc_html($ld_map[$ld][0]); ?></span>
                                        <div style="margin-top:10px"><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=nivawpg_loader'), 'nivawpg_loader')); ?>"><?php esc_html_e('Install / repair again', 'niva-guard'); ?></a></div>
                                        <div style="color:var(--mut);font-size:.86em;margin-top:8px;line-height:1.7"><?php esc_html_e('Makes the plugin load before all the others so no connection escapes it.', 'niva-guard'); ?></div>
                                    </div>
                                    <div>
                                        <b><?php esc_html_e('txt log file', 'niva-guard'); ?></b>
                                        <?php if ($log_ok) : ?>
                                            <span style="margin-inline-start:8px"><?php echo esc_html(size_format((int) filesize(NIVAWPG_Setup::log_path()))); ?></span>
                                            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
                                                <a class="button" href="<?php echo esc_url($dl('log')); ?>"><?php esc_html_e('Download txt', 'niva-guard'); ?></a>
                                                <a class="button" href="<?php echo esc_url($dl('report')); ?>"><?php esc_html_e('Download summary report', 'niva-guard'); ?></a>
                                            </div>
                                        <?php else : ?><span style="color:var(--mut);margin-inline-start:8px"><?php esc_html_e('It is created with the first connection', 'niva-guard'); ?></span><?php endif; ?>
                                        <div class="ltr"><?php echo esc_html(str_replace(ABSPATH, '', NIVAWPG_Setup::log_path())); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="nhg-card" style="margin-top:18px">
                        <div class="nhg-head"><div><h2><?php esc_html_e('Report from the txt file', 'niva-guard'); ?></h2><p><?php esc_html_e('The slowest connections; you can copy this report and send it.', 'niva-guard'); ?></p></div></div>
                        <?php if ($rep['exists'] && $rep['rows']) : ?>
                            <div class="nhg-body">
                                <p style="margin:0 0 12px;color:#4b5563"><?php echo wp_kses(sprintf(/* translators: 1: unique connections, 2: slow connections, 3: total wait time, 4: errors */ __('<b>%1$s</b> unique connections · <b>%2$s</b> slow · total wait <b>%3$s</b> · <b>%4$s</b> errors or no response', 'niva-guard'), esc_html(self::digits((string) $rep['starts'])), esc_html(self::digits((string) $rep['slow'])), esc_html(self::digits(self::fmt_time($rep['wait']))), esc_html(self::digits((string) $rep['errors']))), ['b' => []]); ?></p>
                                <table class="nhg-tbl">
                                    <thead><tr><th><?php esc_html_e('Address', 'niva-guard'); ?></th><th><?php esc_html_e('Plugin / theme', 'niva-guard'); ?></th><th><?php esc_html_e('Count', 'niva-guard'); ?></th><th><?php esc_html_e('Impact', 'niva-guard'); ?></th><th><?php esc_html_e('Maximum', 'niva-guard'); ?></th></tr></thead>
                                    <tbody>
                                    <?php $top = max(0.001, (float) $rep['rows'][0]['total']);
                                    foreach (array_slice($rep['rows'], 0, 10) as $row) : ?>
                                        <tr>
                                            <td class="ltr"><?php echo esc_html($row['hp']); ?></td>
                                            <td><?php echo esc_html($row['plugin']); ?></td>
                                            <td><?php echo esc_html(self::digits((string) $row['n'])); ?></td>
                                            <td><div class="nhg-imp"><div class="nhg-bar"><i style="width:<?php echo (int) round(100 * $row['total'] / $top); ?>%"></i></div><span><?php echo esc_html(self::digits(self::fmt_time((float) $row['total']))); ?></span></div></td>
                                            <td class="<?php echo $row['max'] >= 5 ? 'nhg-slow' : ''; ?>"><?php echo esc_html(self::digits(self::fmt_time((float) $row['max']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <p style="margin:16px 0 6px;font-weight:700"><?php esc_html_e('Report text', 'niva-guard'); ?> <button type="button" class="button" id="nhg-copy" style="margin-inline-start:8px"><?php esc_html_e('Copy', 'niva-guard'); ?></button></p>
                                <textarea id="nhg-report" rows="8" readonly dir="auto" style="font-family:ui-monospace,Consolas,monospace;font-size:.85em"><?php echo esc_textarea(NIVAWPG_Setup::report_text()); ?></textarea>
                            </div>
                        <?php else : ?>
                            <div class="nhg-body"><p style="color:var(--mut);margin:0"><?php echo $log_ok ? esc_html__('The log file exists but no slow connection (over half a second) has been recorded yet.', 'niva-guard') : esc_html__('There is no report yet. Work with the site and the dashboard for a few minutes and check again.', 'niva-guard'); ?></p></div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Known connections -->
                <section class="nhg-panel" data-panel="known">
                    <div class="nhg-card">
                        <div class="nhg-head">
                            <div><h2><?php esc_html_e('Known connections', 'niva-guard'); ?></h2><p><?php esc_html_e('“Block” closes the connection completely. “Cache” is for licenses: the same valid response is kept for a few hours and the license is not cut off.', 'niva-guard'); ?></p></div>
                            <div class="nhg-act">
                                <button type="button" class="button" id="nhg-recommend"><?php esc_html_e('Select recommended (ads and notices)', 'niva-guard'); ?></button>
                                <button type="button" class="button" id="nhg-none"><?php esc_html_e('Clear all', 'niva-guard'); ?></button>
                            </div>
                        </div>
                        <div class="nhg-stabs" id="nhg-scope" role="tablist">
                            <button type="button" class="nhg-stab on" role="tab" data-scope="rel"><?php esc_html_e('Relevant to this site', 'niva-guard'); ?> <em><?php echo esc_html(self::digits((string) count($rel_ids))); ?></em></button>
                            <button type="button" class="nhg-stab" role="tab" data-scope="all"><?php esc_html_e('All connections', 'niva-guard'); ?> <em><?php echo esc_html(self::digits((string) count($rules))); ?></em></button>
                        </div>
                        <div class="nhg-tools">
                            <div class="nhg-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><input type="search" id="nhg-q" placeholder="<?php echo esc_attr__('Search: plugin, domain or description…', 'niva-guard'); ?>"></div>
                            <div class="nhg-chipbar" id="nhg-types">
                                <button type="button" class="nhg-fchip on" data-type=""><?php esc_html_e('All', 'niva-guard'); ?> <em><?php echo esc_html(self::digits((string) count($rules))); ?></em></button>
                                <?php foreach ($types as $tk => $tv) : if (empty($type_counts[$tk])) { continue; } ?>
                                    <button type="button" class="nhg-fchip" data-type="<?php echo esc_attr($tk); ?>"><?php echo esc_html($tv[0]); ?> <em><?php echo esc_html(self::digits((string) $type_counts[$tk])); ?></em></button>
                                <?php endforeach; ?>
                                <button type="button" class="nhg-fchip" data-type="__seen"><?php esc_html_e('Seen only', 'niva-guard'); ?></button>
                            </div>
                            <div class="nhg-count" id="nhg-qcount"></div>
                        </div>
                        <table class="nhg-tbl" id="nhg-known">
                            <thead><tr><th><?php esc_html_e('Description and address', 'niva-guard'); ?></th><th style="width:120px"><?php esc_html_e('Type', 'niva-guard'); ?></th><th style="width:210px"><?php esc_html_e('Impact on speed', 'niva-guard'); ?></th><th style="width:90px"><?php esc_html_e('Block', 'niva-guard'); ?></th><th style="width:80px"><?php esc_html_e('Cache', 'niva-guard'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($groups as $source => $list) : ?>
                                <tr class="nhg-src" data-src><td colspan="5"><?php echo esc_html($source); ?></td></tr>
                                <?php foreach ($list as $r) :
                                    $st = $stats[$r['id']];
                                    $t = $types[$r['type']] ?? ['—', '#555', false]; ?>
                                    <tr data-row data-rel="<?php echo isset($rel_ids[$r['id']]) ? 1 : 0; ?>" data-type="<?php echo esc_attr($r['type']); ?>" data-seen="<?php echo $st['count'] ? 1 : 0; ?>" data-q="<?php echo esc_attr(strtolower($source . ' ' . $r['label'] . ' ' . $r['match'])); ?>">
                                        <td><div class="ttl"><?php echo esc_html($r['label']); ?></div><div class="ltr"><?php echo esc_html($r['match']); ?></div></td>
                                        <td><span class="nhg-badge" style="background:<?php echo esc_attr($t[1]); ?>"><?php echo esc_html($t[0]); ?></span></td>
                                        <td>
                                            <?php if ($st['count']) : ?>
                                                <div class="nhg-imp"><div class="nhg-bar"><i style="width:<?php echo (int) max(4, round(100 * $st['total'] / max(0.001, $maxtotal))); ?>%"></i></div>
                                                <?php echo wp_kses_post(self::impact((int) $st['count'], (float) $st['total'], (float) $st['max'])); ?></div>
                                            <?php else : ?><span class="nhg-dim"><?php esc_html_e('Not seen yet', 'niva-guard'); ?></span><?php endif; ?>
                                        </td>
                                        <td><label class="nhg-sw bad"><input type="checkbox" class="nhg-block" data-rec="<?php echo $t[2] ? 1 : 0; ?>" data-type="<?php echo esc_attr($r['type']); ?>" name="nhg[block][<?php echo esc_attr($r['id']); ?>]" value="1" <?php checked(!empty($s['block'][$r['id']])); ?>><span></span></label></td>
                                        <td><?php if (!empty($r['cache'])) : ?><label class="nhg-sw"><input type="checkbox" class="nhg-cache" name="nhg[cache][<?php echo esc_attr($r['id']); ?>]" value="1" <?php checked(!empty($s['cache'][$r['id']])); ?>><span></span></label><?php else : ?><span class="nhg-dim">—</span><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="nhg-body" style="padding-top:6px"><div class="nhg-note"><?php esc_html_e('Blocking “license” and “plugin function” items may lock a theme or stop SMS and orders from working. For licenses, “cache” is safer. If you tick both “block” and “cache”, block wins.', 'niva-guard'); ?></div></div>
                    </div>
                </section>

                <!-- Seen on your site -->
                <section class="nhg-panel" data-panel="seen">
                    <div class="nhg-card">
                        <div class="nhg-head"><div><h2><?php esc_html_e('Connections seen on your site', 'niva-guard'); ?></h2><p><?php esc_html_e('Items that are not in the known list, ordered by longest wait. Tick one to block that address.', 'niva-guard'); ?></p></div></div>
                        <?php if (!$disc) : ?>
                            <div class="nhg-body"><p style="color:var(--mut);margin:0"><?php esc_html_e('No new connection has been recorded yet. Work with the site and the dashboard for a while and check again.', 'niva-guard'); ?></p></div>
                        <?php else : ?>
                            <div class="nhg-tools">
                                <div class="nhg-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><input type="search" id="nhg-q2" placeholder="<?php echo esc_attr__('Search the connections seen…', 'niva-guard'); ?>"></div>
                            </div>
                            <table class="nhg-tbl" id="nhg-disc">
                                <thead><tr><th style="width:190px"><?php esc_html_e('Plugin / theme', 'niva-guard'); ?></th><th><?php esc_html_e('Address', 'niva-guard'); ?></th><th style="width:210px"><?php esc_html_e('Impact on speed', 'niva-guard'); ?></th><th style="width:110px"><?php esc_html_e('Type (guess)', 'niva-guard'); ?></th><th style="width:90px"><?php esc_html_e('Block', 'niva-guard'); ?></th></tr></thead>
                                <tbody>
                                <?php foreach ($disc as $e) :
                                    $g = NIVAWPG_Rules::guess_type($e['hp']);
                                    $t = $types[$g] ?? ['—', '#555', false];
                                    $is_custom = in_array($e['hp'], (array) $s['custom'], true); ?>
                                    <tr data-row data-q="<?php echo esc_attr(strtolower($e['plugin'] . ' ' . $e['hp'])); ?>">
                                        <td><b><?php echo esc_html($e['plugin']); ?></b></td>
                                        <td class="ltr"><?php echo esc_html($e['hp']); ?></td>
                                        <td><div class="nhg-imp"><div class="nhg-bar"><i style="width:<?php echo (int) max(4, round(100 * $e['total'] / max(0.001, $disc_max))); ?>%"></i></div>
                                            <?php echo wp_kses_post(self::impact((int) $e['count'], (float) $e['total'], (float) $e['max'])); ?></div></td>
                                        <td><span class="nhg-badge" style="background:<?php echo esc_attr($t[1]); ?>"><?php echo esc_html($t[0]); ?></span></td>
                                        <td><label class="nhg-sw bad"><input type="checkbox" class="nhg-block" data-type="<?php echo esc_attr($g); ?>" name="nhg[disc][]" value="<?php echo esc_attr($e['hp']); ?>" <?php checked($is_custom); ?>><span></span></label></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Offline plugins -->
                <section class="nhg-panel" data-panel="offline">
                    <div class="nhg-card">
                        <div class="nhg-head"><div><h2><?php esc_html_e('Take a whole plugin offline', 'niva-guard'); ?></h2><p><?php esc_html_e('All external connections of the selected plugin are closed (only wordpress.org stays open). Use it only for plugins that do not need the internet or a license.', 'niva-guard'); ?></p></div></div>
                        <div class="nhg-tools"><div class="nhg-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><input type="search" id="nhg-q3" placeholder="<?php echo esc_attr__('Search plugin…', 'niva-guard'); ?>"></div></div>
                        <div class="nhg-body">
                            <div class="nhg-chips" id="nhg-offline">
                                <?php foreach ($offline_choices as $slug => $name) : ?>
                                    <div class="nhg-chip"><label data-q="<?php echo esc_attr(strtolower($name . ' ' . $slug)); ?>"><span class="nhg-sw bad"><input type="checkbox" name="nhg[offline][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, (array) $s['offline'], true)); ?>><span></span></span><span><?php echo esc_html($name); ?></span></label></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Dashboard -->
                <section class="nhg-panel" data-panel="dash">
                    <div class="nhg-grid">
                        <div class="nhg-card">
                            <div class="nhg-head"><div><h2><?php esc_html_e('Ready-made widgets and ads', 'niva-guard'); ?></h2><p><?php esc_html_e('Each item is switched on separately.', 'niva-guard'); ?></p></div></div>
                            <div class="nhg-body">
                                <div class="nhg-opts">
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[ui_core_news]" value="1" <?php checked($s['ui_core_news'], 1); ?>><span></span></span><div><b><?php esc_html_e('WordPress news widget', 'niva-guard'); ?></b><small><?php esc_html_e('“Events and News” and the planet news.', 'niva-guard'); ?></small></div></label>
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[ui_parsidate]" value="1" <?php checked($s['ui_parsidate'], 1); ?>><span></span></span><div><b><?php esc_html_e('ParsiDate: widget and ads', 'niva-guard'); ?></b><small><?php esc_html_e('The dashboard widget (sponsor banner such as Files.ir and the news feed) is removed and the sponsors.json and wp-parsi.com connections are closed. The Jalali calendar stays untouched.', 'niva-guard'); ?></small></div></label>
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[ui_elementor]" value="1" <?php checked($s['ui_elementor'], 1); ?>><span></span></span><div><b><?php esc_html_e('Elementor news widget', 'niva-guard'); ?></b><small><?php esc_html_e('Version and tool ads.', 'niva-guard'); ?></small></div></label>
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[ui_yoast_feed]" value="1" <?php checked($s['ui_yoast_feed'], 1); ?>><span></span></span><div><b><?php esc_html_e('Yoast widget feed', 'niva-guard'); ?></b><small><?php esc_html_e('The SEO score stays; only the English feed is hidden.', 'niva-guard'); ?></small></div></label>
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[ui_jetpack]" value="1" <?php checked($s['ui_jetpack'], 1); ?>><span></span></span><div><b><?php esc_html_e('Jetpack: offline mode', 'niva-guard'); ?></b><small><?php esc_html_e('The connection to WordPress.com and the Jetpack connections (including the bundle inside WooCommerce) are cut.', 'niva-guard'); ?></small></div></label>
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[ui_pwsms]" value="1" <?php checked($s['ui_pwsms'], 1); ?>><span></span></span><div><b><?php esc_html_e('Persian WooCommerce SMS ads', 'niva-guard'); ?></b><small><?php esc_html_e('The national SMS banner and plugin install suggestions.', 'niva-guard'); ?></small></div></label>
                                </div>
                            </div>
                        </div>
                        <div class="nhg-card">
                            <div class="nhg-head"><div><h2><?php esc_html_e('Hide promotional notices', 'niva-guard'); ?></h2><p><?php esc_html_e('Errors and “new version” notices always stay.', 'niva-guard'); ?></p></div></div>
                            <div class="nhg-body">
                                <label class="nhg-opt" style="margin-bottom:12px"><span class="nhg-sw"><input type="checkbox" name="nhg[ui_notices]" value="1" <?php checked($s['ui_notices'], 1); ?>><span></span></span><div><b><?php esc_html_e('Enable the keyword filter for notices', 'niva-guard'); ?></b></div></label>
                                <textarea name="nhg[ui_keywords]" rows="5" placeholder="<?php echo esc_attr__('One keyword per line, for example: Digits jacket', 'niva-guard'); ?>"><?php echo esc_textarea($s['ui_keywords']); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <?php $wl = NIVAWPG_Settings::widgets(); ?>
                    <div class="nhg-card" style="margin-top:18px">
                        <div class="nhg-head"><div><h2><?php esc_html_e('Dashboard widgets of this site', 'niva-guard'); ?> <span style="font-weight:400;color:var(--mut);font-size:.85em"><?php esc_html_e('(one switch each)', 'niva-guard'); ?></span></h2><p><?php esc_html_e('All the widgets that have been seen on this site\'s dashboard, with the name of the plugin that created them. A ticked widget is never created, so its feed and ad banner are not fetched from an external server either.', 'niva-guard'); ?></p></div></div>
                        <div class="nhg-body">
                            <?php if (!$wl) : ?>
                                <p style="color:var(--mut);margin:0"><?php echo wp_kses(sprintf(/* translators: %s: URL of the Dashboard page */ __('There is no list yet. Open the <a href="%s">Dashboard</a> page once and come back here.', 'niva-guard'), esc_url(admin_url('index.php'))), ['a' => ['href' => []]]); ?></p>
                            <?php else : ?>
                                <div class="nhg-opts">
                                    <?php foreach ($wl as $wid => $w) : ?>
                                        <label class="nhg-opt"><span class="nhg-sw bad"><input type="checkbox" name="nhg[ui_widgets][]" value="<?php echo esc_attr($wid); ?>" <?php checked(in_array($wid, (array) $s['ui_widgets'], true)); ?>><span></span></span>
                                            <div><b><?php echo esc_html($w['title'] !== '' ? $w['title'] : $wid); ?></b><small><?php echo esc_html(self::src_label((string) $w['source'])); ?> · <span style="direction:ltr;display:inline-block"><?php echo esc_html($wid); ?></span></small></div></label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php $ml = array_diff_key(NIVAWPG_Settings::menus(), array_flip(NIVAWPG_Settings::PROTECTED_MENUS)); ?>
                    <div class="nhg-card" style="margin-top:18px">
                        <div class="nhg-head"><div><h2><?php esc_html_e('Admin menu (right side)', 'niva-guard'); ?> <span style="font-weight:400;color:var(--mut);font-size:.85em"><?php esc_html_e('(only hidden, not removed)', 'niva-guard'); ?></span></h2><p><?php esc_html_e('Every ticked item is hidden from the side menu. The plugin stays active and its settings still open by direct address. “Dashboard”, “Settings” and “Plugins” are not in this list so you never lose access.', 'niva-guard'); ?></p></div></div>
                        <div class="nhg-body">
                            <?php if (!$ml) : ?>
                                <p style="color:var(--mut);margin:0"><?php esc_html_e('There is no list yet. Open another admin page and come back here.', 'niva-guard'); ?></p>
                            <?php else : ?>
                                <div class="nhg-chips">
                                    <?php foreach ($ml as $slug => $title) : ?>
                                        <div class="nhg-chip"><label><span class="nhg-sw bad"><input type="checkbox" name="nhg[ui_menus][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, (array) $s['ui_menus'], true)); ?>><span></span></span><span><?php echo esc_html($title); ?></span></label></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="nhg-card" style="margin-top:18px">
                        <div class="nhg-head"><div><h2><?php esc_html_e('Remove dashboard widgets of the selected plugins', 'niva-guard'); ?></h2><p><?php esc_html_e('The widget code does not run and its feed and banner are not fetched from an external server.', 'niva-guard'); ?></p></div></div>
                        <div class="nhg-body">
                            <div class="nhg-chips">
                                <?php foreach ($active as $slug => $name) : ?>
                                    <div class="nhg-chip"><label><span class="nhg-sw"><input type="checkbox" name="nhg[ui_widget_dirs][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, (array) $s['ui_widget_dirs'], true)); ?>><span></span></span><span><?php echo esc_html($name); ?></span></label></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Server and 404 -->
                <section class="nhg-panel" data-panel="server">
                    <?php
                    $srv = NIVAWPG_Server::status($s);
                    $srv_text = [
                        'ok'         => [__('Installed', 'niva-guard'), '#0b8a3e'],
                        'missing'    => [__('Not installed', 'niva-guard'), '#d98a00'],
                        'outdated'   => [__('Outdated — it is updated when you save', 'niva-guard'), '#d98a00'],
                        'stale'      => [__('Still present — it is removed when you save', 'niva-guard'), '#d98a00'],
                        'unwritable' => [__('Not writable', 'niva-guard'), '#c0282a'],
                        'custom'     => [__('Your own file (kept untouched)', 'niva-guard'), '#168591'],
                        'nginx'      => [__('Not used on Nginx', 'niva-guard'), '#555'],
                        'off'        => [__('Off', 'niva-guard'), '#8a93a3'],
                                            ];
                    $srv_row = static function (string $label, string $code) use ($srv_text): void {
                        $t = $srv_text[$code] ?? $srv_text['off'];
                        echo '<div class="nhg-srv-row"><span>' . esc_html($label) . '</span><span class="nhg-badge" style="background:' . esc_attr($t[1]) . '">' . esc_html($t[0]) . '</span></div>';
                    };
                    $hb_modes = ['default' => __('WordPress default', 'niva-guard'), 'interval' => __('Slow down (use the interval)', 'niva-guard'), 'off' => __('Turn off', 'niva-guard')];
                    $hb_where = ['hb_dash' => __('Dashboard', 'niva-guard'), 'hb_editor' => __('Post editor', 'niva-guard'), 'hb_admin' => __('Other admin pages', 'niva-guard'), 'hb_front' => __('Front-end (visitors)', 'niva-guard')];
                    $srv_url = static fn(string $do): string => wp_nonce_url(admin_url('admin-post.php?action=nivawpg_server&do=' . $do), 'nivawpg_server');
                    $ht_state = $srv['htaccess'] === 'outdated' && empty($s['f404_on']) ? 'stale' : $srv['htaccess'];
                    $nginx = NIVAWPG_Server::is_nginx();
                    ?>
                    <div class="nhg-grid">
                        <div class="nhg-card">
                            <div class="nhg-head"><div><h2><?php esc_html_e('Heartbeat control', 'niva-guard'); ?></h2><p><?php esc_html_e('WordPress sends a background request (Heartbeat) every 15–60 seconds for autosave, post locking and notices. On weak hosts this alone can overload the server. Control it separately for each part of the site.', 'niva-guard'); ?></p></div></div>
                            <div class="nhg-body">
                                <div class="nhg-opts">
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[hb_on]" value="1" <?php checked($s['hb_on'], 1); ?>><span></span></span><div><b><?php esc_html_e('Enable heartbeat control', 'niva-guard'); ?></b><small><?php esc_html_e('A separate mu-plugin file is created; off = default WordPress behavior.', 'niva-guard'); ?></small></div></label>
                                </div>
                                <div style="margin-top:12px">
                                    <?php foreach ($hb_where as $key => $lab) : ?>
                                        <div class="nhg-hb-row"><span class="nhg-flabel"><?php echo esc_html($lab); ?></span>
                                            <select name="nhg[<?php echo esc_attr($key); ?>]">
                                                <?php foreach ($hb_modes as $mk => $ml) : ?><option value="<?php echo esc_attr($mk); ?>" <?php selected($s[$key], $mk); ?>><?php echo esc_html($ml); ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="nhg-field" style="margin-top:14px">
                                    <span class="nhg-flabel"><?php esc_html_e('Interval when slowed (seconds)', 'niva-guard'); ?></span>
                                    <input type="number" name="nhg[hb_interval]" min="1" max="3600" value="<?php echo (int) $s['hb_interval']; ?>" class="small-text">
                                    <small class="nhg-fhint"><?php esc_html_e('For example 300 = every 5 minutes, 600 = every 10 minutes.', 'niva-guard'); ?></small>
                                </div>
                                <div class="nhg-note"><?php esc_html_e('Turning it off in the post editor disables autosave and post locking while editing.', 'niva-guard'); ?></div>
                                <div style="margin-top:14px"><?php $srv_row(__('mu-plugin file', 'niva-guard'), $srv['mu']['heartbeat']); ?></div>
                            </div>
                        </div>

                        <div class="nhg-card">
                            <div class="nhg-head"><div><h2><?php esc_html_e('Fast 404', 'niva-guard'); ?></h2><p><?php esc_html_e('Scanner bots probe hundreds of strange addresses (.env, backup files, fake PHP files). Instead of loading WordPress, the database, CSS and JS for each one, a light static 404 page is returned immediately.', 'niva-guard'); ?></p></div></div>
                            <div class="nhg-body">
                                <div class="nhg-opts">
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[f404_on]" id="nhg-f404" value="1" <?php checked($s['f404_on'], 1); ?>><span></span></span><div><b><?php esc_html_e('Enable fast 404', 'niva-guard'); ?></b><small><?php esc_html_e('Creates a separate mu-plugin, a 404.html page in the site root and a block in .htaccess.', 'niva-guard'); ?></small></div></label>
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[f404_strict]" value="1" <?php checked($s['f404_strict'], 1); ?>><span></span></span><div><b><?php esc_html_e('Strict mode', 'niva-guard'); ?></b><small><?php esc_html_e('Also blocks existing script files outside the WordPress core, plugins and themes folders. May break custom scripts placed in the site root.', 'niva-guard'); ?></small></div></label>
                                </div>
                                <h3 style="margin:18px 0 4px"><?php esc_html_e('Installation status', 'niva-guard'); ?></h3>
                                <?php $srv_row(__('mu-plugin file', 'niva-guard'), $srv['mu']['fast404']); $srv_row(__('404.html page', 'niva-guard'), $srv['html']); $srv_row(__('.htaccess block', 'niva-guard'), $ht_state); ?>
                                <div class="nhg-srv-acts">
                                    <?php if (!empty($s['f404_on'])) : ?>
                                        <?php if (!$nginx) : ?><a class="button" href="<?php echo esc_url($srv_url('htaccess')); ?>"><?php esc_html_e('Install / repair .htaccess', 'niva-guard'); ?></a><?php endif; ?>
                                        <a class="button" href="<?php echo esc_url($srv_url('regen404')); ?>"><?php esc_html_e('Regenerate 404.html', 'niva-guard'); ?></a>
                                    <?php endif; ?>
                                    <?php if (!$nginx && $srv['htaccess'] !== 'off') : ?><a class="button" href="<?php echo esc_url($srv_url('htaccess_remove')); ?>"><?php esc_html_e('Remove .htaccess block', 'niva-guard'); ?></a><?php endif; ?>
                                    <a class="button" href="<?php echo esc_url($srv_url('dl404')); ?>"><?php esc_html_e('Download 404.html', 'niva-guard'); ?></a>
                                    <a class="button" href="<?php echo esc_url($srv_url('dlhtaccess')); ?>"><?php echo esc_html($nginx ? __('Download Nginx snippet', 'niva-guard') : __('Download .htaccess snippet', 'niva-guard')); ?></a>
                                </div>
                                <details class="nhg-details" <?php echo in_array($srv['htaccess'], ['unwritable', 'nginx'], true) || $srv['html'] === 'unwritable' ? 'open' : ''; ?>>
                                    <summary><?php echo esc_html($nginx ? __('Nginx configuration', 'niva-guard') : __('Code to add to .htaccess', 'niva-guard')); ?></summary>
                                    <p class="nhg-fhint" style="margin-top:8px"><?php echo esc_html($nginx ? __('Nginx does not use .htaccess; add this to your server configuration. Fake PHP files are handled by the mu-plugin.', 'niva-guard') : __('If automatic installation is not possible, download the files and place them yourself: put 404.html in the site root (next to wp-config.php) and add this code to the top of .htaccess (before “# BEGIN WordPress”).', 'niva-guard')); ?></p>
                                    <textarea id="nhg-snip" readonly rows="12"><?php echo esc_textarea($nginx ? NIVAWPG_Server::nginx_snippet() : NIVAWPG_Server::htaccess_block($s)); ?></textarea>
                                    <p><button type="button" class="button nhg-copy" data-target="nhg-snip"><?php esc_html_e('Copy', 'niva-guard'); ?></button></p>
                                </details>
                            </div>
                        </div>

                        <?php
                        $cr = NIVAWPG_Server::cron_info($s);
                        $cr_state = NIVAWPG_Server::cron_status($s);
                        $cr_late = !empty($s['cron_off']) ? NIVAWPG_Server::cron_overdue($cr['every']) : 0;
                        ?>
                        <div class="nhg-card">
                            <div class="nhg-head"><div><h2><?php esc_html_e('WordPress cron (wp-cron)', 'niva-guard'); ?></h2><p><?php esc_html_e('By default WordPress runs its scheduled tasks (scheduled posts, emails, backups, WooCommerce jobs) during visitors’ page loads. This slows pages down and wastes resources. Turn it off here and let the server run it on a fixed schedule instead.', 'niva-guard'); ?></p></div></div>
                            <div class="nhg-body">
                                <div class="nhg-opts">
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[cron_off]" value="1" <?php checked($s['cron_off'], 1); ?>><span></span></span><div><b><?php esc_html_e('Disable the built-in WordPress cron', 'niva-guard'); ?></b><small><?php esc_html_e('Adds DISABLE_WP_CRON to wp-config.php. Add the server cron job below first, otherwise scheduled tasks stop running.', 'niva-guard'); ?></small></div></label>
                                </div>
                                <div class="nhg-field" style="margin-top:14px">
                                    <span class="nhg-flabel"><?php esc_html_e('Run the server job every', 'niva-guard'); ?></span>
                                    <select name="nhg[cron_every]" id="nhg-cron-every">
                                        <?php foreach (NIVAWPG_Server::CRON_EVERY as $m) : ?>
                                            <option value="<?php echo (int) $m; ?>" data-sched="<?php echo esc_attr(NIVAWPG_Server::cron_schedule($m)); ?>" <?php selected($cr['every'], $m); ?>><?php echo esc_html($m < 60 ? sprintf(/* translators: %s: number of minutes */ __('%s minutes', 'niva-guard'), self::digits((string) $m)) : ($m === 60 ? __('1 hour', 'niva-guard') : sprintf(/* translators: %s: number of hours */ __('%s hours', 'niva-guard'), self::digits((string) ($m / 60))))); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="nhg-fhint"><?php esc_html_e('5 minutes suits most sites and shops, 15 minutes is enough for a blog. Use 1 minute only if you need exact timing; more frequent runs only load the server.', 'niva-guard'); ?> <?php esc_html_e('Longer intervals (hours) lower the server load the most, but scheduled posts, emails and WooCommerce jobs then run later (up to that interval).', 'niva-guard'); ?></small>
                                </div>
                                <div style="margin-top:14px"><?php $srv_row(__('wp-config.php setting', 'niva-guard'), $cr_state); ?></div>
                                <?php
                                $cl = NIVAWPG_Server::cron_last();
                                $cl_row = static function (string $label, string $value, string $color = ''): void {
                                    echo '<div class="nhg-srv-row"><span>' . esc_html($label) . '</span><span' . ($color !== '' ? ' style="color:' . esc_attr($color) . '"' : '') . '>' . esc_html($value) . '</span></div>';
                                };
                                $cl_when = static fn(int $t): string => self::digits(wp_date('Y/m/d H:i', $t)) . ' (' . sprintf(/* translators: %s: time span such as 5 minutes */ __('%s ago', 'niva-guard'), self::digits(human_time_diff($t))) . ')';
                                $cl_how = ['cli' => __('Server cron command', 'niva-guard'), 'web' => __('Web request (visitor or URL)', 'niva-guard')];
                                ?>
                                <h3 style="margin:18px 0 4px"><?php esc_html_e('Cron activity', 'niva-guard'); ?></h3>
                                <?php if ($cl['ok']) : ?>
                                    <?php $cl_row(__('Last successful run', 'niva-guard'), $cl_when((int) $cl['ok']['time']), '#0b8a3e'); ?>
                                    <?php $cl_row(__('Run by', 'niva-guard'), $cl_how[$cl['ok']['how'] ?? 'web'] ?? $cl_how['web']); ?>
                                <?php else : ?>
                                    <?php $cl_row(__('Last successful run', 'niva-guard'), __('Not recorded yet', 'niva-guard'), '#d98a00'); ?>
                                <?php endif; ?>
                                <?php if ($cl['fail'] && (int) $cl['fail']['time'] > (int) ($cl['ok']['time'] ?? 0)) : ?>
                                    <?php $cl_row(__('Last failed run', 'niva-guard'), $cl_when((int) $cl['fail']['time']) . ' — ' . (string) $cl['fail']['msg'], '#c0282a'); ?>
                                <?php endif; ?>
                                <?php if ($cl['next']) : ?>
                                    <?php $cl_row(__('Next scheduled task', 'niva-guard'), $cl['next'] <= time() ? self::digits(wp_date('Y/m/d H:i', $cl['next'])) : self::digits(wp_date('Y/m/d H:i', $cl['next'])) . ' (' . sprintf(/* translators: %s: time span such as 5 minutes */ __('in %s', 'niva-guard'), self::digits(human_time_diff($cl['next']))) . ')'); ?>
                                <?php endif; ?>
                                <?php if ($cr_late) : ?>
                                    <div class="nhg-note"><?php echo esc_html(sprintf(/* translators: %s: number of minutes */ __('Scheduled tasks are %s minutes overdue. Check that the server cron job below exists.', 'niva-guard'), self::digits((string) $cr_late))); ?></div>
                                <?php endif; ?>

                                <h3 style="margin:18px 0 4px"><?php esc_html_e('Cron job for cPanel', 'niva-guard'); ?></h3>
                                <p class="nhg-fhint"><?php esc_html_e('In cPanel open “Cron Jobs” → “Add New Cron Job”. Enter the schedule (or choose the matching “Common Settings”), paste the command and save.', 'niva-guard'); ?></p>
                                <input type="hidden" id="nhg-cron-sch" value="<?php echo esc_attr($cr['schedule']); ?>">
                                <span class="nhg-flabel"><?php echo esc_html(sprintf(/* translators: %s: PHP version such as 8.2 */ __('Command (matches this site’s PHP %s)', 'niva-guard'), $cr['php'])); ?></span>
                                <textarea id="nhg-cron-cmd" class="nhg-cronline" data-cmd="<?php echo esc_attr($cr['command']); ?>" readonly rows="3" dir="ltr"><?php echo esc_textarea($cr['schedule'] . ' ' . $cr['command']); ?></textarea>
                                <p class="nhg-fhint"><?php esc_html_e('This is the full line (schedule + command), ready for crontab. In the cPanel form, enter the schedule in the time fields and paste only the part after it into the Command field.', 'niva-guard'); ?></p>
                                <p><button type="button" class="button nhg-copy" data-target="nhg-cron-cmd"><?php esc_html_e('Copy', 'niva-guard'); ?></button> <button type="button" class="button nhg-copycmd" data-target="nhg-cron-cmd"><?php esc_html_e('Copy command only', 'niva-guard'); ?></button></p>
                                <details class="nhg-details" open>
                                    <summary><?php esc_html_e('Other panels and alternative command', 'niva-guard'); ?></summary>
                                    <?php foreach ($cr['variants'] as $vi => $v) : ?>
                                        <span class="nhg-flabel" style="margin-top:10px"><?php echo esc_html($v[0]); ?></span>
                                        <textarea id="nhg-cron-v<?php echo (int) $vi; ?>" class="nhg-cronline" data-cmd="<?php echo esc_attr($v[1]); ?>" readonly rows="2" dir="ltr"><?php echo esc_textarea($cr['schedule'] . ' ' . $v[1]); ?></textarea>
                                        <p><button type="button" class="button nhg-copy" data-target="nhg-cron-v<?php echo (int) $vi; ?>"><?php esc_html_e('Copy', 'niva-guard'); ?></button></p>
                                    <?php endforeach; ?>
                                    <p class="nhg-fhint"><?php esc_html_e('If wp-config.php cannot be changed automatically, add this line above “That’s all, stop editing!”:', 'niva-guard'); ?></p>
                                    <textarea id="nhg-cron-cfg" readonly rows="1" dir="ltr">define( 'DISABLE_WP_CRON', true );</textarea>
                                    <p><button type="button" class="button nhg-copy" data-target="nhg-cron-cfg"><?php esc_html_e('Copy', 'niva-guard'); ?></button></p>
                                </details>
                            </div>
                        </div>
                    </div>
                </section>
                <!-- Auto circuit breaker -->
                <section class="nhg-panel" data-panel="brk">
                    <?php $brk = NIVAWPG_Settings::breaker(); $now = time(); ?>
                    <div class="nhg-grid nhg-grid-deep">
                        <div class="nhg-card">
                            <div class="nhg-head"><div><h2><?php esc_html_e('Auto circuit breaker', 'niva-guard'); ?></h2><p><?php esc_html_e('Some servers sometimes cannot reach external services and wait 5 to 15 seconds every time. With this option, a host that keeps failing or is slow in a row is skipped for a while without waiting.', 'niva-guard'); ?></p></div></div>
                            <div class="nhg-body">
                                <div class="nhg-opts">
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[breaker]" value="1" <?php checked($s['breaker'], 1); ?>><span></span></span><div><b><?php esc_html_e('Enable', 'niva-guard'); ?></b><small><?php esc_html_e('Off by default. “License” addresses are always exempt.', 'niva-guard'); ?></small></div></label>
                                </div>
                                <div class="nhg-field" style="margin-top:14px">
                                    <span class="nhg-flabel"><?php esc_html_e('How long a failing host is skipped (hours)', 'niva-guard'); ?></span>
                                    <input type="number" name="nhg[breaker_hours]" min="1" max="72" value="<?php echo (int) $s['breaker_hours']; ?>" class="small-text">
                                </div>
                                <div class="nhg-field" style="margin-top:12px">
                                    <span class="nhg-flabel"><?php esc_html_e('Consecutive failures before it activates', 'niva-guard'); ?></span>
                                    <input type="number" name="nhg[breaker_fails]" min="1" max="5" value="<?php echo (int) $s['breaker_fails']; ?>" class="small-text">
                                </div>
                                <div class="nhg-field" style="margin-top:12px">
                                    <span class="nhg-flabel"><?php esc_html_e('A response slower than this (seconds) also counts as a failure', 'niva-guard'); ?></span>
                                    <input type="number" name="nhg[breaker_slow]" min="2" max="30" value="<?php echo (int) $s['breaker_slow']; ?>" class="small-text">
                                </div>
                                <div class="nhg-field" style="margin-top:12px">
                                    <span class="nhg-flabel"><?php esc_html_e('Exempt hosts (one per line)', 'niva-guard'); ?></span>
                                    <textarea name="nhg[breaker_off_text]" rows="4" dir="ltr" placeholder="example.com"><?php echo esc_textarea(implode("\n", array_diff((array) $s['breaker_off'], array_keys($brk)))); ?></textarea>
                                    <small class="nhg-fhint"><?php esc_html_e('These hosts (and their subdomains) are never skipped, even if they are slow or failing. You can also exempt hosts from the table beside this one.', 'niva-guard'); ?></small>
                                </div>                            </div>
                        </div>

                        <div class="nhg-card">
                            <div class="nhg-head"><div><h2><?php esc_html_e('Hosts being watched', 'niva-guard'); ?></h2><p><?php esc_html_e('Only failures recorded since this option was switched on are shown. After the skip period ends it tries once more; if the host is healthy it is removed from the list.', 'niva-guard'); ?></p></div></div>
                            <div class="nhg-body">
                                <?php if (!$brk) : ?>
                                    <div class="nhg-note" style="background:#e8f7ee;color:#0b5b2e"><?php esc_html_e('No host has been recorded yet.', 'niva-guard'); ?></div>
                                <?php else : ?>
                                    <table class="nhg-tbl">
                                        <thead><tr><th><?php esc_html_e('Host', 'niva-guard'); ?></th><th><?php esc_html_e('Status', 'niva-guard'); ?></th><th><?php esc_html_e('Skip ends', 'niva-guard'); ?></th><th><?php esc_html_e('Times activated', 'niva-guard'); ?></th><th><?php esc_html_e('Last error', 'niva-guard'); ?></th><th><?php esc_html_e('Exempt from the breaker', 'niva-guard'); ?></th></tr></thead>
                                        <tbody>
                                        <?php foreach ($brk as $h => $e) : $on = (int) $e['until'] > $now; ?>
                                            <tr>
                                                <td dir="ltr" class="nhg-slug"><b><?php echo esc_html($h); ?></b></td>
                                                <td><?php if ($on) : ?><span class="nhg-tag own"><?php esc_html_e('Skipped', 'niva-guard'); ?></span><?php elseif ((int) $e['fails'] > 0) : ?><span class="nhg-tag lib"><?php echo esc_html(sprintf(/* translators: %s: number of consecutive failures so far */ __('Counting (%s)', 'niva-guard'), self::digits((string) $e['fails']))); ?></span><?php else : ?><span class="nhg-tag lib"><?php esc_html_e('Waiting for next try', 'niva-guard'); ?></span><?php endif; ?></td>
                                                <td><?php echo $on ? esc_html(self::digits(wp_date('Y/m/d H:i', (int) $e['until']))) : '—'; ?></td>
                                                <td><?php echo esc_html(self::digits((string) (int) $e['trips'])); ?></td>
                                                <td dir="ltr" style="text-align:left;font-size:.85em"><?php echo esc_html((string) $e['err']); ?></td>
                                                <td><label class="nhg-sw bad"><input type="checkbox" name="nhg[breaker_off][]" value="<?php echo esc_attr($h); ?>" <?php checked(in_array($h, (array) $s['breaker_off'], true)); ?>><span></span></label></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <p><button type="submit" form="nhg-brkform" class="button"><?php esc_html_e('Release all (clear the list)', 'niva-guard'); ?></button></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Deep tracking -->
                <section class="nhg-panel" data-panel="deep">
                    <?php $scan = NIVAWPG_Scan::get(); $kind_lbl = ['curl' => 'curl', 'sock' => 'socket', 'fopen' => 'file_get_contents / fopen']; ?>
                    <div class="nhg-grid nhg-grid-deep">
                        <div class="nhg-card">
                            <div class="nhg-head"><div><h2><?php esc_html_e('1) Intercept direct PHP connections', 'niva-guard'); ?></h2><p><?php echo wp_kses(__('Connections that bypass the WordPress API and go through <code>file_get_contents(\'http…\')</code> or <code>fopen</code> are also recorded in the same table and log (tag "PHP-GET"), and the blocking rules apply to them too.', 'niva-guard'), ['code' => [], 'b' => []]); ?></p></div></div>
                            <div class="nhg-body">
                                <div class="nhg-opts">
                                    <label class="nhg-opt"><span class="nhg-sw"><input type="checkbox" name="nhg[deep]" value="1" <?php checked($s['deep'], 1); ?>><span></span></span><div><b><?php esc_html_e('Enable deep tracking', 'niva-guard'); ?></b><small><?php esc_html_e('Off by default. Try it on a test site first.', 'niva-guard'); ?></small></div></label>
                                </div>
                                <div class="nhg-note"><?php echo wp_kses(__('Limits: <code>curl_exec</code> and <code>fsockopen</code> cannot be intercepted live in plain PHP (see section 2). Also, if a plugin reads <code>$http_response_header</code> or <code>get_headers()</code> directly, it may not receive the header data while this option is on; if anything breaks, turn this switch off. Requests made by WordPress core itself (without a calling plugin) are left untouched.', 'niva-guard'), ['code' => [], 'b' => []]); ?> <?php if (!ini_get('allow_url_fopen')) : ?><b><?php esc_html_e('allow_url_fopen is disabled on this host; this section has no effect.', 'niva-guard'); ?></b><?php endif; ?></div>
                            </div>
                        </div>

                        <div class="nhg-card">
                            <div class="nhg-head"><div><h2><?php esc_html_e('2) Plugin code scan (static)', 'niva-guard'); ?></h2><p><?php echo wp_kses(__('Reads the PHP files of all plugins and themes and lists where <code>curl</code>, <code>fsockopen</code> or <code>file_get_contents(\'http…\')</code> is written directly. It does not run or block anything; it only tells you which plugin has the “ability to connect silently”.', 'niva-guard'), ['code' => [], 'b' => []]); ?></p></div></div>
                            <div class="nhg-body">
                                <p><button type="submit" form="nhg-scanform" class="button button-primary"><?php esc_html_e('Scan plugin code', 'niva-guard'); ?></button>
                                <?php if (!empty($scan['time'])) : ?><span class="nhg-m-start" style="color:var(--mut)"><?php echo esc_html(sprintf(/* translators: 1: date and time, 2: number of files */ __('Last scan: %1$s · %2$s files', 'niva-guard'), self::digits(wp_date('Y/m/d H:i', (int) $scan['time'])), self::digits((string) $scan['files']))); ?><?php echo !empty($scan['partial']) ? ' ' . esc_html(__('· partial (time ran out)', 'niva-guard')) : ''; ?></span><?php endif; ?></p>
                                <?php if (!empty($scan['time']) && !$scan['plugins']) : ?>
                                    <div class="nhg-note" style="background:#e8f7ee;color:#0b5b2e"><?php esc_html_e('Nothing found.', 'niva-guard'); ?></div>
                                <?php elseif ($scan['plugins']) : ?>
                                    <table class="nhg-tbl">
                                        <thead><tr><th><?php esc_html_e('Plugin / theme', 'niva-guard'); ?></th><th><?php esc_html_e('Plugin\'s own code', 'niva-guard'); ?></th><th><?php esc_html_e('Library (vendor)', 'niva-guard'); ?></th><th><?php esc_html_e('Type', 'niva-guard'); ?></th><th><?php esc_html_e('Fixed destinations in code', 'niva-guard'); ?></th></tr></thead>
                                        <tbody>
                                        <?php foreach ($scan['plugins'] as $slug => $e) :
                                            $all_h = [];
                                            foreach ((array) ($e['files'] ?? []) as $fl) { foreach ((array) $fl['h'] as $h) { $all_h[$h] = true; } }
                                            ?>
                                            <tr class="nhg-acc" tabindex="0" role="button" aria-expanded="false">
                                                <td dir="ltr" class="nhg-slug"><span class="nhg-chev">‹</span> <b><?php echo esc_html($slug); ?></b></td>
                                                <td><?php echo esc_html(self::digits((string) $e['own_n'])); ?></td>
                                                <td style="color:var(--mut)"><?php echo esc_html(self::digits((string) $e['lib_n'])); ?></td>
                                                <td><?php echo esc_html(implode(wp_get_list_item_separator(), array_map(static fn($k) => $kind_lbl[$k] ?? $k, array_keys($e['kinds'])))); ?></td>
                                                <td dir="ltr" style="text-align:left;font-size:.85em"><?php echo $all_h ? esc_html(implode('  ', array_slice(array_keys($all_h), 0, 3))) . (count($all_h) > 3 ? ' …' : '') : '<span style="color:var(--mut)">—</span>'; ?></td>
                                            </tr>
                                            <tr class="nhg-acc-body" hidden>
                                                <td colspan="5">
                                                    <?php if (empty($e['files'])) : ?>
                                                        <p style="margin:0;color:var(--mut)"><?php esc_html_e('File details were not saved in this scan; scan again.', 'niva-guard'); ?></p>
                                                    <?php else : ?>
                                                        <table class="nhg-sub">
                                                            <thead><tr><th><?php esc_html_e('File', 'niva-guard'); ?></th><th><?php esc_html_e('Type', 'niva-guard'); ?></th><th><?php esc_html_e('Line', 'niva-guard'); ?></th><th><?php esc_html_e('Site / fixed IP inside the file', 'niva-guard'); ?></th></tr></thead>
                                                            <tbody>
                                                            <?php foreach ($e['files'] as $fl) : ?>
                                                                <tr>
                                                                    <td dir="ltr" style="text-align:left"><?php echo esc_html($fl['f']); ?> <span class="nhg-tag <?php echo !empty($fl['lib']) ? 'lib' : 'own'; ?>"><?php echo !empty($fl['lib']) ? esc_html__('Library', 'niva-guard') : esc_html__('Plugin code', 'niva-guard'); ?></span></td>
                                                                    <td><?php echo esc_html(implode(wp_get_list_item_separator(), array_map(static fn($k) => $kind_lbl[$k] ?? $k, array_keys((array) $fl['k'])))); ?></td>
                                                                    <td dir="ltr" style="text-align:left"><?php $ls = []; foreach ((array) $fl['k'] as $arr) { $ls = array_merge($ls, (array) $arr); } sort($ls); echo esc_html(implode(', ', array_slice(array_unique($ls), 0, 6))); ?></td>
                                                                    <td dir="ltr" style="text-align:left"><?php echo $fl['h'] ? esc_html(implode('   ', $fl['h'])) : '<span style="color:var(--mut)">' . esc_html(__('No fixed address — the destination comes from settings or input at run time', 'niva-guard')) . '</span>'; ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <div class="nhg-note"><?php esc_html_e('“Plugin\'s own code” means outside the vendor folder. A high count inside libraries is usually harmless. False positives are possible too (for example curl for a local address); to be sure, follow that plugin in the “seen” table.', 'niva-guard'); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Manual -->
                <section class="nhg-panel" data-panel="manual">
                    <div class="nhg-card">
                        <div class="nhg-head"><div><h2><?php esc_html_e('Manual blocking', 'niva-guard'); ?></h2><p><?php echo wp_kses(__('One address per line in the form <code>domain/path</code> (prefix); for example <code>example.com/ads/</code>', 'niva-guard'), ['code' => [], 'b' => []]); ?></p></div></div>
                        <div class="nhg-body">
                            <?php
                            $manual = array_values(array_filter((array) $s['custom'], static function ($l) use ($disc) {
                                foreach ($disc as $e) {
                                    if ($e['hp'] === $l) {
                                        return false; // These are managed by the "Seen on your site" table
                                    }
                                }

                                return true;
                            }));
                            ?>
                            <textarea name="nhg[custom]" rows="8" dir="ltr" placeholder="example.com/ads/"><?php echo esc_textarea(implode("\n", $manual)); ?></textarea>
                        </div>
                    </div>
                </section>

                <!-- Save bar -->
                <div class="nhg-save">
                    <?php submit_button(__('Save settings', 'niva-guard'), 'primary', 'submit', false); ?>
                    <span class="nhg-dirty" id="nhg-dirty"><?php esc_html_e('No changes made', 'niva-guard'); ?></span>
                    <span style="margin-inline-start:auto"></span>
                    <a class="button" href="#" id="nhg-clearlink"><?php esc_html_e('Clear the connection report', 'niva-guard'); ?></a>
                </div>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="nhg-brkform" style="display:none">
                <input type="hidden" name="action" value="nivawpg_brk_reset">
                <?php wp_nonce_field('nivawpg_brk_reset'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="nhg-scanform" style="display:none">
                <input type="hidden" name="action" value="nivawpg_scan">
                <?php wp_nonce_field('nivawpg_scan'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="nhg-clearform" style="display:none">
                <input type="hidden" name="action" value="nivawpg_clear">
                <?php wp_nonce_field('nivawpg_clear'); ?>
            </form>
        </div>

        <?php
    }
}
