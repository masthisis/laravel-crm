<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator workspace tables (spec 04, 06 §1.4): CRM notes (the legacy
 * "tickets"), the call queue (شماره ها), SMS log, installments and the
 * lookup lists (departments, ready-to-buy categories, call outcomes).
 */
return new class extends Migration
{
    public function up(): void
    {
        // rayan_crm_ticket_group — operator notes about a customer.
        Schema::create('crm_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->index();
            $table->foreignId('operator_id')->index();
            $table->string('subject', 255);
            $table->foreignId('department_id')->nullable();
            $table->foreignId('category_id')->nullable();
            // status 1 = note, 2 = call-status note (وضعیت تماس)
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedInteger('ticket_number')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // rayan_crm_ticket_sub — note messages and replies.
        Schema::create('crm_note_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_note_id')->constrained('crm_notes')->cascadeOnDelete();
            $table->foreignId('operator_id')->index();
            $table->text('message');
            $table->timestamp('created_at')->nullable();
        });

        // rayan_oncalls — the call queue uploaded by admins.
        Schema::create('crm_oncalls', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->foreignId('operator_id')->index();
            $table->string('category', 100)->nullable();
            $table->unsignedTinyInteger('status')->nullable()->index(); // NULL = not called
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamps();
        });

        // SMS log — every message the CRM sends (bug 11 #11 fixed).
        Schema::create('crm_sms_messages', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->foreignId('operator_id')->nullable();
            $table->foreignId('referral_id')->nullable();
            $table->unsignedTinyInteger('template')->nullable(); // 1 intro, 2 code, 3 missed call
            $table->text('body');
            $table->unsignedTinyInteger('parts')->default(1);
            $table->string('status', 16)->default('sent');
            $table->string('provider_reference', 64)->nullable();
            $table->timestamps();
        });

        // rayan_installment — installments of manual orders.
        Schema::create('crm_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->index();
            $table->unsignedInteger('product_id')->nullable();
            $table->string('product_title', 255)->nullable();
            $table->unsignedInteger('amount')->default(0);
            $table->date('due_date')->index();
            $table->unsignedTinyInteger('status')->default(0); // 0 unpaid, 1 paid
            $table->unsignedBigInteger('tracking_code')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        });

        // customer_admin_log — every panel write is audited (07 §4).
        Schema::create('customer_admin_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->index();
            $table->foreignId('customer_id')->index();
            $table->string('action', 64);
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        // One-time impersonation tokens (07 §4.2): only the hash is kept.
        Schema::create('customer_impersonation_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('customer_id')->index();
            $table->foreignId('admin_id');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // Lookup lists (06 §1.4).
        Schema::create('crm_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
        });
        Schema::create('crm_note_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
        });
        Schema::create('crm_oncall_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_oncall_categories');
        Schema::dropIfExists('crm_note_categories');
        Schema::dropIfExists('crm_departments');
        Schema::dropIfExists('customer_impersonation_tokens');
        Schema::dropIfExists('customer_admin_log');
        Schema::dropIfExists('crm_installments');
        Schema::dropIfExists('crm_sms_messages');
        Schema::dropIfExists('crm_oncalls');
        Schema::dropIfExists('crm_note_replies');
        Schema::dropIfExists('crm_notes');
    }
};
