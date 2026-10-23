#!/usr/bin/env bash
# Exercise management rollback against real PostgreSQL without persistent volumes.
set -euo pipefail
work=${RUNNER_TEMP:?}/doublepaas-restore-test
test ! -e "$work"
mkdir -p "$work"
container=doublepaas-restore-test-$$
helper=${DOUBLEPAAS_RESTORE_HELPER:-$(dirname "$0")/restore-database.sh}
cleanup() {
  docker rm -f "$container" >/dev/null 2>&1 || true
  rm -rf -- "$work"
}
trap cleanup EXIT
docker run -d --name "$container" --network none \
  --tmpfs /var/lib/postgresql/data:rw,size=256m \
  -e POSTGRES_HOST_AUTH_METHOD=trust -e POSTGRES_USER=postgres -e POSTGRES_DB=coolify_restore_test \
  postgres:15-alpine >/dev/null
for _ in $(seq 1 60); do
  if docker exec "$container" pg_isready -U postgres -d coolify_restore_test >/dev/null 2>&1; then break; fi
  sleep 1
done
sql() { docker exec -i "$container" psql -X -qAt -v ON_ERROR_STOP=1 -U postgres -d coolify_restore_test; }
sql <<'SQL'
CREATE TABLE migrations (id bigserial PRIMARY KEY, migration text NOT NULL);
INSERT INTO migrations (migration) VALUES ('read_only_mounts');
CREATE TABLE instance_settings (id bigint PRIMARY KEY);
INSERT INTO instance_settings VALUES (0);
CREATE TABLE mounts (id bigint PRIMARY KEY, is_read_only boolean NOT NULL);
INSERT INTO mounts VALUES (1, true);
SQL
docker exec "$container" pg_dump -Fc -U postgres coolify_restore_test > "$work/checkpoint.dmp"
sql <<'SQL'
CREATE TABLE new_release_table (id bigint REFERENCES mounts(id));
INSERT INTO new_release_table VALUES (1);
UPDATE mounts SET is_read_only = false;
SQL
# An archive failure must leave the current database and its new objects intact.
head -c 100 "$work/checkpoint.dmp" > "$work/truncated.dmp"
if bash "$helper" "$container" "$work/truncated.dmp"; then
  echo 'A truncated archive was incorrectly accepted.' >&2
  exit 1
fi
test "$(printf 'SELECT is_read_only FROM mounts WHERE id=1;' | sql)" = f
test "$(printf 'SELECT count(*) FROM new_release_table;' | sql)" = 1
# Restore must remove new-release objects and recover the checkpoint values.
bash "$helper" "$container" "$work/checkpoint.dmp"
test "$(printf 'SELECT is_read_only FROM mounts WHERE id=1;' | sql)" = t
test "$(printf "SELECT to_regclass('new_release_table') IS NULL;" | sql)" = t
test "$(printf 'SELECT count(*) FROM migrations;' | sql)" = 1
# A rejected swap must preserve the complete current database, including its name.
sql <<'SQL'
UPDATE mounts SET is_read_only=false;
CREATE TABLE retained_on_failure (id bigint);
SQL
mkdir "$work/bin"
cat > "$work/bin/docker" <<'PY'
#!/usr/bin/env python3
import os, subprocess, sys
args = sys.argv[1:]
if 'psql' in ' '.join(args) and '-i' in args:
    data = sys.stdin.buffer.read()
    data = data.replace(b'COMMIT;', b'SELECT 1/0;\nCOMMIT;')
    sys.exit(subprocess.run([os.environ['REAL_DOCKER'], *args], input=data).returncode)
os.execv(os.environ['REAL_DOCKER'], [os.environ['REAL_DOCKER'], *args])
PY
chmod +x "$work/bin/docker"
if REAL_DOCKER="$(command -v docker)" PATH="$work/bin:$PATH" bash "$helper" "$container" "$work/checkpoint.dmp"; then
  echo 'An injected swap failure was incorrectly accepted.' >&2
  exit 1
fi
test "$(printf 'SELECT is_read_only FROM mounts WHERE id=1;' | sql)" = f
test "$(printf "SELECT to_regclass('retained_on_failure') IS NOT NULL;" | sql)" = t
echo 'Fresh restore passed. Archive and swap failures preserved the original database.'
