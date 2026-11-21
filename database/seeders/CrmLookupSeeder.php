<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Lookup lists (spec 06 §1.4): CRM note departments (8), ready-to-buy
 * categories (11) and the call-queue outcomes (3).
 */
class CrmLookupSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('crm_departments')->truncate();
        foreach ([
            'فروش', 'دسترسی فایل ها', 'مشکل پرداخت', 'دیگر',
            'پشتیبانی', 'سبد گردانی', 'مشکل کانال پشتیبانی', 'مشکل دوره پرایس اپلیکیشن',
        ] as $name) {
            DB::table('crm_departments')->insert(['name' => $name]);
        }

        DB::table('crm_note_categories')->truncate();
        foreach ([
            'دوره فوری ارز دیجیتال', 'شروع قوی بورس', 'آقای تریدر', 'پکیج دوستان صمیمی',
            'دوره صفر تا یک ثروت', 'دوره سوینگ تریدینگ', 'هیچکدام', 'کانال سیگنال کریپتو',
            'تمدید کانال سیگنال', 'مدیریت سرمایه', 'درآمد دلاری',
        ] as $name) {
            DB::table('crm_note_categories')->insert(['name' => $name]);
        }

        DB::table('crm_oncall_categories')->truncate();
        foreach (['عدم پاسخگویی', 'پاسخ داده شده', 'پیگیری مجدد'] as $name) {
            DB::table('crm_oncall_categories')->insert(['name' => $name]);
        }
    }
}
