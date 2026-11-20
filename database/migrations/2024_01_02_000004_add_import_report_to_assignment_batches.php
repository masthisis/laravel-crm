<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Persisted import outcome: per-batch counters and the row-level
        // error report (row number + reason) so failures are reviewable
        // long after the toast is gone.
        Schema::table('assignment_batches', function (Blueprint $table) {
            $table->unsignedInteger('total_rows')->default(0)->after('filename');
            $table->unsignedInteger('imported_count')->default(0)->after('total_rows');
            $table->unsignedInteger('duplicates_count')->default(0)->after('imported_count');
            $table->unsignedInteger('failed_count')->default(0)->after('duplicates_count');
            $table->json('errors')->nullable()->after('distribution');
        });

        // Backfill: pre-existing batches only knew their lead totals.
        DB::table('assignment_batches')->update([
            'total_rows' => DB::raw('total_leads'),
            'imported_count' => DB::raw('total_leads'),
        ]);
    }

    public function down(): void
    {
        Schema::table('assignment_batches', function (Blueprint $table) {
            $table->dropColumn(['total_rows', 'imported_count', 'duplicates_count', 'failed_count', 'errors']);
        });
    }
};
