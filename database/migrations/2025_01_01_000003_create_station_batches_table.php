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
        Schema::create('station_batches', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 255)->nullable();
            $table->string('queue', 100)->default('default');
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed, cancelled
            $table->unsignedInteger('total_jobs')->default(0);
            $table->unsignedInteger('pending_jobs')->default(0);
            $table->unsignedInteger('processed_jobs')->default(0);
            $table->unsignedInteger('failed_jobs')->default(0);
            $table->unsignedInteger('allowed_failures')->default(0);
            $table->json('failed_job_ids')->nullable();
            $table->json('options')->nullable(); // then, catch, finally callbacks serialized
            $table->timestamp('started_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('finished_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_batches');
    }
};
