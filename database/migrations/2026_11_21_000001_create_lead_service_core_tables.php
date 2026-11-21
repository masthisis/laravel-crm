<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LeadService core tables (spec 05 §1): contacts keyed by canonical
 * phone, idempotent entries per submission, indexed filter facets,
 * per-source summaries, import bookkeeping and legacy-copy cursors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_contacts', function (Blueprint $table) {
            $table->id();
            // Canonical 9xxxxxxxxx (PhoneNormalizer).
            $table->string('phone', 12)->unique();
            // Linked site account (the spec's rayan_users link).
            $table->foreignId('customer_id')->nullable()->index();
            $table->timestamp('customer_checked_at')->nullable();
            $table->string('name')->nullable();
            $table->string('family')->nullable();
            $table->timestamp('first_seen_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            // Current owner (latest assignment).
            $table->foreignId('owner_operator_id')->nullable();
            $table->string('owner_code', 16)->nullable();
            $table->timestamp('owner_assigned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('lead_sources')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->index();
            // Legacy row id or import ref — UNIQUE with the source makes
            // every capture path idempotent (spec 05 §1, legacy: varchar(64)).
            $table->string('origin_ref', 64)->nullable();
            $table->unique(['source_id', 'origin_ref']);
            $table->string('name')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('captured_at')->index();
            $table->foreignId('import_batch_id')->nullable();
            $table->string('raw_phone')->nullable();
            $table->boolean('returning')->default(false);
            $table->boolean('invalid_phone')->default(false);
            $table->string('invalid_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_entry_facets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('lead_entries')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('lead_sources')->cascadeOnDelete();
            $table->string('field', 64);
            $table->string('value', 255)->nullable();
            $table->index(['source_id', 'field', 'value']);
        });

        Schema::create('lead_contact_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('lead_contacts')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('lead_sources')->restrictOnDelete();
            $table->timestamp('first_at')->nullable();
            $table->timestamp('last_at')->nullable();
            $table->unsignedInteger('entries')->default(0);
            $table->unique(['contact_id', 'source_id']);
        });

        Schema::create('lead_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('lead_sources')->restrictOnDelete();
            $table->string('file_name')->nullable();
            $table->string('format', 16)->nullable();
            $table->string('file_hash', 40)->nullable()->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('returning_leads')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('invalid')->default(0);
            $table->json('errors')->nullable();
            $table->foreignId('admin_id')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_legacy_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('adapter', 64)->unique();
            $table->unsignedBigInteger('last_origin_id')->default(0);
            $table->unsignedInteger('copied')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_legacy_cursors');
        Schema::dropIfExists('lead_import_batches');
        Schema::dropIfExists('lead_contact_sources');
        Schema::dropIfExists('lead_entry_facets');
        Schema::dropIfExists('lead_entries');
        Schema::dropIfExists('lead_contacts');
    }
};
