<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', fn (Blueprint $t) => $t->string('sync_group', 20)->nullable());
        DB::statement('CREATE INDEX outbox_events_group_seq ON outbox_events (sync_group, seq)');

        // The other nodes this node exchanges changes with. On HQ: its branch servers. On a branch: HQ.
        Schema::create('sync_peers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('node_id', 40);            // the peer's ERP_NODE_ID
            $t->string('name');
            $t->string('role', 10);               // hq | branch (the peer's role)
            $t->string('base_url')->nullable();   // set on the side that calls (branch → HQ)
            $t->text('secret');                   // shared HMAC secret, encrypted at rest
            $t->jsonb('groups');                  // {"catalog": "auto"|"manual"|"off", ...}
            $t->unsignedBigInteger('last_pushed_seq')->default(0); // our outbox seq the peer has acknowledged
            $t->unsignedBigInteger('last_pulled_seq')->default(0); // the peer's outbox seq we have received
            $t->timestamp('last_success_at')->nullable();
            $t->timestamp('last_attempt_at')->nullable();
            $t->text('last_error')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'node_id']);
        });

        // Every event received from another node, once (event id). Staged = waiting for a person to
        // preview and accept (manual groups); failed = could not apply yet (retried, and reviewed).
        Schema::create('sync_inbox', function (Blueprint $t) {
            $t->uuid('id')->primary(); // = the event id at its origin
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('peer_id')->constrained('sync_peers')->restrictOnDelete();
            $t->string('origin_node', 40);
            $t->unsignedBigInteger('origin_seq');
            $t->string('sync_group', 20)->nullable();
            $t->string('aggregate_type', 60);
            $t->uuid('aggregate_id');
            $t->string('event_type', 100);
            $t->jsonb('payload');
            $t->string('status', 12); // staged | applied | failed | rejected
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('occurred_at', 6);
            $t->timestamp('received_at', 6);
            $t->timestamp('applied_at', 6)->nullable();
            $t->uuid('decided_by')->nullable();
            $t->index(['peer_id', 'status', 'origin_seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_inbox');
        Schema::dropIfExists('sync_peers');
        DB::statement('DROP INDEX IF EXISTS outbox_events_group_seq');
        Schema::table('outbox_events', fn (Blueprint $t) => $t->dropColumn('sync_group'));
    }
};
