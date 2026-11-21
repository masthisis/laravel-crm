<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UTM & attribution (spec 08): immutable campaign links, identified
 * visit records and the per-lead organic attribution snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utm_links', function (Blueprint $table) {
            $table->id();
            $table->string('public_token', 32)->unique(); // utm_link_id in the URL
            $table->string('automatic_key', 64)->nullable()->unique();
            $table->string('display_name', 255);
            $table->string('destination_url', 500);
            $table->string('destination_identity', 300)->nullable();
            $table->string('generated_url', 600);
            $table->string('utm_source', 100)->nullable()->index();
            $table->string('utm_medium', 100)->nullable()->index();
            $table->string('utm_campaign', 100)->nullable()->index();
            $table->string('utm_term', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->string('utm_id', 64)->nullable();
            // dashboard = built by an admin, automatic = discovered from traffic
            $table->string('origin', 16)->default('dashboard');
            $table->foreignId('created_by_admin_id')->nullable();
            $table->timestamps();
        });

        Schema::create('utm_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->nullable()->index();
            $table->string('visitor_token_hash', 64)->index();
            $table->string('visit_id', 64)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('utm_term', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->string('utm_id', 64)->nullable();
            $table->string('display_name', 255)->nullable();
            $table->string('landing_path', 300)->nullable();
            $table->string('referrer', 300)->nullable();
            // Identification: first identity wins (08 §2).
            $table->foreignId('user_id')->nullable()->index();
            $table->string('identification_type', 16)->nullable()->index(); // signup|login|lead|order
            $table->timestamp('identified_at')->nullable();
            $table->string('phone', 12)->nullable()->index();
            $table->string('contact_origin', 16)->nullable(); // form
            $table->unsignedBigInteger('contact_ref')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
        });

        Schema::create('source_attributions', function (Blueprint $table) {
            $table->id();
            $table->string('origin_table', 64)->default('starttraining');
            $table->unsignedBigInteger('origin_id');
            $table->unique(['origin_table', 'origin_id']);
            $table->string('form', 16); // consult_article | footer
            $table->string('phone', 12)->nullable()->index();
            $table->timestamp('event_at')->nullable();
            $table->string('event_host', 100)->nullable();
            $table->string('event_path', 300)->nullable();
            // The visit's own landing.
            $table->string('vt_channel', 16)->nullable()->index();  // search|social|referral|direct|campaign|unknown
            $table->string('vt_source', 100)->nullable()->index();
            $table->string('vt_referrer_host', 100)->nullable();
            $table->string('vt_host', 100)->nullable();
            $table->string('vt_section', 64)->nullable()->index();
            $table->string('vt_category', 64)->nullable();
            $table->string('vt_post', 100)->nullable();
            $table->string('vt_landing_path', 300)->nullable();
            $table->timestamp('vt_at')->nullable();
            // First touch ever seen for this browser.
            $table->string('ft_channel', 16)->nullable();
            $table->string('ft_source', 100)->nullable();
            $table->timestamp('ft_at')->nullable();
            // Last non-direct touch.
            $table->string('lnd_channel', 16)->nullable();
            $table->string('lnd_source', 100)->nullable();
            $table->timestamp('lnd_at')->nullable();
            $table->timestamps();
        });

        // Segment snapshots (05 §4): user ids per segment, rebuilt under
        // a lock while readers keep the old snapshot.
        Schema::create('lead_segment_caches', function (Blueprint $table) {
            $table->id();
            $table->string('segment_slug', 64)->index();
            $table->foreignId('customer_id')->index();
            $table->timestamp('built_at');
            $table->unique(['segment_slug', 'customer_id']);
        });

        // Discount mirror (the spec's rayan_discount): agent codes own the
        // commission (09 §1) — operatorid > 0 marks an agent code.
        Schema::create('crm_discounts', function (Blueprint $table) {
            $table->id();
            // Several rows may share a code (two operators = ambiguous → review).
            $table->string('code', 100)->index();
            $table->unsignedBigInteger('operatorid')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Commission PRD (09 §2.2): insert-only earnings keyed by invoice.
        Schema::create('commission_earnings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->unique(); // idempotent per invoice
            $table->foreignId('agent_id')->nullable()->index(); // NULL = review
            $table->string('discount_code', 100);
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('assignment_id')->nullable();
            $table->foreignId('source_id')->nullable();
            $table->string('source_name', 255)->nullable();
            $table->decimal('rate', 5, 2);
            $table->unsignedBigInteger('paid_amount');
            $table->unsignedBigInteger('amount');
            $table->timestamp('paid_at');
            $table->char('month', 7)->index(); // Jalali YYYY-MM
            $table->string('status', 10)->default('ok'); // ok | review
            $table->timestamps();
            $table->index(['agent_id', 'month']);
        });

        Schema::create('commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->index();
            $table->char('month', 7); // Jalali YYYY-MM
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->foreignId('recorded_by')->nullable();
            $table->timestamps();
            $table->unique(['agent_id', 'month']); // one lump-sum payout per agent per month
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payouts');
        Schema::dropIfExists('commission_earnings');
        Schema::dropIfExists('crm_discounts');
        Schema::dropIfExists('lead_segment_caches');
        Schema::dropIfExists('source_attributions');
        Schema::dropIfExists('utm_records');
        Schema::dropIfExists('utm_links');
    }
};
