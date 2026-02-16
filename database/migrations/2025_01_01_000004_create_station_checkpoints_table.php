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
        Schema::create('station_checkpoints', static function (Blueprint $table): void {
            $table->uuid('job_id')->primary();
            $table->longText('data'); // JSON or encrypted JSON
            $table->boolean('encrypted')->default(false);
            $table->timestamps();

            $table->index('updated_at'); // For cleanup
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_checkpoints');
    }
};
