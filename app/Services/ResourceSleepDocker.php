<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Retains existing containers, volumes and images; never deploys or pulls on wake. */
class ResourceSleepDocker
{
    public function inventory(Model $resource): array
    {
        $server = app(ResourceSleep::class)->server($resource);
        $rows = match (true) {
            $resource instanceof Application => getCurrentApplicationContainerStatus($server, $resource),
            $resource instanceof Service => getCurrentServiceContainerStatus($server, $resource),
            default => getCurrentDatabaseContainerStatus($server, $resource),
        };
        $ids = $this->ids($rows->pluck('ID')->all());
        if ($ids === []) {
            return [];
        }
        // Do not read environment variables, mounts or credentials from inspect.
        $template = '{"id":{{json .Id}},"name":{{json .Name}},"labels":{{json .Config.Labels}},"networks":{{json .NetworkSettings.Networks}},"state":{{json .State}}}';
        $output = instant_remote_process(['docker inspect --format '.escapeshellarg($template).' '.implode(' ', $ids)], $server, maxAttempts: 1);

        return collect(explode("\n", trim($output ?? '')))->filter()->map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR))->all();
    }

    public function stop(Server $server, array $ids, int $timeout = 90): void
    {
        $ids = $this->ids($ids);
        if ($ids !== []) {
            instant_remote_process(['docker stop --time 30 '.implode(' ', $ids)], $server, timeout: min(90, $timeout), maxAttempts: 1);
        }
    }

    public function start(Server $server, array $ids, int $timeout = 120): void
    {
        $ids = $this->ids($ids);
        if ($ids === []) {
            return;
        }
        $attempts = max(1, (int) floor($timeout / 2));
        $containers = implode(' ', $ids);
        $health = escapeshellarg('{{.State.Running}} {{if .State.Health}}{{.State.Health.Status}}{{else}}healthy{{end}}');
        // One remote call, a bounded wait, and no background processes on the host.
        $commands = [
            "docker start $containers",
            "for container in $containers; do",
            '    ready=false',
            "    for attempt in $(seq 1 $attempts); do",
            "        state=$(docker inspect --format $health \"\$container\")",
            '        if [ "$state" = "true healthy" ]; then ready=true; break; fi',
            '        if [ "$state" = "false healthy" ] || [ "$state" = "true unhealthy" ]; then break; fi',
            '        sleep 2',
            '    done',
            '    if [ "$ready" != true ]; then echo "Container did not become healthy" >&2; exit 1; fi',
            'done',
        ];
        instant_remote_process(['bash -c '.escapeshellarg(implode("\n", $commands))], $server, timeout: $timeout, maxAttempts: 1);
    }

    private function ids(array $ids): array
    {
        foreach ($ids as $id) {
            if (! is_string($id) || ! preg_match('/\A[a-f0-9]{12,64}\z/D', $id)) {
                throw new RuntimeException('Invalid container identity.');
            }
        }

        return array_values(array_unique($ids));
    }
}
