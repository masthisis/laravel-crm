<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Hash every existing plaintext integration token in place. The
        // capture lookup now compares sha256(submitted) against this
        // column, so callers are unaffected.
        DB::table('lead_integrations')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('lead_integrations')
                    ->where('id', $row->id)
                    ->update(['token' => hash('sha256', (string) $row->token)]);
            }
        });
    }

    public function down(): void
    {
        // Irreversible — plaintext tokens cannot be recovered from hashes.
    }
};
