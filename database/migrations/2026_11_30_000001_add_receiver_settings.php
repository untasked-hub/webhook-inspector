<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Earlier preview builds applied these fields under an older migration name.
        // Reconcile each field without overwriting any existing endpoint settings.
        foreach (['response_status', 'response_content_type', 'response_body', 'signature_provider', 'signing_secret'] as $column) {
            if (Schema::hasColumn('endpoints', $column)) {
                continue;
            }
            Schema::table('endpoints', function (Blueprint $table) use ($column) {
                match ($column) {
                    'response_status' => $table->unsignedSmallInteger($column)->default(202),
                    'response_content_type' => $table->string($column, 32)->default('application/json'),
                    'signature_provider' => $table->string($column, 16)->default('auto'),
                    default => $table->text($column)->nullable(),
                };
            });
        }
    }

    public function down(): void
    {
        Schema::table('endpoints', function (Blueprint $table) {
            $table->dropColumn(['response_status', 'response_content_type', 'response_body', 'signature_provider', 'signing_secret']);
        });
    }
};
