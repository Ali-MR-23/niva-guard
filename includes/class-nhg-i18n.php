<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * چندزبانه: فایل‌های languages/niva-wp-guard-{locale}.mo
 * متن‌های اصلی کد انگلیسی‌اند؛ اگر برای زبان سایت فایلی نباشد، انگلیسی نمایش داده می‌شود.
 */
final class NIVAWPG_I18n {

    public const DOMAIN = 'niva-wp-guard';

    /** زبان ⇒ فایل پیش‌فرض (برای مثلاً es_MX، de_AT، fa_AF و ...) */
    private const FALLBACK = [
        'fa' => 'fa_IR',
        'ar' => 'ar',
        'tr' => 'tr_TR',
        'ru' => 'ru_RU',
        'de' => 'de_DE',
        'fr' => 'fr_FR',
        'es' => 'es_ES',
        'zh' => 'zh_CN',
    ];

    public static function boot(): void {
        add_action('init', [self::class, 'load'], 0);
    }

    public static function load(): void {
        $locale = (string) determine_locale();
        $lang = strtolower(substr($locale, 0, 2));
        $candidates = [
            WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . $locale . '.mo', // ترجمه‌ی دستی کاربر
            NIVAWPG_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo',
        ];

        if (isset(self::FALLBACK[$lang])) {
            $candidates[] = NIVAWPG_DIR . 'languages/' . self::DOMAIN . '-' . self::FALLBACK[$lang] . '.mo';
        }

        foreach ($candidates as $file) {
            if (is_readable($file)) {
                load_textdomain(self::DOMAIN, $file);

                return;
            }
        }
        // فایلی نیست (مثلاً en_US یا زبان پشتیبانی‌نشده): همان متن انگلیسی کد نمایش داده می‌شود
    }
}