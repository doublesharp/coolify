<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'coolify_mount_test') {
    throw new RuntimeException('Use APP_ENV=testing and the dedicated PostgreSQL coolify_mount_test database.');
}

$schema = 'doublepaas_mounts_'.bin2hex(random_bytes(6));
DB::statement('CREATE SCHEMA '.$schema);
try {
    DB::statement('SET search_path TO '.$schema);
    Schema::create('applications', function (Blueprint $table): void {
        $table->id();
        $table->string('build_pack');
    });
    DB::table('applications')->insert([['id' => 1, 'build_pack' => 'dockerimage'], ['id' => 2, 'build_pack' => 'dockercompose']]);
    $cases = [
        [1, 'App\\Models\\Application', '/data:ro', '/data', true, '/data:ro'],
        [1, 'App\\Models\\Application', '/data:rw', '/data', false, '/data'],
        [1, 'App\\Models\\Application', '/data:z,ro', '/data:z', true, '/data:ro,z'],
        [1, 'App\\Models\\Application', '/data:rw,z', '/data:z', false, '/data:z'],
        [1, 'App\\Models\\Application', '/data', '/data', false, '/data'],
        [2, 'App\\Models\\Application', '/data:ro', '/data:ro', false, '/data:ro'],
        [1, 'App\\Models\\ServiceApplication', '/data:ro', '/data:ro', false, '/data:ro'],
        [1, 'App\\Models\\StandalonePostgresql', '/data:ro', '/data:ro', false, '/data:ro'],
    ];
    foreach (['local_persistent_volumes', 'local_file_volumes'] as $name) {
        Schema::create($name, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('resource_id');
            $table->string('resource_type');
            $table->string('mount_path');
        });
        foreach ($cases as $i => [$resourceId, $resourceType, $path]) {
            DB::table($name)->insert(['id' => $i + 1, 'resource_id' => $resourceId, 'resource_type' => $resourceType, 'mount_path' => $path]);
        }
    }
    $migration = require __DIR__.'/../../database/migrations/2026_10_22_200214_add_is_read_only_to_application_mounts.php';
    $check = function (bool $rolledBack) use ($cases): void {
        foreach (['local_persistent_volumes', 'local_file_volumes'] as $name) {
            foreach ($cases as $i => [, , , $normalized, $readOnly, $rollback]) {
                $mount = DB::table($name)->where('id', $i + 1)->first();
                if ($mount->mount_path !== ($rolledBack ? $rollback : $normalized) || (! $rolledBack && (bool) $mount->is_read_only !== $readOnly)) {
                    throw new RuntimeException("Migration mismatch in {$name}, case {$i}");
                }
            }
        }
    };
    $migration->up();
    $check(false);
    $migration->down();
    $check(true);
    $migration->up();
    $check(false);
    echo "PostgreSQL migration, rollback and reapplication passed for both mount tables and all resource boundaries.\n";
} finally {
    DB::statement('SET search_path TO public');
    DB::statement('DROP SCHEMA '.$schema.' CASCADE');
}
