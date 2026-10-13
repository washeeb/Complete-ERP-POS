<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pages and till calls that took longer than they should (System → Health), kept 30 days. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slow_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('tenant_id')->nullable();
            $t->uuid('user_id')->nullable();
            $t->string('method', 8);
            $t->string('path', 255); // the route, never the query string (it may hold search terms or personal data)
            $t->unsignedInteger('ms');
            $t->unsignedInteger('queries');
            $t->timestamp('created_at')->useCurrent();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slow_requests');
    }
};
