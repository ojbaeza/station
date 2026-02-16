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
        Schema::create('station_jobs', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('queue', 100);
            $table->string('job_class', 255);
            $table->longText('payload');
            $table->string('status', 20)->default('pending'); // pending, reserved, processing, completed, failed
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_tries')->default(3);
            $table->unsignedInteger('timeout')->default(60);
            $table->unsignedSmallInteger('priority')->default(0); // Higher = processed first
            $table->uuid('batch_id')->nullable()->index();
            $table->json('tags')->nullable();
            $table->string('worker_id', 100)->nullable();
            $table->unsignedInteger('memory_used')->nullable(); // bytes
            $table->unsignedInteger('processing_time')->nullable(); // milliseconds
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['queue', 'status', 'priority', 'available_at'], 'station_jobs_queue_status_priority_available_index');
            $table->index(['status', 'created_at']);
            $table->index('worker_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_jobs');
    }
};
