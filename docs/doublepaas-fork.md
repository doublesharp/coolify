# DoublePaaS maintained Coolify fork

`doublepaas/stable` is the production branch of
[doublesharp/coolify](https://github.com/doublesharp/coolify). It starts at official
`v4.4.7` and carries the read-only application mount change from
`feat/readonly-application-mounts`. The feature branch targets upstream `next`.
The stable backport uses the existing read-only MCP tools, without importing
the unreleased MCP write tools or other `next` behavior.

Source checkouts live on the backed-up Data volume under
`/Users/justin/Workspaces/DoublePaaS/coolify` and `coolify-stable`. Dependencies,
assets, captures and logs live under `/Volumes/Scratch/coolify`. GitHub-hosted
Linux runners use their disposable runner disk.

## Images and checks

`.doublepaas/upstream.json` pins the official release tag, source commit and
multi-platform image digest. The fork image starts from that exact official
runtime and replaces only application PHP, views, migrations and API specs.
Composer dependencies and compiled CSS/JavaScript remain the official release's
versions. Each architecture checks the base image's app, views and migrations
against the pinned tag before overlaying the patch. The patch changes no
dependency or asset source files. If a future
patch needs those, update the Dockerfile and checks before merging it.

The build workflow runs affected tests, real Docker reader/writer checks,
browser controls, frontend compilation, and a PostgreSQL migration up/down/up
check. It publishes native amd64 and arm64 images only after tests pass on the
stable branch. The combined image has `sha-<full Git revision>` and
`doublepaas-stable` tags. Production always uses an immutable digest.
OCI labels identify the fork revision and official base image.

Upstream's release, publishing and issue-management workflows are removed from
this production branch. Only `doublepaas-build.yml` and `doublepaas-sync.yml`
operate here. Private package pulls use DoublePaaS's existing read-only GHCR
credential. Public visibility is optional and permits anonymous pulls.

## Upstream updates

The sync workflow checks the latest official stable GitHub release daily at
09:17 UTC. It applies the delta from the last pinned release to an update branch
using Git's three-way conflict handling, retains the local patch,
pins the new official image digest, publishes a review link and explicitly
dispatches its tests. It never merges or deploys the PR. Merge conflicts outside
fork operation files are excluded. Source conflicts stop the job for manual
resolution. Existing update
branches are left for an operator to review. Repository-wide workflow PR approval
power stays disabled; open an internal PR from the review link when ready.

```sh
gh workflow run doublepaas-sync.yml --repo doublesharp/coolify
gh run list --repo doublesharp/coolify --workflow doublepaas-sync.yml
gh api repos/doublesharp/coolify/branches --jq '.[].name'
```

Review the update diff, upstream release notes and test results before merging.
When testing a manually resolved update branch, dispatch the build workflow with
`--ref <branch>`. That runs checks without publishing an image. After merging,
wait for the stable build, qualify its revision and promote its exact digest.
The sync workflow cannot approve pull requests or promote production.

Keep the upstream contribution branch current separately with `git fetch
upstream` and a reviewed rebase onto `upstream/next`. Once upstream ships an
equivalent implementation, test removing our patch on an update branch. Retain
or migrate its stored access flags before retiring the custom image.

## Promotion and recovery

Coolify's native automatic management updates are disabled on this fork.
The custom Compose override pins the fork image while preserving the existing
loopback ports and read-only security-header configuration. Updating management
does not redeploy applications or change saved mount access flags.

Before promotion, run the encrypted off-host recovery job and confirm success.
Resolve the published manifest digest and qualify the image locally. Run the
versioned `scripts/doublepaas/promote.sh` as root on DoublePaaS, supplying the
immutable image and full revision, for example:

```sh
bash promote.sh ghcr.io/doublesharp/coolify@sha256:<64 hex characters> <40-character revision>
```

The script validates image labels, saves a protected local database and config
checkpoint, validates the existing override with the new image, disables native
updates, and recreates only `coolify`. It verifies health, exact running image,
the migration and unchanged environment, then reports container ID differences
for workload acceptance. Ephemeral helpers do not trigger database rollback.
A failed management guard stops
management, restores the database and override checkpoint, and starts the prior
management image with `--pull never`. Application containers keep running.

Local checkpoints contain secrets and stay mode 0700 under
`/data/coolify/source/doublepaas-releases`. They are included in encrypted
recovery backups. Keep them through acceptance, then remove superseded ones
after verifying the off-host copy and leaving a known-good rollback checkpoint.

When returning to an official image, first preserve mount modes through this
feature's migration rollback or restore its matching pre-fork database snapshot.
Switching only the image can lose the effective read-only mode of migrated rows.
Do not run `docker compose down` for promotion or replace APP_KEY.

Upstream accepts disclosed AI assistance but requires human-written Changes and
Why sections and its contributor agreement for the upstream PR. The feature
documentation records assistance and usage; a human must write those sections
before upstream submission.
