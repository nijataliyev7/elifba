#!/bin/sh
# The `backup` service (docker-compose.yml, docs/architecture.md §10), on the
# postgres image: `pg_dump --format=custom` of Elifba's database into
# /backups (the `backups` volume), keeping BACKUP_KEEP_DAYS days of dumps.
#
#   elifba-backup nightly   every day at BACKUP_AT in TZ (Asia/Baku); the service's command
#   elifba-backup now       one dump now: `docker compose exec backup elifba-backup now`
#
# Restore one into the running database (it replaces what is there):
#   docker compose exec backup pg_restore --clean --if-exists --no-owner -d elifba /backups/elifba-….dump
#
# The connection comes from PGHOST, PGUSER, PGDATABASE and PGPASSWORD.

set -eu

dir=/backups
keep_days=${BACKUP_KEEP_DAYS:-14}
at=${BACKUP_AT:-03:00}

backup() {
    file="$dir/elifba-$(date +%Y-%m-%d_%H%M%S).dump"
    pg_dump --format=custom --no-owner --file="$file.partial"
    mv "$file.partial" "$file"
    echo "elifba-backup: wrote $file ($(du -h "$file" | cut -f1))"

    # Older than keep_days whole days; and parts a crash left behind.
    find "$dir" -name 'elifba-*.dump' -type f -mtime +"$((keep_days - 1))" -print -delete
    find "$dir" -name 'elifba-*.dump.partial' -type f -mmin +60 -delete
}

case "${1:-}" in
    now)
        backup
        ;;
    nightly)
        # `docker compose stop` sends TERM: leave at once, not after the sleep.
        trap 'exit 0' TERM INT
        while :; do
            now=$(date +%s)
            next=$(date -d "today $at" +%s)
            [ "$next" -gt "$now" ] || next=$(date -d "tomorrow $at" +%s)
            echo "elifba-backup: next dump at $(date -d "@$next" '+%Y-%m-%d %H:%M %Z')"
            sleep "$((next - now))" &
            wait $!
            backup || echo "elifba-backup: pg_dump failed" >&2
        done
        ;;
    *)
        echo "usage: elifba-backup now|nightly" >&2
        exit 2
        ;;
esac
