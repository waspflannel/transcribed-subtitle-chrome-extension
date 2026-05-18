# Transcribed Subtitle Extension Backend

Laravel API for generating YouTube subtitle tracks, learning-token metadata, and subtitle job history for the browser extension.

## Useful Commands

```powershell
composer install
php artisan migrate
php artisan test --compact
vendor/bin/pint --dirty --format agent
php artisan serve
```

## Runtime Profiles

Default `.env.example` stays SQLite-backed for fast tests and simple local smoke runs. SQLite is not the parallel runtime; it auto-starts at most one `subtitle-ai` worker to avoid local database lock contention.

For the Postgres + Redis parallel profile:

Prerequisites: Docker, the PHP `pdo_pgsql` extension, and either `phpredis` or the Composer-managed `predis/predis` client.

```powershell
docker compose up -d postgres redis
Copy-Item .env.parallel.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Run explicit local workers when you do not want auto-started workers:

```powershell
php artisan queue:work redis --queue=subtitle-ai,default --tries=1 --timeout=1200 --sleep=1
```

Keep the queue `retry_after` value above the worker timeout; the example profiles use 1260 seconds for 1200 second subtitle workers.

Production should set `SUBTITLE_AUTO_START_WORKERS=false` and run supervised `subtitle-ai` workers.

The backend is API-only. Extension UI work lives in `../extension`.
