=== NIVA Guard ===
Contributors: alimohajer
Tags: performance, http, notices, heartbeat, 404
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.6.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See and control every outgoing connection of your plugins and themes: log with timing, block ads and notices, cache licenses, fast 404.

== Description ==

NIVA Guard shows you which plugin or theme connects to which address, how much of your site's time every connection costs, and lets you close the ones you do not want — one switch per connection.

**Connections**

* Logs every outgoing HTTP connection made through the WordPress HTTP API, with the caller (plugin or theme), the address, the context (site, admin, cron, AJAX, REST) and the time it took. Slow connections are highlighted.
* A built-in list of known connections (advertising, news feeds, update checks, license checks, tracking) with a separate switch for each. A "relevant to this site" tab lists only the ones that match the plugins and themes that are active on the site.
* Block a connection completely, or **cache** a license response for a few hours instead of cutting it off.
* Take a whole plugin offline (everything except wordpress.org is closed).
* Automatic circuit breaker: a host that fails or answers slowly several times in a row is skipped for a while, so a blocked or unreachable server no longer adds seconds to every page. License addresses and hosts you choose are exempt.
* Optional deep tracking of direct PHP connections (`file_get_contents( 'http…' )`, `fopen`) and a static scan of plugin code for `curl` and `fsockopen`.

**Dashboard and admin clean-up**

* Remove dashboard widgets and hide admin menu items of your choice, hide promotional notices by keyword.

**Server tools (each one is optional and off until you enable it)**

* Heartbeat control: slow down or turn off the WordPress Heartbeat separately for the dashboard, the post editor, other admin pages and the front-end.
* Fast 404: answers scanner probes (`.env`, backups, fake PHP files) with a light static 404 page instead of loading WordPress.
* WordPress cron: turn off the built-in wp-cron (adds `DISABLE_WP_CRON` to `wp-config.php`) and get a ready cron command for cPanel, matched to your PHP version and a sensible interval.

The settings page is written in English, is fully translatable (translations are managed on translate.wordpress.org) and adapts to left-to-right and right-to-left languages.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/niva-guard/` or install it from the Plugins screen.
2. Activate it. Nothing is written outside the plugin folder at this point.
3. Open **Settings → NIVA Guard**, review the options and save.

== Frequently Asked Questions ==

= Does the plugin send any data to another server? =

No. It does not connect to any external service. The only request it makes is to your own site (home URL), once, after it edits `.htaccess` or `wp-config.php`, to be sure the site still answers; if the site returns a server error the edit is rolled back. The connection log is stored only on your own site (database and a text file in the uploads folder).

= Which files does it create outside its own folder? =

Only if you turn the related option on and save the settings:

* `wp-content/mu-plugins/niva-guard-loader.php` — "Load before other plugins", so no connection escapes the logger.
* `wp-content/mu-plugins/niva-guard-heartbeat.php`, `niva-guard-fast-404.php` — the server tools.
* `404.html` in the site root and a block between `# BEGIN NIVA WP Guard` and `# END NIVA WP Guard` in `.htaccess` — Fast 404. A backup of `.htaccess` is saved first and restored automatically if the site returns a server error afterwards.
* `wp-config.php` — only if you turn on "Disable the built-in WordPress cron": one marked line `define( 'DISABLE_WP_CRON', true );` is added (and removed again when you turn the option off or deactivate the plugin). If the site returns a server error afterwards, the file is restored automatically.

The log folder `wp-content/uploads/niva-guard/` is created when the first connection is logged. If WordPress cannot write these files, the settings page shows download buttons and manual instructions instead.

Everything is removed when you deactivate or delete the plugin.

= Can blocking a connection break something? =

Blocking "license" or "plugin function" connections can lock a theme or stop SMS and orders from working. For licenses use "cache" instead of "block". The settings page warns you before you block these.

= Does it work on Nginx? =

The logger, circuit breaker and Heartbeat control work everywhere. Fast 404 cannot edit `.htaccess` on Nginx; the settings page gives you the equivalent Nginx snippet instead.

= Where is the human-readable source? =

All PHP, CSS and JavaScript files in the plugin are unminified and readable.

== Changelog ==

= 2.6.4 =
* File locations are now taken from WordPress functions (uploads folder, mu-plugins folder, site root, plugin folder) instead of constants and fixed names.
* The 404 page template is a separate file (templates/404.html).
* The log folder is now uploads/niva-guard; the previous folder and log file are moved automatically.
* New rules for WP Rocket.

= 2.6.3 =
* The static 404 page no longer contains a script; the "go back" button was replaced by the plain link to the home page.

= 2.6.2 =
* Translation files are no longer bundled; translations are delivered through translate.wordpress.org.

= 2.6.1 =
* Notice keywords are now passed to the inline script with the JSON_HEX_* flags, so no keyword can close the script tag.

= 2.6.0 =
* Renamed to "NIVA Guard" (folder and text domain "niva-guard"). Settings, log and menu choices are kept automatically; deactivate the old "niva-wp-guard" copy if it is still active.
* The mu-plugin files are now named "niva-guard-*.php"; the old files are removed automatically.
* Plugin Check clean-up: sanitised form input, no raw third-party addresses in the code.

= 2.5.1 =
* The "hide menu items" list now includes menus other plugins add very late (for example WP-Parsidate).
* Cron card: full crontab line with the schedule, "Copy command only" for the cPanel form, and more command variants (cPanel, CloudLinux, DirectAdmin, Plesk, WP-CLI, wget, curl).
* Intervals up to 6 hours for the server cron job.
* Neutral wording in the circuit breaker description; vip-themes.com is shown as "VIP Theme".
* Packaging: the installable zip no longer contains development files.

= 2.5.0 =
* New: switch off the built-in WordPress cron from the "Server and 404" tab and copy a ready cron job command for cPanel (schedule and PHP path matched to your site).
* New: the cron card shows the last successful (and last failed) cron run, how it was started (server command or web request) and the next scheduled task.
* Fix: admin menu items added very late by other plugins (for example WP-Parsidate) now appear in the "hide menu items" list and can be hidden.

= 2.4.1 =
* The plugin no longer has a shortlink option and does not touch WordPress shortlinks. The Fast 404 file only carries the rule that keeps `?p=ID` links working while Fast 404 is on; the old separate shortlink file is removed automatically.

= 2.4.0 =
* Unique `nivawpg` prefix for all PHP names, options and actions; settings of earlier versions are migrated automatically.
* Nothing is created outside the plugin folder until you ask for it in the settings.
* Files outside the plugin folder are written through the WordPress filesystem API.
* Admin styles and scripts are enqueued instead of printed inline.
* Readme, license header and coding-standard fixes.

= 2.3.1 =
* Fast 404 no longer interferes with redirects (old slugs, guessed URLs, SEO redirect plugins, sitemaps) and always keeps `?p=ID` shortlinks working.

= 2.3.0 =
* New "Server and 404" tab: Heartbeat control and Fast 404.

= 2.2.1 =
* "Relevant to this site" / "All connections" sub-tabs.

= 2.1.0 =
* Nine languages and full right-to-left / left-to-right support.

= 2.0.0 =
* Renamed from NIVA HTTP Guard. Settings are migrated automatically.

== Upgrade Notice ==

= 2.6.4 =
The log folder is renamed to niva-guard; your log and settings are kept. If you use the early loader or Fast 404, they are refreshed automatically.

= 2.6.3 =
The generated 404.html is simplified (no script). Use "Regenerate 404.html" to refresh an existing file.

= 2.6.2 =
The interface is shown in English until a translation for your language is available on translate.wordpress.org. Settings are kept.

= 2.6.1 =
Security hardening of the notice keyword filter. Settings are kept.

= 2.6.0 =
The plugin folder is now niva-guard. Deactivate the old niva-wp-guard copy first; your settings are shared.

= 2.5.1 =
Small fixes and a more complete cron card. Settings are kept.

= 2.4.1 =
Internal names changed to a unique prefix. Your settings are migrated automatically; deactivate any old "NIVA HTTP Guard" copy first.
