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
        Schema::create('station_job_events', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('job_id')->index();
            $table->string('event', 30); // dispatched, reserved, processing, completed, failed, retrying
            $table->string('worker_id', 100)->nullable();
            $table->unsignedInteger('attempt')->nullable();
            $table->text('message')->nullable(); // Error message for failures
            $table->json('context')->nullable(); // Additional event data
            $table->timestamp('occurred_at');

            $table->index(['job_id', 'occurred_at']);
            $table->index('occurred_at'); // For cleanup
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_job_events');
    }
};
