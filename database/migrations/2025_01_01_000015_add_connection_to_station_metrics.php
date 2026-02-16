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
        Schema::table('station_metrics', static function (Blueprint $table): void {
            $table->string('connection', 50)->nullable()->after('queue')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('station_metrics', static function (Blueprint $table): void {
            $table->dropIndex(['connection']);
            $table->dropColumn('connection');
        });
    }
};
