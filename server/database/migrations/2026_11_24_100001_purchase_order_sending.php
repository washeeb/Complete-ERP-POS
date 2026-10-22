<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Purchase orders sent to the supplier from the app (e-mail or WhatsApp): when, how, to whom, and the PDF that went. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->timestamp('sent_at')->nullable();
            $t->string('sent_via', 20)->nullable(); // email | whatsapp | whatsapp_link
            $t->string('sent_to', 160)->nullable();
            $t->string('sent_by_name', 120)->nullable();
            $t->string('pdf_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropColumn(['sent_at', 'sent_via', 'sent_to', 'sent_by_name', 'pdf_path']);
        });
    }
};
