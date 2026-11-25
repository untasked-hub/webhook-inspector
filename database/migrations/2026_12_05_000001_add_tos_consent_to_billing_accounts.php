<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('billing_accounts', 'tos_accepted_at')) {
                $table->timestamp('tos_accepted_at')->nullable()->after('cancel_at_period_end');
            }
            if (!Schema::hasColumn('billing_accounts', 'tos_version')) {
                $table->string('tos_version', 64)->nullable()->after('tos_accepted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('billing_accounts', 'tos_version')) {
                $table->dropColumn('tos_version');
            }
            if (Schema::hasColumn('billing_accounts', 'tos_accepted_at')) {
                $table->dropColumn('tos_accepted_at');
            }
        });
    }
};
