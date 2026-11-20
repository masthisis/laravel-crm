<?php

namespace Database\Seeders;

use App\Enums\SourceType;
use App\Models\LeadIntegration;
use App\Models\LeadSource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            ['name' => 'Blog embedded form', 'type' => SourceType::FormEmbed, 'color' => '#6366f1', 'description' => 'Consultation forms embedded inside blog articles.'],
            ['name' => 'Website contact form', 'type' => SourceType::FormEmbed, 'color' => '#22c55e', 'description' => 'Main site contact page.'],
            ['name' => 'Facebook Ads', 'type' => SourceType::Webhook, 'color' => '#1877f2', 'description' => 'Lead ads pushed via webhook.'],
            ['name' => 'Referral', 'type' => SourceType::Manual, 'color' => '#f59e0b', 'description' => 'Word-of-mouth referrals.'],
            ['name' => 'External CRM sync', 'type' => SourceType::DatabaseSync, 'color' => '#a855f7', 'description' => 'Leads pulled from the legacy database by crm:sync-external-leads.'],
        ];

        foreach ($sources as $data) {
            $source = LeadSource::firstOrCreate(
                ['slug' => Str::slug($data['name'])],
                $data,
            );

            if ($data['type'] === SourceType::FormEmbed) {
                LeadIntegration::firstOrCreate(
                    ['lead_source_id' => $source->id, 'driver' => 'form_embed'],
                    [
                        'token' => Str::random(48),
                        'description' => 'Embeddable form token — GET /embed/leads/{token} for the iframe URL.',
                        'is_active' => true,
                    ],
                );
            }

            if ($data['type'] === SourceType::Webhook) {
                LeadIntegration::firstOrCreate(
                    ['lead_source_id' => $source->id, 'driver' => 'webhook'],
                    [
                        'token' => Str::random(48),
                        'description' => 'POST /api/leads with header X-Integration-Token.',
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
