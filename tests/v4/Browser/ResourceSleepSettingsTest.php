<?php

use App\Models\EmailNotificationSettings;
use App\Models\ResourceSleepPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Browsable;

uses(RefreshDatabase::class, Browsable::class);

test('saves automatic sleep settings and preserves them after navigation', function () {
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    $stack = seedBrowserResourceStack();
    // The first-run reminder otherwise covers Save on this long settings page.
    EmailNotificationSettings::where('team_id', 0)->update(['smtp_enabled' => true]);
    $application = createBrowserApplication($stack, ['build_pack' => 'dockerimage']);
    Bus::fake();
    Process::fake();
    loginAndSkipBoarding();
    $url = applicationConfigurationUrl($stack['project'], $stack['environment'], $application).'/advanced';
    $page = visit($url);
    $page->assertSee('Automatic sleep')->check('input[id^="enabled-"]')->fill('idleMinutes', '5')
        ->click('[data-settings-section-title="Automatic sleep"] button[type="submit"]')->assertSee('Sleep settings saved.');
    $policy = ResourceSleepPolicy::where('resource_uuid', $application->uuid)->firstOrFail();
    expect($policy->enabled)->toBeTrue()->and($policy->idle_timeout_seconds)->toBe(300);
    $page = visit($url);
    $page->assertChecked('input[id^="enabled-"]')->assertValue('idleMinutes', '5')->assertNoJavaScriptErrors()
        ->screenshot(filename: 'resource-sleep-settings');
});
