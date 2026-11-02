<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['local_persistent_volumes', 'local_file_volumes'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->boolean('is_read_only')->default(false);
            });
            $this->applicationMounts($name)->where('mount_path', 'like', '%:%')->eachById(function (object $mount) use ($name): void {
                [$path, $mode] = explode(':', $mount->mount_path, 2);
                $options = explode(',', $mode);
                if (array_intersect($options, ['ro', 'rw']) === []) {
                    return;
                }
                $remaining = array_values(array_diff($options, ['ro', 'rw']));
                DB::table($name)->where('id', $mount->id)->update([
                    'mount_path' => $path.($remaining === [] ? '' : ':'.implode(',', $remaining)),
                    'is_read_only' => in_array('ro', $options, true),
                ]);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['local_persistent_volumes', 'local_file_volumes'] as $name) {
            $this->applicationMounts($name)->where('is_read_only', true)->eachById(function (object $mount) use ($name): void {
                [$path, $mode] = array_pad(explode(':', $mount->mount_path, 2), 2, '');
                $options = $mode === '' ? [] : array_values(array_diff(explode(',', $mode), ['ro', 'rw']));
                DB::table($name)->where('id', $mount->id)->update([
                    'mount_path' => $path.':'.implode(',', ['ro', ...$options]),
                ]);
            });
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('is_read_only');
            });
        }
    }

    private function applicationMounts(string $table): Builder
    {
        return DB::table($table)->where('resource_type', 'App\\Models\\Application')
            ->whereIn('resource_id', DB::table('applications')->select('id')->where('build_pack', '!=', 'dockercompose'));
    }
};
