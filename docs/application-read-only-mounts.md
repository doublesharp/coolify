# Read-only application mounts

Addresses [Discussion 3422](https://github.com/coollabsio/coolify/discussions/3422).

For Docker Image, Dockerfile, Nixpacks, Railpack and static applications, open
Persistent Storage and choose **Mount access** on a volume, file or directory.
Select **Read only** for consumers and **Read and write** for publishers.
Redeploy the application to apply the saved setting. The pending deployment
diff includes access changes. Existing mounts remain writable by default.

The application storage API accepts `is_read_only` on both creation and update:

```json
{"type":"persistent","name":"releases","mount_path":"/releases","is_read_only":true}
```

```json
{"type":"file","fs_path":"/srv/releases","mount_path":"/releases","is_directory":true,"is_read_only":true}
```

For updates, include the storage UUID and type. GET returns the saved boolean.
The same write authorization and team/resource ownership checks apply. Saving
access does not restart the application or change file ownership or contents.
Mount access changes through the UI do not save other pending row edits.

This setting controls the mount inside the application container. It is separate
from the container's root filesystem setting and from whether configuration is
editable in Coolify. Administrators can edit the configuration of read-only
mounts. Compose applications and services continue to take access modes from
their Compose file; their stored `is_read_only` flag is not an effective-mode
report and cannot override Compose.

The migration converts legacy application destination access options `ro` or
`rw` into the separate field, including combinations such as `:z,ro`.
Its rollback restores `:ro` for protected mounts
before removing the columns. The renderer also tolerates legacy suffixes and
preserves other options without producing duplicate colons. New API and UI
destination paths must omit access-mode suffixes. Use the access setting instead.
Converting a legacy suffix can show a one-time pending deployment difference
against an older stored snapshot, although the effective permissions are the same.

Cloning and server transfer preserve access modes. Older transfer bundles
without the field remain writable. Preview volume names and suffix preferences
retain their existing behavior.

## Verification

```sh
php artisan test --compact tests/Feature/ApplicationReadOnlyMountTest.php
php artisan test --compact tests/Feature/StorageApiTest.php
php artisan test --compact tests/Unit/ServerTransfer/ServerTransferExporterImporterTest.php
php artisan test --compact tests/Unit/ServerTransfer/ServerTransferCoverageTest.php
php artisan test --compact tests/Unit/DeploymentConfiguration/ApplicationConfigurationSnapshotTest.php
php artisan test --compact tests/Unit/ApplicationOpenApiTest.php
php artisan test --compact tests/v4/Browser/ApplicationReadOnlyMountTest.php
vendor/bin/pint --dirty --format agent
php artisan view:cache
php artisan view:clear
npm run build
```

The feature suite includes opt-in real Docker checks. Set
`COOLIFY_MOUNT_DOCKER_TEST_DIR` to an isolated disposable directory visible to
the Docker daemon. It creates readers and writers, verifies `Mounts[].RW`,
checks reader write denial and publisher visibility, and repeats after container
recreation. Tests remove their containers and named volume in `finally`.
`COOLIFY_MOUNT_DOCKER_COMMAND` optionally accepts a JSON command prefix for an
isolated daemon, for example `["docker","exec","test-engine","/tools/docker"]`.
The Compose CLI configuration check runs locally and requires `docker compose`.
Run Docker cases serially.

