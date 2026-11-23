<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('billing_accounts', 'cancel_at_period_end')) {
                $table->boolean('cancel_at_period_end')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('billing_accounts', 'cancel_at_period_end')) {
                $table->dropColumn('cancel_at_period_end');
            }
        });
    }
};
