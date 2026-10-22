<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each person signs in to the back office, set by the administrator (owner request 2026-10-22): the company rule
 * (default), always the authenticator app, a code by WhatsApp or e-mail, or the password only. The phone number is
 * where WhatsApp codes go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('signin_method', 12)->default('default');
            $t->string('phone', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['signin_method', 'phone']);
        });
    }
};
