<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator fields on panel users (spec 04 §1 / 01 §2.1): the PBX
 * extension is the operator's code everywhere (referrals, discount-code
 * suffix, call logs); hashed passwords replace the legacy plaintext ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('exten', 16)->nullable()->unique()->after('password');
            $table->boolean('may_receive_referrals')->default(true)->after('exten');
            $table->boolean('is_commission_manager')->default(false)->after('may_receive_referrals');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['exten', 'may_receive_referrals', 'is_commission_manager']);
        });
    }
};
