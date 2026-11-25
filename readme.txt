=== NoLimit Migration ===
Contributors: nolimitmigration, rehmat727
Tags: migration, backup, restore, clone, move site
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Migrate, back up and restore WordPress with no size limit. Compressed backups, scheduled backups, import from URL, safe restores.

== Description ==

**NoLimit Migration** moves a complete WordPress site (database, media, themes and plugins) to a new domain or host, and keeps backups you can restore in one click. Everything in this list is free. There is no paid "unlimited" add-on.

= No size limit =

* **Upload backups of any size.** The file is sent in small pieces, so your host's `upload_max_filesize` and `post_max_size` do not matter.
* **Import from URL, server to server.** Paste a direct link to a backup, for example a transfer link from another site. The new site downloads it in resumable chunks; nothing goes through your computer.
* **Small backup files.** Text, code and the database are compressed (often 5–10× smaller); photos and videos are stored as they are, so no time is wasted on them.
* **Works on cheap shared hosting.** Every job runs in short, resumable steps, so 30-second PHP time limits are not a problem.

= Safer restores =

* **Your live site stays untouched until the very end.** The database is imported into temporary tables and switched in with one atomic rename. If anything fails, the site keeps working as before.
* **Automatic restore point.** Before a restore, the plugin can back up the current site, so you can undo.
* **Serialized data stays valid.** URLs and paths are replaced inside serialized PHP, JSON (Elementor, Gutenberg, page builders) and URL-encoded values, with exact string lengths. Values are never unserialized, so there is no object-injection risk.
* **Maintenance page for visitors** while a restore runs.
* Drop-ins from the old server (`object-cache.php`, `advanced-cache.php`, `db.php`) are skipped so they cannot break the new host.

= Migration made simple =

1. On the old site: **Backups → Transfer link**.
2. On the new site: install WordPress and this plugin.
3. On the new site: **Restore / Import → Import from URL**, paste, restore.

The site address, file paths and table prefix are changed automatically. Optional: change e-mail domains, and add your own find & replace rules.

= NoLimit Pro (optional) =

The free plugin is complete on its own. [NoLimit Pro](https://nolimitmigration.com/pricing) is a separate add-on for people who want more:

* Automatic cloud backups to Google Drive, Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces and other S3-compatible storage — and restore straight from the cloud.
* Password-encrypted backups (AES-256-GCM). The free plugin can always restore them with the password.
* Hourly and every-2/4/6-hours schedules.
* Slack, Discord, Telegram and webhook alerts.
* White-label for agencies.

Prefer someone else does it? We also offer [done-for-you migrations](https://nolimitmigration.com/services).

= Backups =

* One-click backups to a single `.nolimit` file.
* **Scheduled backups**: twice daily, daily, weekly or monthly, with retention (keep the newest N) and e-mail reports.
* Labels, "keep forever" lock, download, private expiring transfer links.
* Leave out what you do not need: media, inactive themes or plugins, cache folders, spam comments, post revisions, transients, chosen tables, files by path or wildcard, files over a size limit.
* Backups are stored in a folder that is locked against web access; downloads go through an authenticated, resumable endpoint.

= WP-CLI =

`
wp nolimit backup [--exclude=no_media,no_revisions] [--label="Before update"] [--drive]
wp nolimit restore <file> [--yes]
wp nolimit fetch <url>
wp nolimit link <file> [--hours=24]
wp nolimit list
`

Made by [NoLimit Migration](https://nolimitmigration.com). Need it done for you? We also [migrate sites for you](https://nolimitmigration.com/services).

== Installation ==

1. Upload the `nolimit-migration` folder to `/wp-content/plugins/`, or install it from **Plugins → Add New**.
2. Activate it.
3. Open **NoLimit Migration** in the admin menu.

== Frequently Asked Questions ==

= Is there really no file size limit? =

The plugin itself sets no limit. Uploads are split into pieces smaller than your host's limit, and link imports are downloaded in chunks. You still need enough free disk space for the backup file plus the restored site.

= Will I be logged out after a restore? =

Yes. The restored database contains the users of the backed-up site. Log in with a username and password from that site.

= What happens if a restore fails halfway? =

Files that were already copied stay, but the database switch only happens after all of it was imported successfully. A failed or cancelled restore before that point leaves your database as it was. If you kept the "Back up this site first" option on, you also have a restore point.

= Does it support multisite? =

Not yet. Single sites only.

= Where are backups stored? =

In `wp-content/nlwm-backups-<random>/` (the random part makes the folder unguessable, even on nginx servers that ignore .htaccess). The folder also has `.htaccess`, `web.config` and `index.php` protection. Deleting the plugin keeps your backup files; delete the folder by hand if you no longer need them.

== External services ==

This plugin talks to outside services only when you use a feature that needs them:

* **Import from URL**: downloads the backup from the address you enter.

No data is sent anywhere otherwise. There is no tracking.

== Screenshots ==

1. Create a backup with advanced exclusions and find & replace.
2. Restore from an upload of any size or from a link.
3. Backups list with download, transfer link, labels and keep-forever.
4. Scheduled backups with retention and e-mail reports.

== Changelog ==

= 1.0.0 =
* First release.
