<?php

namespace App\Services;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\ResourceSleepPolicy;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/** A managed file-provider route survives removal from Traefik's Docker provider. */
class ResourceSleepProxy
{
    private const MODULE = 'github.com/coollabsio/resource-sleep';

    private const API_URL = 'http://127.0.0.1:18081/api/rawdata';

    public function install(Server $server): void
    {
        Cache::lock('resource-sleep-proxy:'.$server->id, 120)->block(5, fn () => $this->installLocked($server));
    }

    private function installLocked(Server $server): void
    {
        if ($server->isSwarm() || $server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new RuntimeException('Request activity requires Traefik on standalone Docker.');
        }
        // Reuse the native proxy configuration, preserving custom flags and mounts.
        $path = $this->path($server);
        $configuration = GetProxyConfiguration::run($server);
        $config = parseProxyComposeYaml($configuration);
        if (! is_array($config) || ! isset($config['services']['traefik'])) {
            throw new RuntimeException('Request activity requires a managed Traefik service.');
        }
        $files = ['.traefik.yml', 'go.mod', 'resource_sleep.go'];
        $hash = $this->pluginHash();
        $updated = $this->managedConfiguration($server, $configuration);
        // The lifecycle control endpoint listens only inside the proxy container.
        instant_remote_process(['mkdir -p '.escapeshellarg($path.'/dynamic')], $server, maxAttempts: 1);
        $server->uploadProxyFile($path.'/dynamic/coolify-resource-sleep-api.yaml', Yaml::dump([
            'http' => ['routers' => ['coolify-resource-sleep-api' => [
                'entryPoints' => ['coolify-resource-sleep'], 'rule' => 'PathPrefix(`/api`)', 'service' => 'api@internal',
            ]]],
        ], 10, 2), 0600);
        if ($server->proxy->get('resource_sleep_plugin_hash') === $hash && parseProxyComposeYaml($updated) == $config) {
            return;
        }
        $directory = $path.'/plugins-local/src/'.self::MODULE;
        instant_remote_process(['mkdir -p '.escapeshellarg($directory)], $server, maxAttempts: 1);
        foreach ($files as $file) {
            $server->uploadProxyFile($directory.'/'.$file, file_get_contents(resource_path('proxy/resource-sleep/'.$file)));
        }
        SaveProxyConfiguration::run($server, $updated);
        instant_remote_process([
            'docker compose -f '.escapeshellarg($path.'/docker-compose.yml').' up -d --force-recreate --no-deps --wait traefik',
            ...connectProxyToNetworks($server),
        ], $server, timeout: 90, maxAttempts: 1);
        $server->proxy->resource_sleep_plugin_hash = $hash;
        $server->save();
    }

    public function prepare(ResourceSleepPolicy $policy, ?array $inventory = null): void
    {
        $server = Server::findOrFail($policy->server_id);
        // Only an explicit settings save installs/restarts the shared proxy.
        $configuration = GetProxyConfiguration::run($server);
        if ($server->proxy->get('resource_sleep_plugin_hash') !== $this->pluginHash()
            || parseProxyComposeYaml($configuration) != parseProxyComposeYaml($this->managedConfiguration($server, $configuration))) {
            throw new RuntimeException('Save request activity settings to apply the proxy update.');
        }
        $this->probe($policy);
        $resource = getResourceByUuid($policy->resource_uuid, $policy->team_id);
        if (! $resource) {
            throw new RuntimeException('Sleep resource is missing.');
        }
        $containers = $inventory[$policy->resource_uuid] ?? app(ResourceSleepDocker::class)->inventory($resource);
        if ($containers === []) {
            return;
        }
        // Docker provider discovery can lag an explicit proxy restart by a few seconds.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $raw = instant_remote_process(['docker exec coolify-proxy wget -qO- '.self::API_URL], $server, timeout: 5, maxAttempts: 1);
                $configuration = $this->configuration($policy, $containers, json_decode($raw ?? '', true, flags: JSON_THROW_ON_ERROR));
                break;
            } catch (RuntimeException $exception) {
                if ($attempt === 4) {
                    throw $exception;
                }
                sleep(1);
            }
        }
        $this->write($server, $policy, $configuration);
        $this->waitForRoutes($server, array_keys($configuration['http']['routers']), true);
        $policy->update(['proxy_snapshot' => $configuration]);
    }

    public function waitForBackends(ResourceSleepPolicy $policy, int $timeout = 75): void
    {
        $seconds = max(1, min(60, $timeout - 5));
        $commands = ['deadline=$(($(date +%s) + '.$seconds.'))'];
        foreach ($policy->proxy_snapshot['http']['services'] ?? [] as $service) {
            foreach ($service['loadBalancer']['servers'] ?? [] as $backend) {
                $url = parse_url($backend['url']);
                $host = escapeshellarg($url['host']);
                $port = (int) ($url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80));
                $commands = [...$commands,
                    "until docker exec coolify-proxy nc -z -w 1 $host $port; do",
                    '    if [ "$(date +%s)" -ge "$deadline" ]; then echo "Resource HTTP port did not become ready" >&2; exit 1; fi',
                    '    sleep 1',
                    'done',
                ];
            }
        }
        instant_remote_process(['bash -c '.escapeshellarg(implode("\n", $commands))], Server::findOrFail($policy->server_id), timeout: $timeout, maxAttempts: 1);
    }

    public function remove(ResourceSleepPolicy $policy): void
    {
        $server = Server::findOrFail($policy->server_id);
        $path = $this->path($server).'/dynamic/resource-sleep-'.$policy->resource_uuid.'.yaml';
        instant_remote_process(['rm -f '.escapeshellarg($path)], $server, maxAttempts: 1);
        if ($policy->proxy_snapshot) {
            $this->waitForRoutes($server, array_keys($policy->proxy_snapshot['http']['routers']), false);
        }
        $policy->update(['proxy_snapshot' => null]);
    }

    private function write(Server $server, ResourceSleepPolicy $policy, array $configuration): void
    {
        $directory = $this->path($server).'/dynamic';
        instant_remote_process(['mkdir -p '.escapeshellarg($directory)], $server, maxAttempts: 1);
        $server->uploadProxyFile($directory.'/resource-sleep-'.$policy->resource_uuid.'.yaml', Yaml::dump($configuration, 30, 2), 0600);
    }

    /** Clone the effective router and its Docker references, including authentication and TLS. */
    public function configuration(ResourceSleepPolicy $policy, array $containers, array $raw): array
    {
        $http = ['routers' => [], 'services' => [], 'middlewares' => []];
        $prefix = 'resource-sleep-'.$policy->resource_uuid.'-';
        $names = [];
        $addresses = [];
        foreach ($containers as $container) {
            $name = ltrim($container['name'], '/');
            if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]+\z/D', $name)) {
                throw new RuntimeException('Invalid container DNS name.');
            }
            $addresses[$name] = $name;
            foreach ($container['networks'] ?? [] as $network) {
                foreach (['IPAddress', 'GlobalIPv6Address'] as $field) {
                    if (! empty($network[$field])) {
                        $addresses[$network[$field]] = $name;
                    }
                }
            }
            foreach (array_keys($container['labels'] ?? []) as $label) {
                if (preg_match('/^traefik\.http\.routers\.([^.]+)\./', $label, $match)) {
                    $names[$match[1].'@docker'] = true;
                }
            }
        }
        $clean = fn ($object) => array_diff_key($object, array_flip(['status', 'usedBy', 'using', 'serverStatus', 'errors', 'error', 'provider', 'name']));
        $copy = function (string $type, string $reference) use (&$copy, &$http, $raw, $prefix, $clean, $addresses): string {
            if (! str_contains($reference, '@')) {
                $reference .= '@docker';
            }
            if (! str_ends_with($reference, '@docker')) {
                return $reference;
            }
            $key = $prefix.substr(hash('sha256', $type.$reference), 0, 20);
            if (isset($http[$type][$key])) {
                return $key;
            }
            $object = $raw[$type][$reference] ?? null;
            if (! is_array($object) || ($object['status'] ?? 'enabled') !== 'enabled') {
                throw new RuntimeException('A resource proxy dependency is unavailable.');
            }
            $http[$type][$key] = [];
            $object = $clean($object);
            if ($type === 'middlewares' && isset($object['chain']['middlewares'])) {
                $object['chain']['middlewares'] = array_map(fn ($ref) => $copy('middlewares', $ref), $object['chain']['middlewares']);
            }
            if ($type === 'services') {
                foreach ($object['loadBalancer']['servers'] ?? [] as $index => $backend) {
                    $url = parse_url($backend['url'] ?? '');
                    $host = trim($url['host'] ?? '', '[]');
                    if (! isset($addresses[$host]) || ! in_array($url['scheme'] ?? '', ['http', 'https'], true) || isset($url['user'])) {
                        throw new RuntimeException('A sleep route must use only the retained resource containers.');
                    }
                    $object['loadBalancer']['servers'][$index]['url'] = preg_replace('~^(https?://)\[[^]]+\]|^(https?://)[^/:]+~', '${1}${2}'.$addresses[$host], $backend['url']);
                }
                foreach (['weighted.services', 'mirroring.mirrors'] as $path) {
                    if (data_get($object, $path)) {
                        data_set($object, $path, array_map(function ($entry) use ($copy) {
                            $entry['name'] = $copy('services', $entry['name']);

                            return $entry;
                        }, data_get($object, $path)));
                    }
                }
                if (isset($object['mirroring']['service'])) {
                    $object['mirroring']['service'] = $copy('services', $object['mirroring']['service']);
                }
            }
            $http[$type][$key] = $object;

            return $key;
        };
        $middleware = $prefix.'activity';
        $http['middlewares'][$middleware] = ['plugin' => ['resourcesleep' => [
            'callbackURL' => $this->callbackUrl($policy),
            'token' => $policy->proxy_token, 'timeoutSeconds' => 180,
        ]]];
        foreach (array_keys($names) as $name) {
            $router = $raw['routers'][$name] ?? null;
            if (! is_array($router) || ($router['status'] ?? '') !== 'enabled') {
                throw new RuntimeException('Deploy the resource before enabling request activity.');
            }
            $router = $clean($router);
            $router['service'] = $copy('services', $router['service']);
            $router['middlewares'] = [...array_map(fn ($ref) => $copy('middlewares', $ref), $router['middlewares'] ?? []), $middleware];
            $router['priority'] = ($router['priority'] ?? strlen($router['rule'])) + 1;
            $http['routers'][$prefix.substr(hash('sha256', $name), 0, 20)] = $router;
        }
        if ($http['routers'] === []) {
            throw new RuntimeException('Request activity requires a resource with HTTP routes.');
        }

        return ['http' => $http];
    }

    private function waitForRoutes(Server $server, array $routers, bool $present): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $output = instant_remote_process(['docker exec coolify-proxy wget -qO- '.self::API_URL], $server, timeout: 5, maxAttempts: 1);
            $raw = json_decode($output ?? '', true, flags: JSON_THROW_ON_ERROR);
            $ready = true;
            foreach ($routers as $name) {
                $loaded = ($raw['routers'][$name.'@file']['status'] ?? null) === 'enabled';
                $ready = $ready && ($loaded === $present);
            }
            if ($ready) {
                return;
            }
            sleep(1);
        }
        throw new RuntimeException('The proxy did not apply the resource sleep routes.');
    }

    /** Preserve the plugin when native proxy settings are reset or edited. */
    public function managedConfiguration(Server $server, string $configuration): string
    {
        $config = parseProxyComposeYaml($configuration);
        if (! isset($config['services']['traefik'])) {
            throw new RuntimeException('Request activity requires a managed Traefik service.');
        }
        $service = &$config['services']['traefik'];
        $service['command'] = updateTaggedProxyComposeList($service['command'] ?? [], function ($items) {
            $items = array_values(array_filter($items, fn ($flag) => strtolower($flag) !== '--api=false'));
            $items = array_map(fn ($flag) => str_starts_with(strtolower($flag), '--entrypoints.coolify-resource-sleep.address=')
                ? '--entrypoints.coolify-resource-sleep.address=127.0.0.1:18081' : $flag, $items);
            $items[] = '--api=true';
            $items[] = '--entrypoints.coolify-resource-sleep.address=127.0.0.1:18081';
            $items[] = '--experimental.localplugins.resourcesleep.modulename='.self::MODULE;
            foreach (['http', 'https'] as $entry) {
                if (! collect($items)->contains(fn ($flag) => str_starts_with(strtolower($flag), '--entrypoints.'.$entry.'.address='))) {
                    continue;
                }
                $prefix = '--entrypoints.'.$entry.'.transport.respondingtimeouts.readtimeout=';
                $found = false;
                foreach ($items as &$flag) {
                    if (! str_starts_with(strtolower($flag), $prefix)) {
                        continue;
                    }
                    $found = true;
                    $value = substr($flag, strlen($prefix));
                    // Zero disables the timeout; preserve explicit values longer than our wait.
                    $seconds = preg_match('/^(\d+(?:\.\d+)?)(ms|s|m|h)?$/i', $value, $parts)
                        ? (float) $parts[1] * match (strtolower($parts[2] ?? 's')) {
                            'ms' => .001, 'm' => 60, 'h' => 3600, default => 1
                        }
                    : -1;
                    if ($seconds != 0 && $seconds < 240) {
                        $flag = $prefix.'240s';
                    }
                }
                unset($flag);
                if (! $found) {
                    $items[] = $prefix.'240s';
                }
            }

            return array_values(array_unique($items));
        }, 'Invalid Traefik commands.');
        $mount = $this->path($server).'/plugins-local:/plugins-local:ro';
        $service['volumes'] = updateTaggedProxyComposeList($service['volumes'] ?? [], fn ($items) => array_values(array_unique([...$items, $mount])), 'Invalid proxy volumes.');

        return Yaml::dump($config, 20, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    /** Verify the scoped callback from the actual proxy before publishing a higher priority route. */
    public function probe(ResourceSleepPolicy $policy): void
    {
        $server = Server::findOrFail($policy->server_id);
        $name = 'resource-sleep-probe-'.bin2hex(random_bytes(8)).'.header';
        $path = $this->path($server).'/'.$name;
        try {
            // The credential is transferred as a private file, never included in command output.
            $server->uploadProxyFile($path, 'Authorization: Bearer '.$policy->proxy_token, 0600);
            $body = json_encode(['phase' => 'probe', 'request_id' => str_repeat('0', 32)]);
            $script = 'wget -qO- -T 5 --header "$(cat '.escapeshellarg('/traefik/'.$name).')" --header '.escapeshellarg('Content-Type: application/json').' --post-data '.escapeshellarg($body).' '.escapeshellarg($this->callbackUrl($policy));
            $output = instant_remote_process(['docker exec coolify-proxy sh -c '.escapeshellarg($script)], $server, timeout: 10, maxAttempts: 1);
            $result = json_decode($output ?? '', true);
            if (! is_array($result) || ! is_bool($result['ready'] ?? null)) {
                throw new RuntimeException('Invalid callback response.');
            }
        } catch (\Throwable) {
            throw new RuntimeException('Coolify must be reachable from coolify-proxy. Configure a reachable instance URL before enabling request activity.');
        } finally {
            instant_remote_process(['rm -f '.escapeshellarg($path)], $server, throwError: false, timeout: 5, maxAttempts: 1);
        }
    }

    private function callbackUrl(ResourceSleepPolicy $policy): string
    {
        return rtrim(instanceSettings()->fqdn ?: config('app.url'), '/').'/api/resource-sleep/'.$policy->resource_uuid.'/activity';
    }

    private function pluginHash(): string
    {
        return hash('sha256', implode('', array_map(fn ($file) => file_get_contents(resource_path('proxy/resource-sleep/'.$file)), ['.traefik.yml', 'go.mod', 'resource_sleep.go'])));
    }

    private function path(Server $server): string
    {
        $path = $server->proxyPath();
        if (! preg_match('~\A/[a-zA-Z0-9/_.-]+\z~D', $path)) {
            throw new RuntimeException('Unsupported proxy path.');
        }

        return $path;
    }
}
