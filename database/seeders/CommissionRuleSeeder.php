<?php

namespace Database\Seeders;

use App\Models\CommissionRule;
use App\Models\LeadSource;
use Illuminate\Database\Seeder;

class CommissionRuleSeeder extends Seeder
{
    public function run(): void
    {
        $byName = fn (string $name) => LeadSource::where('name', $name)->first();

        $rules = [
            // Blog-form leads that come back through Facebook Ads and close there pay 25%.
            [
                'name' => 'Blog form → re-engaged via Facebook Ads',
                'original' => 'Blog embedded form',
                're_engaged' => 'Facebook Ads',
                'sold' => null,
                'rate' => 25,
                'priority' => 20,
            ],
            // Plain blog-form leads pay the standard 15%.
            [
                'name' => 'Blog form (standard)',
                'original' => 'Blog embedded form',
                're_engaged' => null,
                'sold' => null,
                'rate' => 15,
                'priority' => 10,
            ],
            // Everything else falls back to a generic 10%.
            [
                'name' => 'Default rate',
                'original' => null,
                're_engaged' => null,
                'sold' => null,
                'rate' => 10,
                'priority' => 0,
            ],
        ];

        foreach ($rules as $data) {
            CommissionRule::firstOrCreate(
                ['name' => $data['name']],
                [
                    'original_source_id' => $data['original'] !== null ? $byName($data['original'])?->id : null,
                    're_engaged_source_id' => $data['re_engaged'] !== null ? $byName($data['re_engaged'])?->id : null,
                    'sold_source_id' => $data['sold'] !== null ? $byName($data['sold'])?->id : null,
                    'rate' => $data['rate'],
                    'priority' => $data['priority'],
                    'is_active' => true,
                ],
            );
        }
    }
}
