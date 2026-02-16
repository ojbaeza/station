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
        Schema::create('station_supervisors', static function (Blueprint $table): void {
            $table->string('id', 100)->primary();
            $table->string('name', 100);
            $table->string('hostname', 255);
            $table->unsignedInteger('pid');
            $table->string('status', 20)->default('running'); // running, paused, terminating
            $table->json('queues');
            $table->json('options')->nullable();
            $table->unsignedInteger('processes')->default(1);
            $table->unsignedInteger('jobs_processed')->default(0);
            $table->timestamp('last_heartbeat_at');
            $table->timestamps();

            $table->index('last_heartbeat_at');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_supervisors');
    }
};
