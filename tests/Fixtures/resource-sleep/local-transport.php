<?php

// The Docker integration test executes the native SSH command batches locally.
// Only the transport and proxy container name are changed; Docker operations are real.

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as NativeProcess;

function resourceSleepLocalTransport(string $directory, string $proxyName): void
{
    config(['constants.ssh.mux_enabled' => false, 'constants.coolify.base_config_path' => $directory]);
    Process::fake(function (PendingProcess $process) use ($directory, $proxyName) {
        $command = (string) $process->command;
        if (str_contains($command, ' scp ')) {
            if (! preg_match("/'([^']+)' [^ ]+:'([^']+)'$/", $command, $matches)) {
                throw new RuntimeException('Unexpected test SCP command.');
            }
            $destination = str_replace('/tmp/coolify-upload-', $directory.'/upload-', $matches[2]);
            copy($matches[1], $destination);
            chmod($destination, 0600);

            return Process::result();
        }
        if (! is_string($process->input) || $process->input === '') {
            throw new RuntimeException('Unexpected command in Docker integration transport.');
        }
        $script = str_replace(['/tmp/coolify-upload-', 'coolify-proxy'], [$directory.'/upload-', $proxyName], $process->input);
        // The native proxy writer transfers Compose as base64 over SSH stdin.
        $script = preg_replace_callback("/echo '([A-Za-z0-9+\/=]+)' \\| (sudo )?base64 -d/", function ($match) use ($proxyName) {
            return "echo '".base64_encode(str_replace('coolify-proxy', $proxyName, base64_decode($match[1])))."' | ".($match[2] ?? '').'base64 -d';
        }, $script);
        $native = new NativeProcess(['/bin/bash', '-e', '-s'], timeout: 180);
        // This host test already has Docker access; model sudo's command execution without host elevation.
        $native->setInput('sudo() { "$@"; }'."\n".$script);
        $native->run();

        return Process::result($native->getOutput(), $native->getErrorOutput(), $native->getExitCode());
    });
}
