<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sensors (interface stage 5; paid add-on "iot"): fridges, cold rooms, freezers, rooms (temperature, humidity), power
 * and doors. Readings arrive from the sensor itself (its secret address), or from a sensor cloud (SensorPush, Tuya)
 * read every few minutes. A reading out of range for longer than the sensor's delay — or a sensor gone silent — is an
 * alarm: people on duty are told (bell, WhatsApp / SMS) until someone acknowledges it with what was done; a fridge's
 * alarm opens a breakdown work order on its equipment. Every reading is kept, with a daily summary and signed checks,
 * for the monthly temperature log inspectors ask for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iot_connections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('provider', 12);              // sensorpush | tuya
            $t->string('name');
            $t->text('credentials');                 // encrypted JSON
            $t->string('region', 20)->nullable();    // Tuya data centre (eu, us, in, cn)
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_polled_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['tenant_id', 'is_active']);
        });

        Schema::create('iot_sensors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->string('name');
            $t->string('kind', 10);                  // fridge | cold_room | freezer | room | power | door
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('asset_id')->nullable()->constrained('maintenance_assets')->nullOnDelete();
            $t->string('connection', 12)->default('direct'); // direct (posts itself) | sensorpush | tuya
            $t->foreignUuid('connection_id')->nullable()->constrained('iot_connections')->nullOnDelete();
            $t->string('external_id', 120)->nullable(); // the device id in the sensor cloud
            $t->string('token', 64)->nullable();     // the secret in the address a direct sensor posts to
            $t->decimal('min_temp', 5, 1)->nullable();
            $t->decimal('max_temp', 5, 1)->nullable();
            $t->unsignedTinyInteger('min_humidity')->nullable();
            $t->unsignedTinyInteger('max_humidity')->nullable();
            $t->unsignedSmallInteger('delay_minutes')->default(10);  // out of range this long before an alarm (a door opened briefly is not one)
            $t->unsignedSmallInteger('silent_minutes')->default(30); // no reading this long: the sensor is silent
            $t->timestamp('out_since')->nullable();  // when the current out-of-range reading began
            $t->string('out_kind', 12)->nullable();
            $t->timestamp('last_reading_at')->nullable();
            $t->decimal('last_temp', 5, 1)->nullable();
            $t->unsignedTinyInteger('last_humidity')->nullable();
            $t->boolean('last_power')->nullable();
            $t->boolean('last_door_open')->nullable();
            $t->unsignedTinyInteger('battery')->nullable();
            $t->string('status', 8)->default('waiting'); // waiting (no reading yet) | ok | alarm | silent | off
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->unique('token');
            $t->index('branch_id');
            $t->index('asset_id');
            $t->index('connection_id');
            $t->index(['connection_id', 'external_id']);
        });

        Schema::create('iot_readings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('sensor_id')->constrained('iot_sensors')->restrictOnDelete();
            $t->timestamp('read_at');
            $t->decimal('temperature', 5, 1)->nullable();
            $t->unsignedTinyInteger('humidity')->nullable();
            $t->boolean('power_on')->nullable();
            $t->boolean('door_open')->nullable();
            $t->unsignedTinyInteger('battery')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['sensor_id', 'read_at']); // a reading sent twice is kept once
            $t->index('tenant_id');
        });

        // One row a day per sensor: lowest, highest, average, readings, minutes out of range (the log and the charts).
        Schema::create('iot_days', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('sensor_id')->constrained('iot_sensors')->restrictOnDelete();
            $t->date('day');
            $t->decimal('min_temp', 5, 1)->nullable();
            $t->decimal('max_temp', 5, 1)->nullable();
            $t->decimal('sum_temp', 12, 1)->default(0);
            $t->unsignedTinyInteger('min_humidity')->nullable();
            $t->unsignedTinyInteger('max_humidity')->nullable();
            $t->unsignedInteger('readings')->default(0);
            $t->unsignedInteger('out_minutes')->default(0);
            $t->timestamps();
            $t->unique(['sensor_id', 'day']);
            $t->index('tenant_id');
        });

        Schema::create('iot_alarms', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->foreignUuid('sensor_id')->constrained('iot_sensors')->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('kind', 12);                  // too_warm | too_cold | humidity | power | door | silent
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();   // back in range (or reporting again)
            $t->decimal('worst', 6, 1)->nullable();  // the warmest / coldest / most humid reading
            $t->string('status', 12)->default('open'); // open | acknowledged | closed
            $t->unsignedSmallInteger('alerts_sent')->default(0);
            $t->timestamp('last_alert_at')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->string('acknowledged_by_name')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->string('action', 20)->nullable();    // checked_ok | moved | discarded | repaired | false_alarm | other
            $t->text('notes')->nullable();
            $t->foreignUuid('maintenance_order_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
            $t->index('sensor_id');
            $t->index('branch_id');
            $t->index('maintenance_order_id');
        });

        // A person's check of a fridge (morning and afternoon in most pharmacies): what they saw, signed with their name.
        Schema::create('iot_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('sensor_id')->constrained('iot_sensors')->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->string('user_name');
            $t->timestamp('checked_at');
            $t->decimal('seen_temp', 5, 1)->nullable(); // what the fridge's own display showed
            $t->boolean('ok')->default(true);
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->index(['sensor_id', 'checked_at']);
            $t->index('user_id');
            $t->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iot_checks');
        Schema::dropIfExists('iot_alarms');
        Schema::dropIfExists('iot_days');
        Schema::dropIfExists('iot_readings');
        Schema::dropIfExists('iot_sensors');
        Schema::dropIfExists('iot_connections');
    }
};
