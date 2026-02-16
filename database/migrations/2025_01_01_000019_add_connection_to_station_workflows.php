<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('station_workflows', static function (Blueprint $table): void {
            $table->string('connection', 100)->nullable()->after('definition_name');
            $table->index('connection');
        });
    }

    public function down(): void
    {
        Schema::table('station_workflows', static function (Blueprint $table): void {
            $table->dropIndex(['connection']);
            $table->dropColumn('connection');
        });
    }
};
