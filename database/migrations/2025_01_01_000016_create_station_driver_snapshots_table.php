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
        Schema::create('station_driver_snapshots', static function (Blueprint $table): void {
            $table->id();
            $table->string('connection', 50);
            $table->unsignedInteger('queue_size')->default(0);
            $table->unsignedBigInteger('memory_bytes')->default(0);
            $table->unsignedInteger('consumers')->default(0);
            $table->decimal('ops_rate', 10, 2)->default(0);
            $table->timestamp('recorded_at');

            $table->index(['connection', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_driver_snapshots');
    }
};
