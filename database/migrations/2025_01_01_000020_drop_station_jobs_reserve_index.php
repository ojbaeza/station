<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Drop the composite index used by the unused reserve() method.
     * This index adds write overhead on every status UPDATE to station_jobs.
     * The simpler (status, created_at) index already serves dashboard queries.
     */
    public function up(): void
    {
        Schema::table('station_jobs', static function (Blueprint $table): void {
            $table->dropIndex('station_jobs_queue_status_priority_available_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('station_jobs', static function (Blueprint $table): void {
            $table->index(
                ['queue', 'status', 'priority', 'available_at'],
                'station_jobs_queue_status_priority_available_index',
            );
        });
    }
};
