<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // The owner: a minted token belongs to this user and never has more access than the user.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('issuer');
            $table->string('audience');
            $table->json('conditions');
            $table->json('abilities');
            $table->json('scopes')->nullable();
            $table->unsignedSmallInteger('token_lifetime_minutes')->default(10);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['issuer', 'audience']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Deleting a trusted workflow revokes the short-lived tokens it issued.
            $table->foreignId('trusted_workflow_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trusted_workflow_id');
        });
        Schema::dropIfExists('trusted_workflows');
    }
};
