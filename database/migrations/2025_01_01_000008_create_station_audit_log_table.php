<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('station_audit_log', static function (Blueprint $table): void {
            $table->id();
            $table->string('event', 50); // e.g., 'job.retry', 'queue.pause', 'batch.cancel'
            $table->string('actor_type', 50); // 'user', 'api_token', 'system'
            $table->string('actor_id')->nullable(); // User ID or token identifier
            $table->string('actor_name')->nullable(); // Display name
            $table->string('resource_type', 50); // 'job', 'batch', 'queue', 'worker'
            $table->string('resource_id')->nullable();
            $table->json('context')->nullable(); // Additional event data
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');

            $table->index('event');
            $table->index(['resource_type', 'resource_id']);
            $table->index('actor_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_audit_log');
    }
};
