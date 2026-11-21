<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account (customer) fields the CRM reads and writes, modelled on the
 * spec's rayan_users (01 §2.1): extra phone numbers, operator-editable
 * profile fields and CRM provenance flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone2')->nullable()->index()->after('phone');
            $table->string('phone3')->nullable()->index()->after('phone2');
            $table->text('address')->nullable()->after('company');
            $table->string('bank_cart', 32)->nullable()->after('address');
            $table->string('bank_number', 32)->nullable()->after('bank_cart');
            $table->string('bank_shaba', 32)->nullable()->after('bank_number');
            // pending = created, OTP not yet confirmed; verified otherwise.
            $table->string('account_status', 16)->default('verified')->after('bank_shaba')->index();
            $table->string('add_from', 32)->nullable()->after('account_status');   // arminvest_app | webinar | …
            $table->boolean('is_crm_created')->default(false)->after('add_from');  // spec's crm=1
            $table->foreignId('created_by_operator_id')->nullable()->after('is_crm_created'); // spec's operator_id
            $table->timestamp('last_login_at')->nullable()->after('created_by_operator_id');
            $table->timestamp('reminder_at')->nullable()->after('last_login_at');
            $table->foreignId('reminder_operator_id')->nullable()->after('reminder_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'phone2', 'phone3', 'address', 'bank_cart', 'bank_number',
                'bank_shaba', 'account_status', 'add_from', 'is_crm_created',
                'created_by_operator_id', 'last_login_at', 'reminder_at',
                'reminder_operator_id',
            ]);
        });
    }
};
