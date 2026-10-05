<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->string('last_hash', 64)->default(str_repeat('0', 64));
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('seq');
            $table->uuid('actor_id')->nullable();
            $table->string('actor_name');
            $table->string('source', 20); // web | api | pos | cli | system | sync
            $table->string('action', 100);
            $table->string('subject_type', 60);
            $table->uuid('subject_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->string('origin_node', 40);
            $table->jsonb('changes')->default('{}'); // {"field": [before, after]}
            $table->string('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('occurred_at', 6);
            $table->timestamp('received_at', 6);
            $table->string('prev_hash', 64);
            $table->string('hash', 64);
            $table->unique(['tenant_id', 'seq']);
            $table->index(['subject_type', 'subject_id']);
        });

        // Append-only at the database level: even a buggy service or a console session
        // using the app role cannot rewrite history. Full-rewrite protection by a DB
        // superuser needs external checkpoints (Phase 13).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only';
            END $$;
            CREATE TRIGGER audit_events_no_update BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_immutable();
            CREATE TRIGGER audit_events_no_truncate BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION audit_events_immutable();
        SQL);

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id'); // event id: receivers dedupe on it (made the PK below)
            $table->bigIncrements('seq'); // monotonic per node — the sync cursor peers track
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('origin_node', 40);
            $table->string('aggregate_type', 60);
            $table->uuid('aggregate_id');
            $table->unsignedInteger('aggregate_version');
            $table->string('event_type', 100);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->jsonb('payload');
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('occurred_at', 6);
            $table->timestamp('published_at', 6)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
        });
        DB::statement('ALTER TABLE outbox_events DROP CONSTRAINT outbox_events_pkey');
        DB::statement('ALTER TABLE outbox_events ADD PRIMARY KEY (id)');
        DB::statement('CREATE UNIQUE INDEX outbox_events_seq_unique ON outbox_events (seq)');
        DB::statement('CREATE INDEX outbox_events_unpublished ON outbox_events (seq) WHERE published_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        DB::unprepared('DROP TABLE IF EXISTS audit_events; DROP FUNCTION IF EXISTS audit_events_immutable();');
        Schema::dropIfExists('audit_chain_heads');
    }
};
