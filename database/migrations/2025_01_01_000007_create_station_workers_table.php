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
        Schema::create('station_workers', static function (Blueprint $table): void {
            $table->string('id', 100)->primary();
            $table->string('supervisor_id', 100)->index();
            $table->string('hostname', 255);
            $table->unsignedInteger('pid');
            $table->string('status', 20)->default('idle'); // idle, processing, paused
            $table->string('queue', 100);
            $table->uuid('current_job_id')->nullable();
            $table->unsignedInteger('memory_usage')->default(0); // bytes
            $table->unsignedInteger('jobs_processed')->default(0);
            $table->timestamp('last_heartbeat_at');
            $table->timestamp('started_at');
            $table->timestamps();

            $table->index('last_heartbeat_at');
            $table->index(['status', 'queue']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_workers');
    }
};
