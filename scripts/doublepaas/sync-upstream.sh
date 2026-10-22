#!/usr/bin/env bash
# Run in an ephemeral checkout. Propose updates; never merge or deploy them.
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
if ! git merge --no-commit --no-ff "$tag"; then
  # Official workflow edits are deliberately excluded from the production fork.
  while IFS= read -r path; do
    case "$path" in
      .github/workflows/*) git rm -f -- "$path" ;;
      *) echo "::error::Resolve the upstream merge conflict in $path manually."; exit 1 ;;
    esac
  done < <(git diff --name-only --diff-filter=U)
fi
for path in .github/workflows/*; do
  case "$path" in
    .github/workflows/doublepaas-build.yml|.github/workflows/doublepaas-sync.yml) ;;
    *) git rm -f --ignore-unmatch -- "$path" ;;
  esac
done
commit=$(git rev-parse "$tag^{}")
image="docker.io/coollabsio/coolify:${tag#v}"
digest=$(docker buildx imagetools inspect "$image" --format '{{json .Manifest}}' | jq -r .digest)
[[ "$digest" =~ ^sha256:[a-f0-9]{64}$ ]]
jq --arg tag "$tag" --arg commit "$commit" --arg image "$image@$digest" \
  '.tag=$tag | .commit=$commit | .image=$image' .doublepaas/upstream.json > upstream-new.json
mv upstream-new.json .doublepaas/upstream.json
git add .doublepaas/upstream.json
git commit -m "chore(doublepaas): merge upstream $tag"
git push origin "$branch"
printf 'Review upstream %s: https://github.com/%s/compare/doublepaas/stable...%s\n' "$tag" "$repo" "$branch" | tee -a "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
gh workflow run doublepaas-build.yml --repo "$repo" --ref "$branch"
