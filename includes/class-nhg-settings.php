<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ذخیره و خواندن تنظیمات (یک گزینه‌ی واحد) و گزارش اتصال‌های دیده‌شده.
 */
class NIVAWPG_Settings {

    public const OPTION = 'nivawpg_settings';
    public const SEEN = 'nivawpg_seen';
    public const WIDGETS = 'nivawpg_widgets';
    public const MENUS = 'nivawpg_menus';
    public const BREAKER = 'nivawpg_breaker';

    /** منوهایی که هرگز قابل مخفی شدن نیستند تا راه رسیدن به همین تنظیمات بسته نشود */
    public const PROTECTED_MENUS = ['index.php', 'options-general.php', 'plugins.php'];

    private static ?array $cache = null;

    public static function defaults(): array {
        return [
            'enabled'       => 1,   // کلید اصلی
            'log'           => 1,   // ثبت اتصال‌ها
            'block'         => [],  // شناسه‌ی قانون شناخته‌شده ⇒ 1  (مسدود)
            'cache'         => [],  // شناسه‌ی قانون شناخته‌شده ⇒ 1  (کش پاسخ)
            'custom'        => [],  // آدرس‌های دلخواه (host/path) برای مسدود شدن
            'offline'       => [],  // اسلاگ افزونه‌هایی که کل اینترنتشان بسته است
            'ui_core_news'  => 0,   // ویجت‌های خبری هسته‌ی وردپرس
            'ui_elementor'  => 0,   // ویجت اخبار المنتور
            'ui_widget_dirs' => [], // حذف ویجت‌های داشبوردِ کدهای این افزونه‌ها
            'ui_widgets'    => [],  // شناسه‌ی ویجت‌های داشبوردی که تیک حذف خورده‌اند (از فهرست شناسایی‌شده)
            'ui_notices'    => 0,   // پنهان کردن اعلان‌های تبلیغاتی با کلیدواژه
            'ui_keywords'   => '',  // کلیدواژه‌های اعلان تبلیغاتی (هر خط یکی)
            'ui_yoast_feed' => 0,   // پنهان کردن فید ویجت Yoast
            'ui_pwsms'      => 0,   // خاموش کردن تبلیغ‌های افزونه‌ی پیامک ووکامرس فارسی
            'ui_parsidate'  => 0,   // پارسی‌دیت: حذف ویجت داشبورد (بنر حامیان و فید) و بستن اتصال‌هایش
            'ui_menus'      => [],  // اسلاگ منوهای سمت راست ادمین که فقط مخفی می‌شوند (نه حذف)
            'ui_jetpack'    => 0,   // حالت آفلاین رسمی Jetpack (بدون اتصال به WordPress.com)
            'deep'          => 0,   // ردیابی عمیق: اتصال‌های مستقیم PHP (file_get_contents / fopen با http)
            'breaker'       => 0,   // قطع‌کننده‌ی خودکار: هاستی که پشت‌سرهم خطا یا کندی دارد موقتاً بدون معطلی رد می‌شود
            'breaker_hours' => 6,   // مدت رد شدن هاست خراب
            'breaker_fails' => 2,   // چند خطای پشت‌سرهم تا فعال شدن
            'breaker_off'   => [],  // هاست‌هایی که قطع‌کننده هرگز رد نمی‌کند (معاف)
            'breaker_slow'  => 5,   // پاسخ کندتر از این (ثانیه) هم خطا حساب می‌شود
            'loader_on'     => 0,   // لودر mu-plugin (فقط با اجازه‌ی کاربر ساخته می‌شود)
            'files_ok'      => 0,   // کاربر تنظیمات را ذخیره کرده؛ از این به بعد ترمیم خودکارِ فایل‌های فعال‌شده مجاز است
            'hb_on'         => 0,   // کنترل Heartbeat (mu-plugin جدا)
            'hb_interval'   => 300, // فاصله‌ی حالت «کند» به ثانیه
            'hb_dash'       => 'interval', // default | interval | off
            'hb_editor'     => 'interval',
            'hb_admin'      => 'interval',
            'hb_front'      => 'off',
            'f404_on'       => 0,   // ۴۰۴ سریع (mu-plugin + 404.html + .htaccess)
            'f404_strict'   => 0,   // بستن فایل‌های اسکریپت موجود بیرون از هسته/افزونه/قالب
            'cron_off'      => 0,   // DISABLE_WP_CRON در wp-config.php (کرون واقعی سرور جایگزین می‌شود)
            'cron_every'    => 5,   // فاصله‌ی پیشنهادیِ کرون سرور (دقیقه)
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

            // نصب‌های قدیمی‌تر که لودر یا فایل‌های mu از قبل دارند: همان وضعیت حفظ می‌شود
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

    /** کپی یک‌باره‌ی تنظیمات و گزارش‌ها از نام‌های قبلی گزینه‌ها: nwg_* (نسخه‌های ۲.x) یا nhg_* (افزونه‌ی NIVA HTTP Guard) */
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

    /** @return array<string,array{title:string,source:string,context:string}> ویجت‌هایی که در داشبورد همین سایت دیده شده‌اند */
    public static function widgets(): array {
        $w = get_option(self::WIDGETS, []);

        return is_array($w) ? $w : [];
    }

    /** @return array<string,string> منوهای سطح اول ادمین که دیده شده‌اند: اسلاگ ⇒ عنوان */
    public static function menus(): array {
        $m = get_option(self::MENUS, []);

        return is_array($m) ? $m : [];
    }

    /** @return array<string,array{fails:int,until:int,trips:int,t:int,err:string}> وضعیت قطع‌کننده به‌ازای هر هاست */
    public static function breaker(): array {
        $b = get_option(self::BREAKER, []);

        return is_array($b) ? $b : [];
    }

    /** @return array<string,array> گزارش اتصال‌های دیده‌شده؛ کلید = md5(plugin|host|path) */
    public static function seen(): array {
        $s = get_option(self::SEEN, []);

        return is_array($s) ? $s : [];
    }
}
