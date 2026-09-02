# Deploying Pool Queue to cPanel

This app is a plain Laravel 13 site with **no auth** and **no front-end build step**
(the CSS is committed to `public/css/app.css` and loaded via `asset()`), so you can
ignore anything about `npm` / `vite` when deploying.

Two wrinkles apply to any Laravel app on cPanel:

1. **The web root must point at `public/`**, never the project folder — the project
   folder holds your `.env` and source and must not be web-accessible.
2. **`vendor/` is not in git**, so `composer install` has to run on the server (or you
   upload `vendor/` yourself).

And about the database: **run `php artisan migrate`, not `database/schema.sql`.**
`schema.sql` only creates the 5 app tables and omits the framework tables this app
needs (sessions, cache, jobs). See [The database](#the-database) below.

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
3. If cPanel has a **Terminal**, just run `php artisan migrate --force` and skip
   `schema.sql`.
4. If you truly cannot run Artisan: phpMyAdmin → select the DB → **Import**
   `database/schema.sql`, **and** set these in `.env` so the missing framework tables
   are never used:
   ```ini
   SESSION_DRIVER=file
   CACHE_STORE=file
   QUEUE_CONNECTION=sync
   ```

### 5. After uploading

- Set `storage/` and `bootstrap/cache/` to **775** recursively in File Manager.
- Delete any `bootstrap/cache/config.php` or `bootstrap/cache/routes-*.php` from your
  machine (they hardcode local paths). Keep `packages.php` and `services.php`.

---

## The database

`database/schema.sql` creates only `players`, `games`, `game_players`,
`queue_entries`, `queue_entry_players`. It does **not** create `sessions`, `cache`,
`cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `users`,
`password_reset_tokens` — and this app is configured (`SESSION_DRIVER=database`,
`CACHE_STORE=database`, `QUEUE_CONNECTION=database`) to use several of them. Importing
only `schema.sql` will error on the first page load.

- **Preferred:** `php artisan migrate --force` — creates everything correctly.
- **Only if Artisan is impossible:** import `schema.sql` and switch
  `SESSION_DRIVER`/`CACHE_STORE` to `file` and `QUEUE_CONNECTION` to `sync` (above).

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

SESSION_DRIVER=database    # or "file" if you could not run migrations
CACHE_STORE=database       # or "file"
QUEUE_CONNECTION=sync
LOG_LEVEL=error
```

`APP_DEBUG` must be `false` and `APP_ENV` `production` in production. Never commit
`.env`.

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| 500, blank page | `tail storage/logs/laravel.log`; check `storage/` + `bootstrap/cache/` are 775 |
| "No application encryption key" | `php artisan key:generate` (or set `APP_KEY` by hand) |
| Table not found on first load | You imported `schema.sql` instead of running migrations — see [The database](#the-database) |
| Old code still served after `git pull` | `php artisan config:clear && ./deploy.sh` |
| CSS missing / links 404 | Document root is not pointing at `public/` |
| `SQLSTATE[HY000] [2002]` | `DB_HOST` should be `127.0.0.1`, not `mysql` |
