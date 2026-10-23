#!/usr/bin/env bash
# Management must be stopped. Restore completely before changing database identity.
set -euo pipefail
container=${1:?Supply the management PostgreSQL container}
archive=${2:?Supply its checkpoint archive}
test -f "$archive" && test -s "$archive"
database=$(docker exec "$container" printenv POSTGRES_DB)
[[ "$database" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]]
case "$database" in postgres|template0|template1) exit 1;; esac
suffix=$(date -u +%Y%m%d%H%M%S)_$$_$RANDOM
stage=doublepaas_restore_$suffix
displaced=doublepaas_displaced_$suffix
catalog() {
  docker exec -i "$container" sh -c 'exec psql -X -qAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres "$@"' sh \
    -v original="$database" -v stage="$stage" -v displaced="$displaced"
}
stage_created=false
blocked=false
cleanup() {
  status=$?
  trap - EXIT HUP INT TERM
  if [ "$blocked" = true ]; then
    # A rejected rename transaction leaves the original name and contents intact.
    printf 'ALTER DATABASE :"original" WITH ALLOW_CONNECTIONS true;\n' | catalog || true
  fi
  if [ "$stage_created" = true ]; then
    docker exec "$container" sh -c 'exec dropdb --if-exists -U "$POSTGRES_USER" "$1"' sh "$stage" || true
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
catalog <<'SQL'
SELECT format('CREATE DATABASE %I WITH TEMPLATE template0 OWNER %I ENCODING %L LC_COLLATE %L LC_CTYPE %L',
  :'stage', pg_get_userbyid(datdba), pg_encoding_to_char(encoding), datcollate, datctype)
FROM pg_database WHERE datname=:'original' \gexec
SQL
stage_created=true
docker exec -i "$container" sh -c 'exec pg_restore --single-transaction --exit-on-error --no-owner -U "$POSTGRES_USER" -d "$1"' sh "$stage" < "$archive"
test "$(docker exec "$container" sh -c 'exec psql -X -qAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$1" -c "SELECT count(*) FROM instance_settings WHERE id=0"' sh "$stage")" = 1
catalog <<'SQL'
-- Preserve the original database access policy and connection settings.
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'stage') \gexec
SELECT format('GRANT %s ON DATABASE %I TO %s%s', privilege_type, :'stage',
  CASE WHEN grantee=0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(grantee)) END,
  CASE WHEN is_grantable THEN ' WITH GRANT OPTION' ELSE '' END)
FROM pg_database, LATERAL aclexplode(coalesce(datacl, acldefault('d', datdba)))
WHERE datname=:'original' \gexec
SELECT format('ALTER %s SET %I TO %L',
  CASE WHEN setrole=0 THEN format('DATABASE %I', :'stage')
    ELSE format('ROLE %I IN DATABASE %I', pg_get_userbyid(setrole), :'stage') END,
  split_part(setting, '=', 1), substring(setting FROM position('=' IN setting)+1))
FROM pg_db_role_setting, LATERAL unnest(setconfig) AS setting
WHERE setdatabase=(SELECT oid FROM pg_database WHERE datname=:'original') \gexec
SQL
# Block reconnects before draining sessions. A failure restores connection access.
blocked=true
printf 'ALTER DATABASE :"original" WITH ALLOW_CONNECTIONS false;\n' | catalog
catalog <<'SQL'
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname=:'original';
BEGIN;
ALTER DATABASE :"original" RENAME TO :"displaced";
ALTER DATABASE :"stage" RENAME TO :"original";
COMMIT;
SQL
blocked=false
stage_created=false
printf 'Restored checkpoint. Displaced database retained as %s with connections disabled.\n' "$displaced"
