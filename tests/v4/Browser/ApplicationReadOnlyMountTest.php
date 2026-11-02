<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Browsable;

uses(RefreshDatabase::class, Browsable::class);

test('changes application mount access through the listbox and keeps the choice after reload', function () {
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    $stack = seedBrowserResourceStack();
    $application = createBrowserApplication($stack, ['build_pack' => 'dockerimage']);
    Bus::fake();
    Process::fake();
    $storage = $application->persistentStorages()->create(['name' => 'releases', 'mount_path' => '/releases']);
    loginAndSkipBoarding();
    $url = applicationConfigurationUrl($stack['project'], $stack['environment'], $application).'/persistent-storage';

    $page = visit($url);
    $page->assertSee('Mount access')
        ->click('[id="forms.'.$storage->id.'.isReadOnlyMount-trigger"]')
        ->click('Read only')
        ->assertSee('Storage updated successfully');

    expect($storage->refresh()->is_read_only)->toBeTrue();
    $page = visit($url);
    $page->assertSee('Read only')->assertNoJavaScriptErrors()
        ->screenshot(filename: 'application-mount-read-only');
    $page->click('[id="forms.'.$storage->id.'.isReadOnlyMount-trigger"]')
        ->click('Read and write')->assertSee('Storage updated successfully');
    expect($storage->refresh()->is_read_only)->toBeFalse();
});
