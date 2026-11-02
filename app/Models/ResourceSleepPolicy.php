<?php

namespace App\Models;

use App\Services\ResourceSleep;
use Illuminate\Database\Eloquent\Model;

class ResourceSleepPolicy extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['webhook_secret', 'proxy_token', 'webhooks', 'proxy_snapshot', 'containers'];

    public function configuration(): array
    {
        return collect([...ResourceSleep::SETTINGS, 'webhook_secret'])
            ->mapWithKeys(fn ($key) => [$key => $this->{$key}])->all();
    }

    protected function casts(): array
    {
        return [
            'activity_version' => 'integer', 'enabled' => 'boolean', 'request_activity' => 'boolean',
            'idle_timeout_seconds' => 'integer', 'minimum_uptime_seconds' => 'integer',
            'dependencies' => 'array', 'containers' => 'encrypted:array',
            'webhooks' => 'encrypted:array', 'proxy_snapshot' => 'encrypted:array',
            'webhook_secret' => 'encrypted', 'proxy_token' => 'encrypted',
            'last_activity_at' => 'datetime', 'woke_at' => 'datetime',
        ];
    }
}
