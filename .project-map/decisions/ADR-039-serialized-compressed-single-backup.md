# ADR-039: Serialized compressed database backup with one retained snapshot

Status: accepted. Date: 2026-10-12.

The operator requires one newest database snapshot. The measured database dump
and complete verification take about 22 minutes, exceeding the ordinary
15-minute scheduler lease. Native dump stdout through Symfony WindowsPipes
also exceeded the PHP memory limit.

The existing scheduled Artisan job routes `ops:backup-database` to a dedicated
serial `scheduler-backup` queue. Its 3600-second job lease and 3700-second worker
timeout remain below Redis's existing 4500-second visibility. Native dump,
helper and shared lock limits are 2700, 3300 and 4800 seconds. The fallback
hidden supervisor owns the same queue and lease, and reload preflight includes
its pending/reserved work. Other queue visibility and command budgets remain
unchanged.

A project-private Python helper receives database configuration through stdin,
passes its password only through the native environment and streams 1 MiB
chunks into gzip. Native Windows children use `CREATE_NO_WINDOW`. Successful
native exit, complete gzip CRC, raw length/SHA and compressed SHA are verified
before the snapshot/manifest pair is published. Only a verified new pair in
the same resolved directory permits deletion of older SQL/gzip pairs. A shared
lock serializes writers; failure retains the previous completed snapshot.

Operational backup/restoration proves database recoverability. It grants no
research independence, economic authority, evidence rewrite or budget renewal.
