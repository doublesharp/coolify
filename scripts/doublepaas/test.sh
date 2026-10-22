#!/usr/bin/env bash
set -euo pipefail

php -d memory_limit=1024M vendor/bin/pest --compact \
  tests/Feature/ApplicationReadOnlyMountTest.php \
  tests/Feature/StorageApiTest.php \
  tests/Feature/ClonePersistentVolumeUuidTest.php \
  tests/Feature/PersistentStoragePerformanceTest.php \
  tests/Feature/PersistentStorageVolumesLayoutTest.php \
  tests/Feature/StorageAndServiceAuditTest.php \
  tests/Feature/Mcp/McpReadToolsTest.php \
  tests/Unit/ServerTransfer/ServerTransferExporterImporterTest.php \
  tests/Unit/DeploymentConfiguration/ApplicationConfigurationSnapshotTest.php \
  tests/Unit/ApplicationOpenApiTest.php
php -d memory_limit=1024M vendor/bin/pest --compact tests/v4/Browser/ApplicationReadOnlyMountTest.php
php artisan view:cache
php artisan view:clear
