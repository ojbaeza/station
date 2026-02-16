<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('station_alert_history', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('rule_id');
            $table->string('rule_name');
            $table->string('type', 50);
            $table->string('severity', 20);
            $table->text('message');
            $table->json('context')->nullable();
            $table->json('channels_notified')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('rule_id');
            $table->index('type');
            $table->index('created_at');
            $table->index(['rule_id', 'created_at']);
            $table->index('resolved');

            $table->foreign('rule_id')
                ->references('id')
                ->on('station_alert_rules')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_alert_history');
    }
};
