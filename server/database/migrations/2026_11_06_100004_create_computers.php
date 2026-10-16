<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Computers that used the back office: each browser gets a lasting ID the first time someone signs in from it, and
// an administrator names it ("Front desk PC"). The audit log then shows which computer did what.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->index()->constrained()->restrictOnDelete();
            $t->uuid('computer_key'); // the ID kept in the browser's ses_computer cookie
            $t->string('name', 80)->nullable();
            $t->timestamp('first_seen_at');
            $t->timestamp('last_seen_at');
            $t->string('last_ip', 45)->nullable();
            $t->jsonb('last_client')->nullable();
            $t->foreignUuid('last_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $t->string('last_user_name', 120)->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'computer_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computers');
    }
};
