<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use Illuminate\Database\Seeder;

/**
 * System lead sources (spec 05 §2): one tab per form family, the UTM /
 * organic / unpaid-invoice live sources and the four system segments,
 * each with its default CRM lead_type, badge and facet fields.
 */
class LeadServiceSourceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::sources() as $attributes) {
            LeadSource::query()->updateOrCreate(
                ['slug' => $attributes['slug']],
                $attributes,
            );
        }
    }

    /** @return list<array<string, mixed>> */
    public static function sources(): array
    {
        return [
            [
                'slug' => 'starttraining',
                'name' => 'درخواست مشاوره',
                'type' => 'webhook',
                'color' => '#3B82F6',
                'kind' => LeadSource::KIND_SYSTEM,
                'driver' => LeadSource::DRIVER_STORED,
                'crm_lead_type' => 'app_moshavare',
                'importable' => true,
                'sort' => 10,
                'config' => ['facets' => ['category', 'verified']],
            ],
            [
                'slug' => 'ask',
                'name' => 'از من بپرس (کریپتو پامپ)',
                'type' => 'webhook',
                'color' => '#8B5CF6',
                'kind' => LeadSource::KIND_SYSTEM,
                'driver' => LeadSource::DRIVER_STORED,
                'crm_lead_type' => 'cryptopumpt_link',
                'importable' => true,
                'sort' => 20,
                'config' => ['facets' => ['learning', 'datingtime']],
            ],
            [
                'slug' => 'lead_from_admin',
                'name' => 'ثبت توسط ادمین',
                'type' => 'manual',
                'color' => '#64748B',
                'kind' => LeadSource::KIND_SYSTEM,
                'driver' => LeadSource::DRIVER_STORED,
                'crm_lead_type' => 'lead_from_admin',
                'crm_lead_type_map' => [
                    ['field' => 'lead_type', 'value' => 'وبینار', 'lead_type' => 'lead_webinar'],
                    ['field' => 'lead_type', 'value' => 'کریپتو پامپ', 'lead_type' => 'cryptopumpt_link'],
                    ['field' => 'lead_type', 'value' => 'مشاوره اپ', 'lead_type' => 'app_moshavare'],
                    ['field' => 'lead_type', 'value' => 'دکس', 'lead_type' => 'dex_gheyrehozoori'],
                    ['field' => 'lead_type', 'value' => 'دکس غیرحضوری', 'lead_type' => 'dex_gheyrehozoori'],
                ],
                'importable' => true,
                'sort' => 30,
                'config' => ['facets' => ['lead_type', 'from_user']],
            ],
            [
                'slug' => 'requestcall',
                'name' => 'درخواست تماس',
                'type' => 'webhook',
                'color' => '#0EA5E9',
                'kind' => LeadSource::KIND_SYSTEM,
                'driver' => LeadSource::DRIVER_STORED,
                'crm_lead_type' => 'lead_from_admin',
                'importable' => true,
                'sort' => 40,
                'config' => ['facets' => ['verified', 'comment']],
            ],
            [
                'slug' => 'consult_users',
                'name' => 'رزرو مشاوره',
                'type' => 'webhook',
                'color' => '#14B8A6',
                'kind' => LeadSource::KIND_SYSTEM,
                'driver' => LeadSource::DRIVER_STORED,
                'crm_lead_type' => 'lead_from_admin',
                'sort' => 50,
                'config' => ['facets' => ['consult_main', 'consult_type', 'consult_status']],
            ],
            [
                'slug' => 'webinar',
                'name' => 'ثبت‌نام وبینار',
                'type' => 'webhook',
                'color' => '#F59E0B',
                'kind' => LeadSource::KIND_SYSTEM,
                'driver' => LeadSource::DRIVER_STORED,
                'crm_lead_type' => 'lead_webinar',
                'importable' => true,
                'sort' => 60,
                'config' => ['facets' => ['webinar_id', 'new_user']],
            ],
            [
                'slug' => 'campaign',
                'name' => 'بازدید کمپین',
                'type' => 'webhook',
                'color' => '#EF4444',
                'kind' => LeadSource::KIND_SYSTEM,
                'driver' => LeadSource::DRIVER_STORED,
                'crm_lead_type' => 'lead_from_admin',
                'sort' => 70,
                'config' => ['facets' => ['page']],
            ],
            [
                'slug' => 'oncalls',
                'name' => 'صف تماس',
                'type' => 'manual',
                'color' => '#F97316',
                'kind' => LeadSource::KIND_SYSTEM,
                'driver' => LeadSource::DRIVER_STORED,
                'crm_lead_type' => 'lead_from_admin',
                'importable' => true,
                'sort' => 80,
                'config' => ['facets' => ['category', 'call_status']],
            ],
            [
                'slug' => 'utm',
                'name' => 'UTM و کمپین‌ها',
                'type' => 'api',
                'color' => '#7C3AED',
                'kind' => LeadSource::KIND_SYSTEM,
                'driver' => LeadSource::DRIVER_UTM,
                'crm_lead_type' => 'lead_from_admin',
                'sort' => 90,
            ],
            [
                'slug' => 'organic',
                'name' => 'ورودی‌های ارگانیک',
                'type' => 'api',
                'color' => '#22C55E',
                'kind' => LeadSource::KIND_SYSTEM,
                'driver' => LeadSource::DRIVER_ORGANIC,
                'crm_lead_type' => 'app_moshavare',
                'sort' => 100,
            ],
            [
                'slug' => 'unpaid_invoices',
                'name' => 'فاکتورهای پرداخت‌نشده',
                'type' => 'database_sync',
                'color' => '#DC2626',
                'kind' => LeadSource::KIND_SYSTEM,
                'driver' => LeadSource::DRIVER_INVOICES,
                'crm_lead_type' => 'unpaid_invoice',
                'sort' => 110,
            ],
            [
                'slug' => 'app_not_pay',
                'name' => 'پرداخت نشده‌های اپ',
                'type' => 'manual',
                'color' => '#B91C1C',
                'kind' => LeadSource::KIND_SEGMENT,
                'driver' => LeadSource::DRIVER_SEGMENT,
                'crm_lead_type' => 'app_not_pay',
                'sort' => 200,
                'config' => ['segment' => [
                    'match' => 'all',
                    'rules' => [
                        ['criterion' => 'order', 'params' => ['status' => 'unpaid', 'product_group' => ['app'], 'last_days' => 30]],
                        ['criterion' => 'order', 'negate' => true, 'params' => ['status' => 'paid', 'product_group' => ['app']]],
                    ],
                ]],
            ],
            [
                'slug' => 'app_not_have',
                'name' => 'مشتریانی که اپ ندارند',
                'type' => 'manual',
                'color' => '#7C2D12',
                'kind' => LeadSource::KIND_SEGMENT,
                'driver' => LeadSource::DRIVER_SEGMENT,
                'crm_lead_type' => 'app_not_have',
                'sort' => 210,
                'config' => ['segment' => [
                    'match' => 'all',
                    'rules' => [
                        ['criterion' => 'order', 'params' => ['status' => 'any']],
                        ['criterion' => 'order', 'negate' => true, 'params' => ['status' => 'any', 'product_group' => ['app']]],
                        ['criterion' => 'assigned', 'negate' => true, 'params' => ['crm_lead_type' => 'app_not_have']],
                    ],
                ]],
            ],
            [
                'slug' => 'active_latest_lead',
                'name' => 'فعال دو ماه اخیر بدون اپ',
                'type' => 'manual',
                'color' => '#0F766E',
                'kind' => LeadSource::KIND_SEGMENT,
                'driver' => LeadSource::DRIVER_SEGMENT,
                'crm_lead_type' => 'active_latest_lead',
                'sort' => 220,
                'config' => ['segment' => [
                    'match' => 'all',
                    'rules' => [
                        ['criterion' => 'last_login', 'params' => ['within_days' => 60]],
                        ['criterion' => 'order', 'params' => ['status' => 'paid']],
                        ['criterion' => 'order', 'negate' => true, 'params' => ['status' => 'paid', 'products' => [60, 62, 63, 64]]],
                        ['criterion' => 'assigned', 'negate' => true, 'params' => ['crm_lead_type' => 'active_latest_lead']],
                    ],
                ]],
            ],
            [
                'slug' => 'app_users_lead',
                'name' => 'تمدید اشتراک اپ',
                'type' => 'manual',
                'color' => '#4D7C0F',
                'kind' => LeadSource::KIND_SEGMENT,
                'driver' => LeadSource::DRIVER_SEGMENT,
                'crm_lead_type' => 'app_users_lead',
                'sort' => 230,
                'config' => ['segment' => [
                    'match' => 'all',
                    'rules' => [
                        ['criterion' => 'subscription', 'params' => ['ends_from_days' => 0, 'ends_to_days' => 30]],
                    ],
                ]],
            ],
        ];
    }
}
