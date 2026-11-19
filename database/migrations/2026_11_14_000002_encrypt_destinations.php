<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        // Ciphertext can exceed the original URL column capacity.
        Schema::table('forward_targets', fn (Blueprint $t) => $t->text('url')->change());
        Schema::table('deliveries', fn (Blueprint $t) => $t->text('target_url')->change());
        foreach (['forward_targets' => 'url', 'deliveries' => 'target_url'] as $table => $column) {
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $column) {
                foreach ($rows as $row) {
                    try { Crypt::decryptString($row->$column); }
                    catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                        DB::table($table)->where('id', $row->id)->update([$column => Crypt::encryptString($row->$column)]);
                    }
                }
            });
        }
    }
    public function down(): void { /* Keep secrets encrypted on rollback. */ }
};
