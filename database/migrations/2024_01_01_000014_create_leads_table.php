<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();

            // Original source / re-engagement source / closing (sold) source
            $table->foreignId('source_id')->constrained('lead_sources')->restrictOnDelete();
            $table->foreignId('re_engaged_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('sold_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();

            $table->string('status')->default('new')->index();
            $table->decimal('estimated_value', 12, 2)->nullable();
            $table->decimal('sold_amount', 12, 2)->nullable();

            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('re_engaged_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('lost_reason')->nullable();

            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
