# Deploying Pool Queue to cPanel

This app is a plain Laravel 13 site with **no login for the web UI** and **no front-end build step**
(the CSS is committed to `public/css/app.css` and loaded via `asset()`), so you can
ignore anything about `npm` / `vite` when deploying. The only authenticated part is the
[pool-vision camera API](#pool-vision-camera-api), which uses Laravel Sanctum tokens.
The phone camera itself is a separate project and site, Pool Vision
(https://poolvision.adamlopez.co, in the pool-vision repo); this app only takes its pots.

Two wrinkles apply to any Laravel app on cPanel:

1. **The web root must point at `public/`**, never the project folder — the project
   folder holds your `.env` and source and must not be web-accessible.
2. **`vendor/` is not in git**, so `composer install` has to run on the server (or you
   upload `vendor/` yourself).

About the database: **prefer `php artisan migrate`.** `database/schema.sql` is a
complete fallback (framework tables + app tables + seeded `migrations` ledger) for when
you have no shell at all — see [The database](#the-database) below.

---

## Prerequisites on the server

- **PHP 8.3+** for the domain — set it in cPanel → *MultiPHP Manager*.
- The CLI `php` on cPanel is often an older default. Find the right binary:
  ```bash
  ls -d /opt/cpanel/ea-php* 
  # use e.g. /opt/cpanel/ea-php83/root/usr/bin/php in place of `php`
  ```
- **Composer.** Check with `composer -V`. If missing:
  ```bash
  php -r "copy('https://getcomposer.org/installer','ci.php');"
  php ci.php && rm ci.php
  # then use `php composer.phar` wherever these docs say `composer`
  ```

---

## Option A — SSH + git (recommended)

Updates become `git pull` + a script run. This is the route to prefer.

### 1. Enable SSH

- cPanel → **SSH Access** (or **Terminal**). On many shared hosts SSH is off until you
  enable it or ask support.
- Connect: `ssh YOUR_CPANEL_USER@yourdomain.com -p 22` (some hosts use port **2222**).
- Prefer a key: cPanel → SSH Access → *Manage SSH Keys* → generate or import, then
  **Authorize** it.

### 2. First deploy

```bash
cd ~
git clone https://github.com/Bishopafl/pool-queue.git
cd pool-queue

composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
nano .env                     # set production values — see "The .env file" below
php artisan migrate --force

chmod -R 775 storage bootstrap/cache

./deploy.sh                   # caches config/routes/views
```

Private repo? Clone with a GitHub Personal Access Token:
`git clone https://USERNAME:TOKEN@github.com/Bishopafl/pool-queue.git`

### 3. Point the web root at `public/`

**Best:** cPanel → *Domains* → set the domain's **Document Root** to
`/home/YOUR_USER/pool-queue/public`.

**Symlink alternative** (serving from the primary domain, back up `public_html` first):
```bash
rm -rf ~/public_html
ln -s ~/pool-queue/public ~/public_html
```

**No symlinks allowed:** copy the contents of `public/` into `public_html/`, then edit
`public_html/index.php` so its two path lines point at `__DIR__.'/../pool-queue/...'`.

### 4. Every future update

```bash
cd ~/pool-queue && ./deploy.sh
```

---

## Option B — File Manager + phpMyAdmin (no SSH)

Works as a one-time fallback, but every future update is another manual zip upload, and
you cannot run Composer or Artisan.

### 1. Build the bundle locally

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate --show     # copy the base64:... value for the server .env
```

Zip the **whole project including `vendor/`**. Exclude `.git/`, `node_modules/`,
`tests/`, and your local `.env`.

### 2. Upload

File Manager → upload the zip to `~/` (**not** `public_html`) → Extract → gives
`~/pool-queue`. Point the document root at `~/pool-queue/public` (step A3).

### 3. Create `.env`

In File Manager, create `~/pool-queue/.env` from the block below, using the
`key:generate --show` value.

### 4. The database

1. cPanel → **MySQL Databases** → create a database and a user, then add the user to
   the database with **ALL PRIVILEGES**. Names are prefixed, e.g. `cpuser_poolqueue`.
2. Put those exact names in `.env`.
3. Load the structure by one of:
   - cPanel **Terminal** (if present): `php artisan migrate --force` — skip
     `schema.sql` entirely.
   - **Cron-job trick** (no shell needed): cPanel → *Cron Jobs* → add a job set to run
     once in a minute or two:
     ```
     /usr/local/bin/php /home/YOUR_USER/pool-queue/artisan migrate --force
     ```
     Wait for the confirmation email, then **delete the cron job**. (Adjust the PHP
     path — cPanel's *Cron Jobs* page or *MultiPHP* shows it, often
     `/opt/cpanel/ea-php83/root/usr/bin/php`.) You can reuse this trick for any
     `artisan` command later, e.g. `... artisan config:cache`.
   - **phpMyAdmin import**: select the DB → **Import** → `database/schema.sql`. It is
     now complete — it creates the framework tables (`sessions`, `cache`, `jobs`, …)
     and the app tables, and seeds the `migrations` table so a later
     `php artisan migrate` is a no-op.

### 5. After uploading

- Set `storage/` and `bootstrap/cache/` to **775** recursively in File Manager.
- Delete any `bootstrap/cache/config.php` or `bootstrap/cache/routes-*.php` from your
  machine (they hardcode local paths). Keep `packages.php` and `services.php`.

---

## The database

This app is configured with `SESSION_DRIVER=database` and `CACHE_STORE=database`, so
it needs Laravel's framework tables (`sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, `users`, `password_reset_tokens`) **as well as** the app
tables (`players`, `games`, `game_players`, `queue_entries`, `queue_entry_players`), plus
`personal_access_tokens` (Sanctum) and `vision_events` for the camera API.
A site missing the `sessions` table 500s on the very first request.

- **Preferred:** `php artisan migrate --force` — creates everything, in the right
  order, guaranteed to match the code. Works from SSH, cPanel Terminal, or the
  cron-job trick in Option B step 4.
- **Fallback with no shell at all:** import `database/schema.sql` via phpMyAdmin. It
  is the complete set — framework tables + app tables — and it seeds the `migrations`
  ledger so a later `php artisan migrate` sees nothing to do. Keep the `.env` drivers
  as `database`; the tables are all there.

`schema.sql` is regenerated by hand when migrations change. If you add a migration,
either run `php artisan migrate` on the server or update `schema.sql` to match.

---

## Pool Vision cameras (another site)

Pool Vision (https://poolvision.adamlopez.co, beta) is a separate project: a phone
overlooking the table tracks the game in its own browser and keeps score by itself. It is
built and deployed from the [pool-vision](https://github.com/Bishopafl/pool-vision) repo, not
from here. This app's only part is the optional link: when someone links Pool Vision to this
scoreboard, it pairs with `POST /api/vision/pair` and sends its pots to the API below.

Two `.env` settings control that:

- `VISION_PAIR_PASSWORD`: the password the phone enters to link. Blank turns pairing off.
- `VISION_ALLOWED_ORIGINS`: the sites whose pages may call `/api/vision/*` from a browser
  (CORS, `config/cors.php`), comma-separated. Defaults to `https://poolvision.adamlopez.co`.
  The laptop app isn't a browser, so it doesn't need to be listed.

## pool-vision camera API

Pool Vision on a phone, or [pool-vision](https://github.com/Bishopafl/pool-vision) on a
laptop, posts each pot here, protected by a Laravel Sanctum token.

| Route | Purpose |
| --- | --- |
| `POST /api/vision/pair` | `{"name": "table-1", "password": "..."}` → `{"token": "..."}` when the password matches `VISION_PAIR_PASSWORD` (403 if wrong, 503 if pairing is off; 5 tries a minute) |
| `GET /api/vision/game?table=Front` | The live game: sides, players, groups, scores, who is shooting |
| `POST /api/vision/events` | One pot: `{"event_id": "<uuid>", "type": "pot", "kind": "solid", "pocket": "top side", "kind_conf": 0.9, "t": 812.4, "table": "Front"}` |

The other two need `Authorization: Bearer <token>` from a token with the `vision` ability, and are
limited to 120 requests a minute. A repeated `event_id` returns the first result and is
never scored twice, so the camera can retry safely.

**What a pot does** (`App\Services\VisionEventService`). Only things the scoreboard's
buttons can undo are applied:

- solid / stripe on an open table → the shooter takes that group, +1
- solid / stripe with groups set → +1 to the side whose group it is (the shooter keeps the table)
- cue ball (scratch) → the table passes to the other side
- **not applied, only recorded:** the 8 (a misread would end the rack: call it on the
  scoreboard), reads under 60% confidence, unknown balls, nine-ball games, games with no
  shooter marked, and a 7th-ball side's further group balls

Every event, applied or not, is kept in `vision_events` with the outcome in words.

### Issue the camera's token

Pool Vision pairs itself with `VISION_PAIR_PASSWORD` (above). For the laptop app, or
to issue one by hand:

```bash
php artisan pool-vision:token table-1            # prints the token once
php artisan pool-vision:token table-1 --revoke   # if the laptop is lost or the token leaks
```

Tokens belong to a device user (`pool-vision@devices.invalid`) with a random password, so
nobody can sign in as it. **No shell?** Use the cron-job trick from Option B step 4 with
`.../artisan pool-vision:token table-1`. The token arrives in the cron email, so delete that
email and the cron job once it's on the laptop.

Then, on the camera laptop (see the pool-vision README):

```powershell
$env:POOL_QUEUE_URL   = "https://yourdomain.com"
$env:POOL_QUEUE_TOKEN = "<the token>"
```

### Updating a live site to this version (File Manager, no SSH)

1. **Back up** first: phpMyAdmin → *Export* the database, and File Manager → compress
   `~/pool-queue` (minus `vendor/`) into a zip you keep.
2. **Upload** `pool-queue-update.zip` (built with `composer install --no-dev`, includes
   `vendor/` and the new `bootstrap/cache/packages.php` that registers Sanctum) to your home
   folder, and **Extract** it over `~/pool-queue`, overwriting. It contains no `.env` and no
   `storage/`, so your settings, logs and sessions are untouched.
3. **Database:** phpMyAdmin → select the database → *Import* →
   `database/updates/2026-10-07-vision-camera.sql`. Safe to run twice. (With a shell, use
   `php artisan migrate --force` instead.)
4. **`.env`:** add `VISION_PAIR_PASSWORD=` with a password of your choice, and
   `VISION_ALLOWED_ORIGINS=https://poolvision.adamlopez.co`.
5. **Clear cached config**, if there is any: delete `bootstrap/cache/config.php` and
   `bootstrap/cache/routes-v7.php` in File Manager. A cached config hides the new `.env`
   lines and a cached route list hides the new API routes.
6. Check it: https://yourdomain.com/api/vision/game should answer `{"message":"Unauthenticated."}`.
   Then link from Pool Vision (its "Scoreboard" button).

---

## The .env file

```ini
APP_NAME="Pool Queue"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:PASTE_FROM_key_generate
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1          # cPanel is 127.0.0.1 / localhost — never "mysql"
DB_PORT=3306
DB_DATABASE=cpuser_poolqueue
DB_USERNAME=cpuser_poolqueue
DB_PASSWORD=your-db-password

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
LOG_LEVEL=error

# Pool Vision phones link with this password (blank turns pairing off); browser calls to the
# camera API are only allowed from these sites
VISION_PAIR_PASSWORD=pick-a-long-one
VISION_ALLOWED_ORIGINS=https://poolvision.adamlopez.co
```

`APP_DEBUG` must be `false` and `APP_ENV` `production` in production. Never commit
`.env`.

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| 500, blank page | `tail storage/logs/laravel.log`; check `storage/` + `bootstrap/cache/` are 775 |
| "No application encryption key" | `php artisan key:generate` (or set `APP_KEY` by hand) |
| `/api/vision/...` 404 | Delete `bootstrap/cache/routes-v7.php`; make sure the zip's `routes/api.php` was extracted |
| "Pairing is off" when linking Pool Vision | `VISION_PAIR_PASSWORD` missing from `.env`, or a cached `bootstrap/cache/config.php` (delete it) |
| Pool Vision says "Couldn't reach" the scoreboard | Its address is wrong, or this site isn't updated, or `VISION_ALLOWED_ORIGINS` doesn't list the Pool Vision address exactly (`https://`, no trailing slash) |
| API calls fail with 500 "auth guard [sanctum]" | `bootstrap/cache/packages.php` is old: re-extract it from the update zip |
| `Table 'x.sessions' doesn't exist` / 500 on first load | Structure not loaded — run `php artisan migrate --force` or import the full `schema.sql` (see [The database](#the-database)) |
| Old code still served after `git pull` | `php artisan config:clear && ./deploy.sh` |
| CSS missing / links 404 | Document root is not pointing at `public/` |
| `SQLSTATE[HY000] [2002]` | `DB_HOST` should be `127.0.0.1`, not `mysql` |
