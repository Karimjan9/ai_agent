# G: daily backup runbook

## Policy

- Backup root: `G:/NeuroTrader/backups`.
- Schedule: every day at `02:30` through Laravel scheduler.
- Retention: newest 3 SQL + manifest pairs by default.
- C: is never used as a fallback. If G: is disconnected or not writable, the command fails and reports the problem.

## Configuration

```dotenv
DATABASE_BACKUP_PATH=G:/NeuroTrader/backups
DATABASE_BACKUP_RETENTION=3
DATABASE_BACKUP_SCHEDULE_TIME=02:30
DATABASE_BACKUP_STALE_AFTER_SECONDS=172800
DATABASE_BACKUP_VERIFY_HASH_ON_HEALTH=false
```

Create/check the directory:

```powershell
New-Item -ItemType Directory -Force G:\NeuroTrader\backups
Get-PSDrive G
```

## Manual run and verification

```powershell
cd C:\x_programs\xamp\htdocs\laravel_projects\ai_agent\backend-laravel
php artisan ops:backup-database
php artisan system:health-check --strict
```

The backup command writes a `.sql` file and matching `.sql.manifest.json`,
then prunes older pairs. It uses an atomic `.partial` file while `mysqldump`
runs; incomplete files are not considered backup evidence.

Legacy C: backups from the previous policy can be removed after the first
successful G: backup has been verified:

```powershell
php artisan ops:cleanup-legacy-backups
```

This removes only `storage/app/backups/neurotrader_*.sql` and their manifests;
the directory itself and all other files are preserved.

## Recovery

1. Confirm G: is mounted and the SQL/manifest pair exists.
2. Verify the manifest and restore only to an approved MySQL target:
   `php artisan ops:restore-database <backup-file>`.
3. Do not delete the newest retained pair before a restore has been verified.
4. If G: is unavailable, fix the volume/mount first; do not change the path to C:.
