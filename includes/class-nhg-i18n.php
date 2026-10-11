<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Translations: languages/niva-guard-{locale}.mo
 * The source strings are English; if there is no file for the site language, English is shown.
 */
final class NIVAWPG_I18n {

    public const DOMAIN = 'niva-guard';

    /** Language => fallback file (for example es_MX, de_AT, fa_AF …). */
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
            WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . $locale . '.mo', // Translation provided by the site owner
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
        // No file (for example en_US or an unsupported language): the English source text is shown
    }
}