# G: daily backup runbook

## Policy

- Backup root: `G:/NeuroTrader/backups`.
- Schedule: every day at `02:30` through Laravel scheduler.
- Retention: one newest verified database snapshot and its manifest.
- The scheduler uses a separate serial `scheduler-backup` queue; a full dump cannot block ordinary operational commands.
- C: is never used as a fallback. If G: is disconnected or not writable, the command fails and reports the problem.

## Configuration

```dotenv
DATABASE_BACKUP_PATH=G:/NeuroTrader/backups
DATABASE_BACKUP_RETENTION=1
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

The backup command streams native `mysqldump` output in 1 MiB chunks into a
`.sql.gz.partial` file. It re-reads the complete gzip stream to verify its CRC,
raw byte count and raw SHA-256, then records the compressed SHA-256 and publishes
the `.sql.gz` plus `.sql.gz.manifest.json` pair. A shared backup lock prevents
concurrent writers. Only a verified new pair authorizes pruning older SQL/gzip
pairs; failed or incomplete writes retain the previous completed snapshot.

The project private Python environment owns the stream helper. Database
credentials go through stdin/environment, and native Windows children use
`CREATE_NO_WINDOW`. The native dump has a 45-minute deadline, the command a
55-minute deadline and the isolated queue job a one-hour lease. The worker's
3700-second timeout remains below Redis's default 4500-second visibility;
runtime reload preflight checks the backup queue too.

Legacy C: backups from the previous policy can be removed after the first
successful G: backup has been verified:

```powershell
php artisan ops:cleanup-legacy-backups
```

This removes only `storage/app/backups/neurotrader_*.sql` and their manifests;
the directory itself and all other files are preserved.

## Recovery

1. Confirm G: is mounted and the SQL/manifest pair exists.
2. Verify the manifest and restore SQL or gzip only to an approved MySQL target:
   `php artisan ops:restore-database <backup-file>`.
3. Keep the newest verified pair. Exercise restoration on a separate scratch
   server/account and compare native table inventory plus original scientific
   records; a later live schema may differ from an older snapshot.
4. If G: is unavailable, fix the volume/mount first; do not change the path to C:.
