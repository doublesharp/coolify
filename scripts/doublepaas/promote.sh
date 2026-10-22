#!/usr/bin/env bash
# Run as root on DoublePaaS after qualifying this exact image revision.
set -Eeuo pipefail
umask 077
image=${1:?Usage: promote.sh ghcr.io/doublesharp/coolify@sha256:DIGEST REVISION}
revision=${2:?Supply the expected full Git revision}
[[ "$image" =~ ^ghcr\.io/doublesharp/coolify@sha256:[a-f0-9]{64}$ ]]
[[ "$revision" =~ ^[a-f0-9]{40}$ ]]
test "$(id -u)" = 0
exec 9>/run/lock/double-paas-coolify-promotion.lock
flock -n 9
cd /data/coolify/source
compose=(docker compose --env-file .env -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.custom.yml)
"${compose[@]}" config --format json | python3 -c '
import json,sys
value=json.load(sys.stdin)["services"]["coolify"].get("environment", {}).get("AUTOUPDATE")
if value is not None and str(value).lower() not in ("false", "(false)"):
    raise SystemExit("Remove AUTOUPDATE or set it to false before promoting the fork.")
'
previous_id=$(docker inspect coolify --format '{{.Image}}')
previous_ref=$("${compose[@]}" config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["services"]["coolify"]["image"])')
test "$(docker image inspect "$previous_ref" --format '{{.Id}}')" = "$previous_id"
docker pull "$image"
test "$(docker image inspect "$image" --format '{{index .Config.Labels "org.opencontainers.image.revision"}}')" = "$revision"
test "$(docker image inspect "$image" --format '{{index .Config.Labels "org.opencontainers.image.source"}}')" = https://github.com/doublesharp/coolify
expected_id=$(docker image inspect "$image" --format '{{.Id}}')
checkpoint=/data/coolify/source/doublepaas-releases/$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$checkpoint"
chmod 700 "$checkpoint"
cp -p docker-compose.custom.yml "$checkpoint/docker-compose.custom.yml"
cp -p .env "$checkpoint/env"
docker inspect coolify --format '{{.Config.Image}} {{.Image}}' > "$checkpoint/previous-image.txt"
docker ps --format '{{.Names}} {{.ID}}' | sort > "$checkpoint/containers-before.txt"
docker exec coolify-db sh -c 'pg_dump -Fc -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$checkpoint/coolify.dmp"
test -s "$checkpoint/coolify.dmp"

wait_healthy() {
  for _ in $(seq 1 60); do
    if [ "$(docker inspect coolify --format '{{.State.Health.Status}}' 2>/dev/null)" = healthy ] && \
       docker exec coolify curl -fsS http://127.0.0.1:8080/api/health >/dev/null; then
      return 0
    fi
    sleep 3
  done
  return 1
}

rollback() {
  status=$?
  trap - ERR
  set +e
  echo "Promotion failed; restoring management configuration and database from $checkpoint." >&2
  "${compose[@]}" stop coolify
  cp -p "$checkpoint/docker-compose.custom.yml" docker-compose.custom.yml
  # Stop all management writers before restoring. Application containers keep running.
  if docker exec -i coolify-db sh -c 'pg_restore --single-transaction --exit-on-error --clean --if-exists --no-owner -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < "$checkpoint/coolify.dmp"; then
    "${compose[@]}" up -d --no-deps --pull never coolify
    if wait_healthy && [ "$(docker inspect coolify --format '{{.Image}}')" = "$previous_id" ]; then
      echo 'Previous management state is healthy again.' >&2
    else
      echo "Rollback needs operator recovery from $checkpoint." >&2
    fi
  else
    echo "Database restoration failed. Keep management stopped and recover from $checkpoint." >&2
  fi
  exit "$status"
}

python3 - "$image" <<'PY'
import pathlib, re, sys
path = pathlib.Path('docker-compose.custom.yml')
text = path.read_text()
if len(re.findall(r'^  coolify:[ \t]*$', text, re.M)) != 1:
    raise SystemExit('Expected exactly one coolify service in the existing override.')
text, count = re.subn(r'(^  coolify:[ \t]*\n)(?:    image:[^\n]*\n)?', lambda m: m[1] + '    image: ' + sys.argv[1] + '\n', text, count=1, flags=re.M)
if count != 1:
    raise SystemExit('Could not add image pin to existing override.')
pathlib.Path('docker-compose.doublepaas-candidate.yml').write_text(text)
PY
docker compose --env-file .env -f docker-compose.yml -f docker-compose.prod.yml \
  -f docker-compose.doublepaas-candidate.yml config --format json | python3 -c '
import json,sys
c=json.load(sys.stdin)["services"]["coolify"]
assert c["image"] == sys.argv[1]
assert all(p.get("host_ip") == "127.0.0.1" for p in c["ports"])
assert any(v.get("target") == "/etc/nginx/conf.d/zz-double-paas-security-headers.conf" and v.get("read_only") for v in c["volumes"])
print("Image pin, loopback ports and security-header mount validated.")
' "$image"

# From here failures restore the pre-promotion management database and override.
trap rollback ERR
mv docker-compose.doublepaas-candidate.yml docker-compose.custom.yml
sql() {
  docker exec coolify-db sh -c 'exec psql -X -q -v ON_ERROR_STOP=1 -tA -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "$1"' sh "$1"
}
test "$(sql 'UPDATE instance_settings SET is_auto_update_enabled=false WHERE id=0 RETURNING is_auto_update_enabled')" = f
"${compose[@]}" up -d --no-deps --pull never coolify
wait_healthy
test "$(docker inspect coolify --format '{{.Image}}')" = "$expected_id"
test "$(sql 'SELECT is_auto_update_enabled FROM instance_settings WHERE id=0')" = f
test "$(sql "SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name IN ('local_file_volumes', 'local_persistent_volumes') AND column_name='is_read_only'")" = 2
test "$(sql "SELECT count(*) FROM migrations WHERE migration='2026_10_22_200214_add_is_read_only_to_application_mounts'")" = 1
cmp -s "$checkpoint/env" .env
trap - ERR
docker ps --format '{{.Names}} {{.ID}}' | sort > "$checkpoint/containers-after.txt"
if ! diff <(grep -v '^coolify ' "$checkpoint/containers-before.txt") <(grep -v '^coolify ' "$checkpoint/containers-after.txt"); then
  echo 'Management passed its guards. Inspect container changes above before accepting workloads; ephemeral helpers can change during promotion.' >&2
fi
printf 'Serving %s\nRevision %s\nRollback checkpoint %s\n' "$image" "$revision" "$checkpoint"
