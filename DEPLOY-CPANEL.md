# Deploying SaccoPro on cPanel

Plan for about 20 minutes. You need a cPanel account with PHP 8.1 or newer and MySQL or MariaDB (standard on modern cPanel hosting).

## 1. Build the upload package (on your computer)

```bash
php deploy/build.php
```

This creates `dist/saccopro-YYYYMMDD-HHMM.zip`. It contains everything the server needs and leaves out your local `.env`, logs, uploads, backups and git history.

## 2. Choose where it lives

| Option | Install into | Address |
|---|---|---|
| **Subdomain (recommended)** | Create `sacco.yourdomain.com` in cPanel → Domains. Its folder is e.g. `/home/USER/sacco.yourdomain.com` | `https://sacco.yourdomain.com` |
| Main domain | `public_html` | `https://yourdomain.com` |
| Sub-folder | `public_html/sacco` | `https://yourdomain.com/sacco` |

The app's private folders (`app/`, `database/`, `cron/`, `storage/`) and files such as `.env` are blocked from the web by the included `.htaccess` files, so all three options are safe.

## 3. Create the database

cPanel → **MySQL® Databases**:

1. Create a database, e.g. `sacco` (cPanel names it `USER_sacco`).
2. Create a user with a strong password, e.g. `USER_saccoadmin`.
3. **Add the user to the database** and tick **ALL PRIVILEGES**.

Keep the full names (with the `USER_` prefix) and the password for step 6.

## 4. Set the PHP version

cPanel → **Select PHP Version** (or **MultiPHP Manager**): choose **PHP 8.1, 8.2 or 8.3** for the domain.
Under *Extensions* make sure these are ticked: `pdo_mysql`, `mbstring`, `fileinfo`, `zlib`, `openssl`.

## 5. Upload and extract

cPanel → **File Manager** → open the folder from step 2 → **Upload** the zip → right-click it → **Extract** → delete the zip.
Turn on *Settings → Show Hidden Files* to see `.htaccess`, `.user.ini` and `.env.example`.

Folder permissions should be **755** (folders) and **644** (files). The `storage/` folders must be writable, which they are by default on cPanel.

## 6. Run the installer

Open `https://your-address/install.php`:

1. **Server check and database.** Every check should be green. Enter `localhost`, the database name, user and password from step 3, and your site address. The installer tests the connection and writes `.env` with production settings (`APP_DEBUG=false`).
   *If it cannot write the file*, it shows the contents: create `.env` in File Manager and paste them in.
2. **Administrator.** Enter the SACCO name and your admin login. Leave "demo data" **unticked** for a live SACCO.

Then lock the installer: edit `.env` and set

```
INSTALLER_ENABLED=false
```

## 7. Turn on HTTPS

cPanel → **SSL/TLS Status** → run **AutoSSL**, or install your certificate. Once `https://` works:

* in `.env` set `FORCE_HTTPS=true` and make `APP_URL` start with `https://`
* optionally uncomment the HTTPS block at the top of `.htaccess`

Session cookies become secure automatically over HTTPS. This also works behind Cloudflare.

## 8. Schedule the cron jobs

cPanel → **Cron Jobs** → *Add New Cron Job*. Use your real path, which is shown in File Manager's address bar:

| When | Command |
|---|---|
| Once a day, 00:05 | `/usr/local/bin/php /home/USER/sacco.yourdomain.com/cron/daily.php >/dev/null 2>&1` |
| Once a day, 01:30 | `/usr/local/bin/php /home/USER/sacco.yourdomain.com/cron/backup.php >/dev/null 2>&1` |

`daily.php` marks overdue installments and applies penalties. `backup.php` writes a compressed database copy to `storage/backups`.
If `/usr/local/bin/php` is a different version, ask your host for the CLI path of PHP 8.1+ (often `/opt/cpanel/ea-php82/root/usr/bin/php`).

Also download copies from **Administration → Backups** regularly, or use cPanel's own backup tool. A backup that only exists on the same server is not enough.

## 9. First steps in the app

1. **Settings:** check the SACCO details, currency and share price. Under **Controls**, set the teller-till rule and approval limits.
2. **Branches and Users:** create staff accounts with the right roles and branches.
3. **Two-step login:** every administrator should switch it on (user menu → Two-step login).
4. **Migrating from another system:** use **Operations → Imports → Member migration**, then post opening bank, cash and loan balances as *Opening balance* journals.

## Updating to a new version

1. Back up: Administration → Backups → *Back up now*, and download it.
2. Build a new zip with `php deploy/build.php` and extract it over the old files. Your `.env` and `storage/` are not in the zip, so they are kept.
3. Apply database changes. Either:
   * ask your host or use cPanel **Terminal**: `php /home/USER/…/database/migrate.php`, or
   * add a one-off cron job running that command, then remove it.

   Administrators see a "Database upgrade required" banner until this is done.

## Troubleshooting

| Symptom | Fix |
|---|---|
| **500 Internal Server Error** right after upload | Your host may not allow an `.htaccess` directive. Rename `.htaccess` temporarily to confirm, then remove the `Options` line and try again. Check cPanel → **Errors**. |
| "SaccoPro needs PHP 8.1" | Step 4. |
| Installer says it could not connect | Use the full prefixed names, check the user was **added to the database**, and use host `localhost`. |
| Uploads or imports fail on large files | cPanel → **MultiPHP INI Editor**: `upload_max_filesize 20M`, `post_max_size 25M` (`.user.ini` sets these, but some hosts ignore it). |
| Blank page or errors | Look at `storage/logs/php-error.log`. Temporarily set `APP_DEBUG=true` in `.env` to see the message, then set it back. |
| Logged out constantly behind a proxy or CDN | Make sure the site is always opened on the same `https://` address. |
