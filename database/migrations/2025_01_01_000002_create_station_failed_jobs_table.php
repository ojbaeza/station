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
        Schema::create('station_failed_jobs', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('original_id')->nullable()->index(); // Reference to original job
            $table->string('queue', 100);
            $table->string('job_class', 255);
            $table->longText('payload');
            $table->longText('exception');
            $table->json('context')->nullable();
            $table->uuid('batch_id')->nullable()->index();
            $table->json('tags')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('failed_at');

            $table->index('queue');
            $table->index('job_class');
            $table->index('failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_failed_jobs');
    }
};
