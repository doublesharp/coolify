<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/bootstrap.php';
$app->make(Kernel::class)->call('queue:work', [
    'connection' => 'redis', '--queue' => 'resource-lifecycle', '--sleep' => 1, '--tries' => 1, '--timeout' => 150,
]);
