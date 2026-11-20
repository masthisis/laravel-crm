<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer-level activity log: pre-call notes that belong to the
        // customer, not to one specific lead.
        Schema::table('lead_activities', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('lead_id')
                ->constrained('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lead_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
