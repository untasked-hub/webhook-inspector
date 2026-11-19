<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
        });
        Schema::create('endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('token', 48)->unique();
            $table->unsignedSmallInteger('retention_days')->default(7);
            $table->timestamps();
        });
        Schema::create('captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20);
            $table->string('content_type', 200);
            $table->unsignedInteger('size');
            $table->longText('payload');
            $table->timestamp('received_at');
            $table->timestamp('expires_at')->index();
            $table->index(['endpoint_id', 'id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('captures');
        Schema::dropIfExists('endpoints');
        Schema::dropIfExists('projects');
    }
};
