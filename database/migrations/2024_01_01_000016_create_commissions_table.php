<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // NULL = wildcard ("any source")
            $table->foreignId('original_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('re_engaged_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('sold_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();

            $table->decimal('rate', 5, 2)->default(0);
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('original_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('re_engaged_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('sold_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();

            $table->decimal('base_amount', 12, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending')->index();
            $table->timestamp('sold_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_rules');
    }
};
