<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('station_metrics', static function (Blueprint $table): void {
            $table->index(['connection', 'recorded_at'], 'station_metrics_connection_recorded_idx');
        });

        Schema::table('station_failed_jobs', static function (Blueprint $table): void {
            $table->index(['queue', 'failed_at'], 'station_failed_jobs_queue_failed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('station_metrics', static function (Blueprint $table): void {
            $table->dropIndex('station_metrics_connection_recorded_idx');
        });

        Schema::table('station_failed_jobs', static function (Blueprint $table): void {
            $table->dropIndex('station_failed_jobs_queue_failed_idx');
        });
    }
};
