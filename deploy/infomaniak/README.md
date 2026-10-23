# Wellms on Infomaniak shared web hosting

This fork's `production` branch runs the Wellms API on Infomaniak's shared web hosting: no Docker, Redis, Postgres, supervisor or shell cron. It is upstream EscolaLMS/API plus the files listed below; everything else stays upstream so updates merge cleanly.

| File | Purpose |
|---|---|
| `public/cron.php` | Scheduler trigger. The Aufgabenplaner can only call a URL, at most every 15 minutes. Runs `schedule:run` (and `queue:work` if a queue is configured), at most once per 50 s, never in parallel, so it needs no secret. |
| `public/.user.ini` | Per-site PHP settings: hide errors from output (older packages throw PHP 8.4 deprecations), upload/memory limits. |
| `deploy/infomaniak/env.example` | Production `.env` template (MariaDB, `QUEUE_CONNECTION=sync`, file cache, local disk). `SENTRY_DSN` is empty on purpose: upstream's `.env.example` points to the EscolaLMS developers' Sentry. |
| `deploy/infomaniak/import-courses.php` | Imports course ZIPs (e.g. from the Thrive exporter) through the same service as Admin → Courses → Import, acting as the first admin. Skips titles that already exist. |

## Setup

1. **Manager:** add an Apache/PHP site (PHP 8.3; Laravel 9 isn't meant for 8.4), with a subdomain, e.g. `api.example.ch`. If DNS is hosted elsewhere, untick "DNS automatisch aktualisieren" and add the A/AAAA records there. Create a MariaDB database and user.
2. **SSH:**
   ```bash
   cd ~/sites/api.example.ch
   git clone --branch production https://github.com/minigraphx/wellms-api.git wellms
   cd wellms
   /opt/php8.3/bin/php /opt/php8.4/bin/composer2.phar install --no-dev --optimize-autoloader
   cp deploy/infomaniak/env.example .env && chmod 600 .env   # fill in DB_*, URLs
   ```
   Set `APP_KEY` without artisan (artisan needs the database to boot):
   ```bash
   K=$(/opt/php8.3/bin/php -r 'echo base64_encode(random_bytes(32));')
   sed -i "s|^APP_KEY=$|APP_KEY=base64:$K|" .env
   ```
3. **Manager:** set the site's location ("Erweitert" → "Speicherort") to `…/wellms/public`, then install the free Let's Encrypt certificate.
4. **Database and storage** (`P="/opt/php8.3/bin/php -d error_reporting=E_ALL&~E_DEPRECATED"`):
   ```bash
   $P artisan migrate --force
   $P artisan passport:keys        # skip if storage/oauth-*.key already exist
   $P artisan passport:client --personal --no-interaction
   $P artisan db:seed --class=PermissionsSeeder --force
   $P artisan storage:link
   $P artisan h5p:storage-link --overwrite
   $P artisan escolalms:admin      # first admin, interactive
   ```
   `h5p:storage-link` copies the H5P files into a nested absolute path (`storage/app/public/home/…`) when the local disk is used. Move `…/h5p-core` and `…/h5p-editor` from there into `storage/app/public/`, then delete the `home` folder.
5. **Manager → Aufgabenplaner:** call `https://api.example.ch/cron.php` every 15 minutes (hourly, minutes 0, 15, 30, 45). All upstream scheduled tasks fall on those minutes.

## Deploying updates

```bash
cd ~/sites/api.example.ch/wellms
git pull --ff-only
/opt/php8.3/bin/php /opt/php8.4/bin/composer2.phar install --no-dev --optimize-autoloader
/opt/php8.3/bin/php artisan migrate --force
/opt/php8.3/bin/php artisan config:clear
```

## Branches

- `develop` / `main`: mirror upstream EscolaLMS/API (use GitHub's "Sync fork").
- `production`: what runs live. Merge upstream into it; keep custom features in separate Composer packages, not in `escolalms/*`.
