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
        Schema::create('station_workflows', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('definition_id', 36)->index();
            $table->string('definition_name', 100)->index();
            $table->string('status', 20)->default('pending')->index();
            $table->string('current_step', 100)->nullable();
            $table->json('input')->nullable();
            $table->json('context')->nullable();
            $table->json('results')->nullable();
            $table->json('step_statuses')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate();

            // Indexes for common queries
            $table->index(['definition_name', 'status']);
            $table->index(['definition_name', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_workflows');
    }
};
