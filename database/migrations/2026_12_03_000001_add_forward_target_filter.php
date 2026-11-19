<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forward_targets', function (Blueprint $table) {
            $table->text('filter')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forward_targets', function (Blueprint $table) {
            $table->dropColumn('filter');
        });
    }
};
