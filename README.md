# NIVA WP Guard

See and control every outgoing connection of your WordPress plugins and themes. Log each connection with its timing, block ads and notices, cache license checks, switch off WP-Cron in favour of a real server cron, and answer scanner probes with a fast static 404.

[فارسی](#فارسی) · License: GPLv2 or later · Requires WordPress 6.0+, PHP 7.4+

## Features

**Connections**
- Logs every outgoing HTTP request made through the WordPress HTTP API: caller (plugin or theme), address, context (site, admin, cron, AJAX, REST) and time taken. Slow requests are highlighted.
- A built-in list of known connections (ads, news feeds, update and license checks, tracking) with one switch each. A "relevant to this site" tab lists only those that match the active plugins and themes.
- Block a connection completely, or **cache** a license response for a few hours instead of cutting it off.
- Take a whole plugin offline (everything except wordpress.org is closed).
- Automatic circuit breaker: a host that keeps failing or answering slowly is skipped for a while. License addresses and hosts you choose are exempt.
- Optional deep tracking of direct PHP connections (`file_get_contents( 'http…' )`, `fopen`) and a static scan of plugin code for `curl` and `fsockopen`.

**Dashboard and admin clean-up**
- Remove dashboard widgets, hide admin menu items, hide promotional notices by keyword.

**Server tools** (each optional, off until you enable it)
- Heartbeat control per area: dashboard, post editor, other admin pages, front-end.
- Fast 404 for scanner probes (`.env`, backups, fake PHP files) without loading WordPress, with a static `404.html`, an `.htaccess` block (backup and automatic rollback on server error) and an Nginx snippet.
- WordPress cron: add `DISABLE_WP_CRON` to `wp-config.php` and get a ready cron line for cPanel, DirectAdmin, Plesk, WP-CLI, wget or curl, matched to your PHP version and interval (1 minute to 6 hours). Shows the last successful cron run, how it was started and the next scheduled task.

The settings page is available in English, Persian, Arabic, Turkish, Russian, German, French, Spanish and Chinese, and adapts to left-to-right and right-to-left languages.

## Installation

1. Download the latest release zip (or clone this repository into `wp-content/plugins/niva-wp-guard/`).
2. Activate it in **Plugins**.
3. Open **Settings → NIVA WP Guard**.

Nothing is written outside the plugin folder until you enable an option that needs it. The plugin makes no external connections of its own.

## Files outside the plugin folder (only when you enable them)

- `wp-content/mu-plugins/niva-wp-guard-loader.php`, `niva-wp-guard-heartbeat.php`, `niva-wp-guard-fast-404.php`
- `404.html` and an `.htaccess` block between `# BEGIN NIVA WP Guard` and `# END NIVA WP Guard`
- one marked `DISABLE_WP_CRON` line in `wp-config.php`

Everything is removed when you deactivate or delete the plugin.

## Author

Ali Mohajer · [GitHub](https://github.com/Ali-MR-23) · [WordPress.org profile](https://profiles.wordpress.org/alimohajer/)

---

## فارسی

**NIVA WP Guard** همه‌ی اتصال‌های بیرونی افزونه‌ها و قالب‌های وردپرس را نشان می‌دهد و کنترل می‌کند: ثبت هر اتصال با زمان آن، بستن تبلیغ و اعلان، کش کردن بررسی لایسنس، خاموش کردن WP-Cron و جایگزینی با کرون واقعی سرور، و پاسخ سریع ۴۰۴ به اسکنرها.

### قابلیت‌ها
- لاگ هر اتصال با نام افزونه یا قالب، آدرس، محل اجرا و مدت زمان؛ اتصال‌های کند مشخص می‌شوند.
- فهرست آماده‌ی اتصال‌های شناخته‌شده (تبلیغ، خبر، بررسی آپدیت و لایسنس، ردیابی) با یک کلید برای هر مورد، و تب «مرتبط با این سایت».
- مسدود کردن کامل یا **کش** پاسخ لایسنس به‌جای قطع کردن آن.
- آفلاین کردن یک افزونه، و قطع‌کننده‌ی خودکار برای هاستی که مدام خطا می‌دهد.
- ردیابی عمیق اتصال‌های مستقیم PHP و اسکن کد افزونه‌ها.
- حذف ویجت‌های پیشخوان، پنهان کردن آیتم‌های منو و اعلان‌های تبلیغاتی.
- کنترل Heartbeat، ۴۰۴ سریع (با `404.html` و بلوک `.htaccess`)، و کنترل کرون وردپرس با دستور آماده‌ی cPanel و نمایش آخرین اجرای موفق کرون.
- رابط به ۹ زبان، با پشتیبانی کامل راست‌به‌چپ و چپ‌به‌راست.

### نصب
افزونه را از بخش Releases دانلود و نصب کنید، فعالش کنید و از **تنظیمات ← NIVA WP Guard** استفاده کنید. تا وقتی گزینه‌ای را فعال نکنید چیزی بیرون از پوشه‌ی افزونه نوشته نمی‌شود، و افزونه خودش به هیچ سرویس بیرونی وصل نمی‌شود.

### مجوز
GPLv2 یا بالاتر.
