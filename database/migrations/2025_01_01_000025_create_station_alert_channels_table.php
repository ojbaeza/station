<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('station_alert_channels', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type', 50);
            $table->boolean('enabled')->default(true);
            $table->json('config');
            $table->timestamps();

            $table->index('type');
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_alert_channels');
    }
};
