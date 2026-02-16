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
        Schema::create('station_kafka_delayed_jobs', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->timestamp('available_at')->index();
            $table->timestamp('created_at');

            // Composite index for efficient delayed job migration
            $table->index(['queue', 'available_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_kafka_delayed_jobs');
    }
};
