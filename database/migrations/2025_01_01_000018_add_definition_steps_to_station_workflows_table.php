<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('station_workflows', static function (Blueprint $table): void {
            $table->json('definition_steps')->nullable()->after('step_statuses');
        });
    }

    public function down(): void
    {
        Schema::table('station_workflows', static function (Blueprint $table): void {
            $table->dropColumn('definition_steps');
        });
    }
};
