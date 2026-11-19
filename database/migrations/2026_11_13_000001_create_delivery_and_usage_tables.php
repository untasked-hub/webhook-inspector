<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('forward_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_until')->nullable();
            $table->timestamps();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capture_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // replay | forward
            $table->string('target_url', 2048);
            $table->string('status', 30); // queued | retrying | succeeded | failed | blocked
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['endpoint_id', 'id']);
            $table->index(['capture_id', 'id']);
        });

        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('response_excerpt')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamp('created_at');
            $table->index(['delivery_id', 'attempt_number']);
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->unsignedInteger('requests_count')->default(0);
            $table->unsignedInteger('quota_limit')->default(1000);
            $table->timestamps();
            $table->unique(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('delivery_attempts');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('forward_targets');
    }
};
