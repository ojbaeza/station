<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('station_alert_rules', static function (Blueprint $table): void {
            $table->renameColumn('channels', 'channel_ids');
        });

        // Drop notify_url if it exists (migration 000024 was never released)
        if (Schema::hasColumn('station_alert_rules', 'notify_url')) {
            Schema::table('station_alert_rules', static function (Blueprint $table): void {
                $table->dropColumn('notify_url');
            });
        }
    }

    public function down(): void
    {
        Schema::table('station_alert_rules', static function (Blueprint $table): void {
            $table->renameColumn('channel_ids', 'channels');
        });
    }
};
