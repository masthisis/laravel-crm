<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail for crm:sync-external-leads: one row per run per
        // connection with counts, row-level errors and the incremental
        // cursor (last processed reference value).
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('connection_name');
            $table->string('source_name')->nullable();
            $table->string('trigger')->default('schedule'); // schedule|manual
            $table->boolean('dry_run')->default(false);
            $table->unsignedInteger('seen')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('last_ref')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
