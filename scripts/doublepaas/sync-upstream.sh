#!/usr/bin/env bash
# Run in an ephemeral checkout. Prepare updates; never promote stable or deploy.
set -euo pipefail
test -z "$(git status --porcelain)"
repo=${GITHUB_REPOSITORY:-doublesharp/coolify}
test "$repo" = doublesharp/coolify
tag=$(gh release view --repo coollabsio/coolify --json tagName --jq .tagName)
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]
if [ "$tag" = "$(jq -r .tag .doublepaas/upstream.json)" ]; then
  echo "Already based on $tag."
  exit 0
fi
branch=doublepaas/update-$tag
if git ls-remote --exit-code --heads origin "$branch" >/dev/null; then
  echo "An update branch already exists for $tag; review it before requesting another update."
  exit 0
fi
git config user.name 'doublepaas-upstream[bot]'
git config user.email 'doublepaas-upstream[bot]@users.noreply.github.com'
git fetch https://github.com/coollabsio/coolify.git "refs/tags/$tag:refs/tags/$tag"
git switch -c "$branch"
# Apply the delta from the last pinned release with three-way conflict handling.
# Do not introduce upstream workflow commits or request workflow-write authority.
previous=$(jq -r .commit .doublepaas/upstream.json)
patch=${RUNNER_TEMP:?}/doublepaas-upstream.patch
git diff --binary --full-index "$previous" "$tag" -- . ':!.github/workflows' > "$patch"
if [ -s "$patch" ] && ! git apply --3way --index "$patch"; then
  echo '::error::Resolve the upstream release delta conflicts manually before promotion.'
  exit 1
fi
commit=$(git rev-parse "$tag^{}")
image="docker.io/coollabsio/coolify:${tag#v}"
digest=$(docker buildx imagetools inspect "$image" --format '{{json .Manifest}}' | jq -r .digest)
[[ "$digest" =~ ^sha256:[a-f0-9]{64}$ ]]
jq --arg tag "$tag" --arg commit "$commit" --arg image "$image@$digest" \
  '.tag=$tag | .commit=$commit | .image=$image' .doublepaas/upstream.json > upstream-new.json
mv upstream-new.json .doublepaas/upstream.json
git add .doublepaas/upstream.json
git commit -m "chore(doublepaas): apply upstream $tag"
git push origin "$branch"
printf 'Review upstream %s: https://github.com/%s/compare/doublepaas/stable...%s\n' "$tag" "$repo" "$branch" | tee -a "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
gh workflow run doublepaas-build.yml --repo "$repo" --ref "$branch"
