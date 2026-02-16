<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('station_alert_rules', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type', 50);
            $table->boolean('enabled')->default(true);
            $table->json('condition');
            $table->unsignedInteger('window')->default(300);
            $table->json('channels');
            $table->unsignedInteger('cooldown')->default(300);
            $table->json('metadata')->nullable();
            $table->string('source', 20)->default('config');
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'type']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_alert_rules');
    }
};
