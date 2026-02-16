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
        Schema::create('station_metrics', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue', 100);
            $table->unsignedInteger('jobs_processed')->default(0);
            $table->unsignedInteger('jobs_failed')->default(0);
            $table->unsignedInteger('jobs_pending')->default(0);
            $table->unsignedInteger('avg_processing_time')->default(0); // milliseconds
            $table->unsignedInteger('avg_wait_time')->default(0); // milliseconds
            $table->unsignedInteger('peak_memory')->default(0); // bytes
            $table->unsignedInteger('active_workers')->default(0);
            $table->timestamp('recorded_at');

            $table->index(['queue', 'recorded_at']);
            $table->index('recorded_at'); // For cleanup
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_metrics');
    }
};
