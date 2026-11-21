<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LeadService source extensions (spec 05 §1–2): tabs are system sources,
 * custom sources or saved segments; the driver decides where the rows
 * come from; crm_lead_type is the lead_type written on a referral; the
 * badge (colour + label) and commission rate live on the source too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->string('kind', 16)->default('system')->after('slug');        // system | custom | segment
            $table->string('driver', 16)->default('stored')->after('kind');      // stored | utm | organic | segment | all | invoices
            $table->string('crm_lead_type', 64)->nullable()->after('driver');
            $table->json('crm_lead_type_map')->nullable()->after('crm_lead_type');
            $table->json('config')->nullable()->after('crm_lead_type_map');
            $table->boolean('importable')->default(false)->after('config');
            $table->unsignedInteger('sort')->default(0)->after('importable');
            $table->decimal('commission_percent', 5, 2)->nullable()->after('sort');
        });
    }

    public function down(): void
    {
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->dropColumn([
                'kind', 'driver', 'crm_lead_type', 'crm_lead_type_map',
                'config', 'importable', 'sort', 'commission_percent',
            ]);
        });
    }
};
