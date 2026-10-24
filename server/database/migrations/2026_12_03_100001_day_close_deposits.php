<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close the day → bank (owner request 2026-10-24): the cash taken to the bank is recorded as money moved from the tills'
 * cash to the chosen bank account, so the books show it and the bank reconciliation can find it on the statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_closes', function (Blueprint $t) {
            $t->foreignUuid('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('deposit_transfer_id')->nullable()->constrained('money_transfers')->restrictOnDelete();
            $t->index('bank_account_id');
            $t->index('deposit_transfer_id');
        });
    }

    public function down(): void
    {
        Schema::table('day_closes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('deposit_transfer_id');
            $t->dropConstrainedForeignId('bank_account_id');
        });
    }
};
