<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_sleep_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('resource_uuid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('request_activity')->default(false);
            $table->unsignedInteger('idle_timeout_seconds')->default(1800);
            $table->unsignedInteger('minimum_uptime_seconds')->default(60);
            $table->json('dependencies')->nullable();
            $table->text('webhooks')->nullable();
            $table->text('webhook_secret');
            $table->text('proxy_token');
            $table->string('state')->default('awake');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('woke_at')->nullable();
            $table->text('containers')->nullable();
            $table->text('proxy_snapshot')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_sleep_policies');
    }
};
