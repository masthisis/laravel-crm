<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commerce and phone mirrors (spec 01 §2.5, §2.4 / 03 §2): the CRM owns
 * its invoice and call-log state, fed by events and the legacy poller
 * (invoice.created / invoice.paid / call.logged / call.completed).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Invoice header (the spec's rayan_prefactor_group).
        Schema::create('crm_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->foreignId('customer_id')->nullable()->index();
            $table->string('phone', 12)->nullable()->index();
            $table->unsignedBigInteger('tracking_code')->nullable()->index();
            $table->unsignedInteger('total')->default(0);       // toman, digits of the text column
            $table->string('discount', 100)->nullable()->index(); // code text the buyer used
            $table->string('reagent_code', 100)->nullable();     // referrer code the buyer typed
            $table->unsignedTinyInteger('status')->default(1)->index(); // 1/2/3/4/10/11
            $table->unsignedTinyInteger('pay_type')->nullable(); // 1..9 gateway codes
            $table->boolean('is_installment')->default(false);
            $table->string('token', 64)->nullable();
            $table->string('comments', 255)->nullable();
            $table->timestamp('invoice_date')->nullable()->index(); // legacy `date`
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamps();
            $table->index(['status', 'invoice_date']);
        });

        Schema::create('crm_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->unsignedInteger('product_id')->nullable()->index();
            $table->string('product_name', 255)->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedSmallInteger('product_count')->default(1);
        });

        // Call log (the spec's rayan_crm_call_log).
        Schema::create('crm_call_logs', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 32)->nullable()->index();      // canonical-ish (FixNumber output)
            $table->string('phone_raw', 32)->nullable();           // raw PBX number
            $table->string('exten', 16)->nullable()->index();
            $table->string('type', 16)->default('incoming');       // incoming | outgoing
            $table->string('uniqueid', 64)->index();               // Asterisk unique id (recording key)
            $table->string('accepted_call', 16)->nullable()->index();
            $table->string('hangup', 16)->nullable();
            $table->unsignedInteger('call_time')->nullable();      // seconds, filled by CDR verify
            $table->string('status_asterisk', 32)->nullable();     // ANSWERED, NO ANSWER, …
            $table->boolean('cdr_verify')->default(false)->index();
            $table->timestamp('called_at')->index();
            $table->timestamps();
            // One row per ringing extension per call.
            $table->unique(['phone', 'exten', 'type', 'uniqueid'], 'uq_call_row');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_call_logs');
        Schema::dropIfExists('crm_invoice_lines');
        Schema::dropIfExists('crm_invoices');
    }
};
