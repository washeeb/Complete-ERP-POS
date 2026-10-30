<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal vaults (owner request 2026-10-30): each teller holds her own vault (cash tray) and a till opens only on a vault
 * she holds, or one a manager lends her for the day, so nobody sells in someone else's name. A shift records its vault;
 * with the light flow a manager recounts it blind after the close. Staff cards: a teller signs in by scanning her card
 * (then her PIN) instead of typing a username.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaults', function (Blueprint $t) {
            $t->foreignUuid('holder_id')->nullable()->after('shift_template_id')->constrained('users')->nullOnDelete();
            $t->index('holder_id');
        });
        // A personal vault is a tray of the branch (any till) or of one till; per-till-per-shift vaults keep both.
        DB::statement('ALTER TABLE vaults ALTER COLUMN till_id DROP NOT NULL');
        DB::statement('ALTER TABLE vaults ALTER COLUMN shift_template_id DROP NOT NULL');

        // A manager lends a vault to someone else for one day (the holder is away, or cover for a shift).
        Schema::create('vault_covers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('vault_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $t->string('user_name');
            $t->date('business_date');
            $t->foreignUuid('granted_by')->constrained('users')->restrictOnDelete();
            $t->string('granted_by_name');
            $t->string('reason');
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['vault_id', 'business_date']);
            $t->index('tenant_id');
            $t->index('branch_id');
            $t->index('vault_id');
            $t->index(['user_id', 'business_date']);
            $t->index('granted_by');
        });

        Schema::table('shifts', function (Blueprint $t) {
            $t->foreignUuid('vault_id')->nullable()->constrained()->nullOnDelete();
            $t->index('vault_id');
            // Light flow: the manager's blind recount after the teller's close. The shift's over/short is posted once checked.
            $t->bigInteger('checked_count_minor')->nullable();
            $t->jsonb('checked_denoms')->nullable();
            $t->unsignedSmallInteger('check_recounts')->default(0);
            $t->foreignUuid('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index('checked_by');
            $t->string('checked_by_name')->nullable();
            $t->timestampTz('checked_at')->nullable();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('badge_hash', 64)->nullable()->unique(); // sha256 of the staff card's code; a new card replaces it
            $t->timestampTz('badge_issued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['badge_hash', 'badge_issued_at']));
        Schema::table('shifts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('checked_by');
            $t->dropConstrainedForeignId('vault_id');
            $t->dropColumn(['checked_count_minor', 'checked_denoms', 'check_recounts', 'checked_by_name', 'checked_at']);
        });
        Schema::dropIfExists('vault_covers');
        Schema::table('vaults', fn (Blueprint $t) => $t->dropConstrainedForeignId('holder_id'));
    }
};
