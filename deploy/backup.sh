#!/bin/sh
# Резервне копіювання бази з перевіркою відновлення (ТЗ 12, ТВ-13). Працює в контейнері books-backup (postgres:16-alpine).
# Щодня о BACKUP_HOUR (UTC) робить pg_dump; по неділях і при першому запуску відновлює копію в тимчасову базу
# й рахує записи, в інші дні перевіряє цілісність архіву (pg_restore --list). Копії старші за BACKUP_KEEP_DAYS видаляються.
# Ручний запуск: docker compose -p books exec books-backup sh /backup.sh now
set -u
export PGHOST="${PGHOST:-books-db}" PGUSER="${POSTGRES_USER:-books}" PGPASSWORD="${POSTGRES_PASSWORD:-}"
DIR="${BACKUP_DIR:-/backups}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
mkdir -p "$DIR"

run() {
  full="${1:-0}"
  ts="$(date +%Y%m%d-%H%M)"
  f="$DIR/books-$ts.dump"
  users=""; records=""; check="archive"
  if pg_dump -Fc -d "${POSTGRES_DB:-books}" -f "$f.part" 2>"$DIR/last.err"; then
    mv "$f.part" "$f"
    if [ "$full" = 1 ] || [ "$(date +%u)" = 7 ]; then
      check="restore"
      dropdb --if-exists books_restore_check 2>>"$DIR/last.err"
      if createdb books_restore_check 2>>"$DIR/last.err" && pg_restore -d books_restore_check --no-owner "$f" 2>>"$DIR/last.err"; then
        users="$(psql -d books_restore_check -tAc 'select count(*) from users')"
        records="$(psql -d books_restore_check -tAc 'select count(*) from records')"
        status=ok
      else
        status=restore_failed
      fi
      dropdb --if-exists books_restore_check 2>>"$DIR/last.err"
    elif pg_restore --list "$f" >/dev/null 2>>"$DIR/last.err"; then
      status=ok
    else
      status=archive_broken
    fi
  else
    rm -f "$f.part"; status=dump_failed
  fi
  size="$(stat -c %s "$f" 2>/dev/null || echo 0)"
  printf '{"time":"%s","file":"%s","size":%s,"status":"%s","check":"%s","users":"%s","records":"%s"}\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(basename "$f")" "$size" "$status" "$check" "$users" "$records" > "$DIR/last.json"
  find "$DIR" -name 'books-*.dump' -mtime +"$KEEP_DAYS" -delete
  echo "backup $(basename "$f"): $status ($check) users=$users records=$records"
}

if [ "${1:-}" = now ]; then run 1; exit 0; fi
# перша копія одразу після старту (з повною перевіркою відновлення), далі щодня
[ -f "$DIR/last.json" ] || { sleep 30; run 1; }
while true; do
  sleep 600
  [ "$(date -u +%H)" = "${BACKUP_HOUR:-01}" ] || continue
  grep -q "\"time\":\"$(date -u +%Y-%m-%d)" "$DIR/last.json" 2>/dev/null && continue
  run 0
done
