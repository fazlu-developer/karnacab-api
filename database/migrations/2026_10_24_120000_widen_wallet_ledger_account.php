<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wallet_ledger') && Schema::hasColumn('wallet_ledger', 'account')) {
            DB::statement('ALTER TABLE wallet_ledger MODIFY COLUMN account VARCHAR(32) NULL');
        }
        if (Schema::hasTable('wallets') && Schema::hasColumn('wallets', 'owner_type')) {
            DB::statement('ALTER TABLE wallets MODIFY COLUMN owner_type VARCHAR(32) NULL');
        }
        if (Schema::hasTable('wallets') && Schema::hasColumn('wallets', 'updated_at')) {
            DB::statement('ALTER TABLE wallets MODIFY COLUMN updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)');
        }
    }

    public function down(): void
    {
    }
};
