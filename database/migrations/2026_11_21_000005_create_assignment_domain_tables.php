<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assignment (ارجاع) domain (spec 05 §1, §5, §8–9): the referral row the
 * operator CRM works from, the LeadService assignment history, CRM
 * follow-up events (snooze/reject) and the frozen engagement context of
 * each referral.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The spec's rayan_lead_referal: one row per assignment of a
        // phone to an operator extension.
        Schema::create('crm_referrals', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->string('family', 100)->nullable();
            // The operator extension the lead is assigned to.
            $table->string('reagent_code', 16)->index();
            $table->string('phone', 12)->index();
            $table->string('lead_type', 64)->default('app_not_pay')->index();
            // '1' open, '2' closed (عدم خرید).
            $table->string('status', 2)->default('1')->index();
            $table->string('description', 100)->nullable();
            // Set on close: product id(s) + the reason.
            $table->string('course', 100)->nullable();
            $table->string('agent_description', 100)->nullable();
            // The admin (user id) who made the referral — not the customer.
            $table->foreignId('referred_by')->nullable();
            $table->foreignId('source_id')->nullable();
            $table->foreignId('entry_id')->nullable();
            $table->timestamp('referred_at')->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['reagent_code', 'status', 'phone']);
        });

        // lead_assignment: every ارجاع, including copied legacy ones.
        Schema::create('lead_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->index();
            $table->foreignId('source_id')->nullable();
            $table->foreignId('entry_id')->nullable();
            $table->foreignId('operator_id')->index();
            $table->string('operator_code', 16)->index();
            $table->string('crm_lead_type', 64)->nullable();
            $table->string('strategy', 16)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('assigned_by')->nullable();
            // Links to the legacy-visible referral row (UNIQUE: one each).
            $table->foreignId('crm_referral_id')->unique();
            $table->timestamps();
        });

        // lead_event: CRM follow-ups (snooze = reminder, reject = عدم خرید).
        Schema::create('lead_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->index();
            $table->string('type', 16)->index(); // snooze | reject
            $table->foreignId('operator_id')->nullable();
            $table->string('operator_code', 16)->nullable();
            $table->foreignId('ref_id')->nullable()->index(); // referral id for reject
            $table->timestamp('until_at')->nullable();
            $table->timestamps();
        });

        // lead_referral_context: engagement tags frozen at referral time.
        Schema::create('lead_referral_contexts', function (Blueprint $table) {
            $table->foreignId('crm_referral_id')->primaryKey();
            $table->foreignId('contact_id')->index();
            $table->string('first_label', 100)->nullable();
            $table->timestamp('first_at')->nullable();
            $table->string('first_ref', 64)->nullable();
            $table->string('latest_label', 100)->nullable();
            $table->timestamp('latest_at')->nullable();
            $table->string('latest_ref', 64)->nullable();
            $table->string('trigger_label', 100)->nullable();
            $table->timestamp('trigger_at')->nullable();
            $table->string('trigger_ref', 64)->nullable();
            // complete | inferred | unknown
            $table->string('status', 16)->default('complete')->index();
            $table->timestamp('referred_at')->nullable();
            $table->timestamp('snapshot_at')->nullable();
        });

        // Idempotency for the /api/events receiver (spec 03 §1).
        Schema::create('crm_event_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('type', 64)->index();
            $table->string('idempotency_key', 96)->nullable()->index();
            $table->string('result', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_event_receipts');
        Schema::dropIfExists('lead_referral_contexts');
        Schema::dropIfExists('lead_events');
        Schema::dropIfExists('lead_assignments');
        Schema::dropIfExists('crm_referrals');
    }
};
