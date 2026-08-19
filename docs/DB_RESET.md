# Reset the database (nuclear option)

> ⚠️ This drops **every** platform table and re-seeds a fresh install.
> Use only on staging / dev / a brand-new production server.

## Usage

```bash
# Interactive (asks for confirmation):
php artisan db:reset

# Skip the confirmation prompt:
php artisan db:reset --force

# Reset but don't seed:
php artisan db:reset --no-seed
```

## What it does

1. **Clears local file storage** under `storage/app/` (avatars, KYC
   documents, etc.) — framework subdirs (`framework`, `logs`,
   `testing`, `debugging`) are left intact.
2. **Drops every table** and re-runs all migrations (`migrate:fresh`).
3. **Runs the seeders** (`db:seed`) — pass `--no-seed` to skip.
4. **Clears framework caches** (views, config, app cache) so stale
   compiled views don't leak after the schema change.

## Safeguards

- **Blocked in production** unless you pass `--force`. Even then,
  `APP_ENV=production` will still print a loud warning before
  proceeding.
- Asks for confirmation interactively unless `--force` is passed.

## Typical fresh-install flow

```bash
cp .env.example .env
php artisan key:generate
# Edit .env: set DB credentials, APP_URL, TELEGRAM_BOT_TOKEN, etc.
php artisan db:reset --force
php artisan telegram:webhook:set
php artisan queue:work --tries=3
```
