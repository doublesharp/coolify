#!/usr/bin/env bash
# Fail if the official runtime source differs from its pinned release tag.
set -euo pipefail
image=$(jq -r .image .doublepaas/upstream.json)
commit=$(jq -r .commit .doublepaas/upstream.json)
work=${RUNNER_TEMP:?}/doublepaas-upstream-source
test ! -e "$work"
mkdir -p "$work/expected" "$work/actual/resources" "$work/actual/database"
container=
cleanup() {
  if [ -n "$container" ]; then docker rm -f "$container" >/dev/null; fi
  rm -rf -- "$work"
}
trap cleanup EXIT
if ! git cat-file -e "$commit^{commit}"; then
  git fetch --depth=1 https://github.com/coollabsio/coolify.git "$commit"
fi
git archive "$commit" app resources/views database/migrations | tar -x -C "$work/expected"
docker pull "$image"
container=$(docker create --entrypoint /bin/true "$image")
docker cp "$container:/var/www/html/app" "$work/actual/app"
docker cp "$container:/var/www/html/resources/views" "$work/actual/resources/views"
docker cp "$container:/var/www/html/database/migrations" "$work/actual/database/migrations"
diff -qr "$work/expected" "$work/actual"
echo 'Official image app, views and migrations match the pinned upstream source.'
