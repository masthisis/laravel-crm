<?php

use App\Models\CommissionEarning;
use App\Models\CommissionPayout;
use App\Models\CrmDiscount;
use App\Models\CrmInvoice;
use App\Models\CrmInvoiceLine;
use App\Models\Customer;
use App\Models\LeadContact;
use App\Models\LeadEntry;
use App\Models\LeadSource;
use App\Models\Product;
use App\Models\SourceAttribution;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UtmLink;
use App\Models\UtmRecord;
use App\Services\Commission\CommissionSyncService;
use App\Services\Leads\LeadCaptureService;
use App\Services\Leads\LegacyUserLinker;
use App\Services\Leads\ReferralService;
use App\Services\Leads\SegmentEvaluator;
use App\Services\Utm\UtmService;
use App\Support\Jalali;
use Database\Seeders\LeadServiceSourceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** UTM & attribution (08), segment engine (05 §4), commission PRD (09). */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    (new LeadServiceSourceSeeder)->run();
});

/** ---------------- UTM & attribution ---------------- */
it('classifies referrers in the spec order', function () {
    expect(UtmService::classify(null, 'https://alirezamehrabi.ir/x?utm_source=pub'))->toBe(['channel' => 'campaign', 'source' => null])
        ->and(UtmService::classify(null, 'https://alirezamehrabi.ir/x'))->toBe(['channel' => 'direct', 'source' => null])
        ->and(UtmService::classify('alirezamehrabi.ir', 'https://alirezamehrabi.ir/x'))->toBe(['channel' => 'internal', 'source' => null])
        ->and(UtmService::classify('vafadar.io', 'https://alirezamehrabi.ir/thanks'))->toBe(['channel' => 'direct', 'source' => null])
        ->and(UtmService::classify('google.com', 'https://alirezamehrabi.ir/blog/a'))->toBe(['channel' => 'search', 'source' => 'google.com'])
        ->and(UtmService::classify('t.me', 'https://alirezamehrabi.ir/x'))->toBe(['channel' => 'social', 'source' => 't.me'])
        ->and(UtmService::classify('example.org/blog', 'https://alirezamehrabi.ir/x'))->toBe(['channel' => 'referral', 'source' => 'example.org']);
});

it('identifies anonymous visits on the first identity only', function () {
    $service = new UtmService;

    UtmRecord::create(['visitor_token_hash' => 'h1', 'created_at' => now(), 'utm_campaign' => 'pub']);

    $service->identifyContact('09121234567', 'lead', 501);

    $record = UtmRecord::query()->firstOrFail();
    expect($record->phone)->toBe('9121234567')
        ->and($record->identification_type)->toBe('lead')
        ->and($record->contact_origin)->toBe('form')
        ->and((string) $record->contact_ref)->toBe('501');

    // A later login does not overwrite the lead identity (08 §2).
    $service->identify('h1', 44, 'login');
    expect($record->fresh()->identification_type)->toBe('lead')
        ->and($record->fresh()->user_id)->toBeNull();
});

it('attributes consult and footer leads once per origin row', function () {
    $service = new UtmService;

    $first = $service->attributeLead('starttraining', 7, SourceAttribution::FORM_FOOTER, '09121234567', [
        'channel' => 'search', 'source' => 'google.com', 'host' => 'alirezamehrabi.ir',
        'path' => '/blog/cryptocurrency/a', 'at' => now(),
    ], ['channel' => 'direct', 'at' => now()->subDays(3)]);

    expect($first)->not->toBeNull()
        ->and($first->vt_channel)->toBe('search')
        ->and($first->vt_section)->toBeNull() // blog section parsed on capture side; here raw path kept
        ->and($first->ft_channel)->toBe('direct');

    // INSERT IGNORE semantics: same origin → no new row.
    $second = $service->attributeLead('starttraining', 7, SourceAttribution::FORM_FOOTER, '09121234567');
    expect($second)->toBeNull()
        ->and(SourceAttribution::query()->count())->toBe(1);
});

it('parses page sections including blog categories', function () {
    expect(UtmService::pageSection('/cryptocurrency/news/post-1'))->toBe(['section' => 'cryptocurrency', 'category' => 'news', 'post' => 'post-1'])
        ->and(UtmService::pageSection('/landing/start'))->toBe(['section' => 'landing', 'category' => 'start', 'post' => null])
        ->and(UtmService::pageSection('/'))->toBe(['section' => 'home', 'category' => null, 'post' => null]);
});

it('dedupes automatic links by normalized utm values', function () {
    $service = new UtmService;
    $utm = ['utm_source' => 'pub', 'utm_medium' => 'cpc', 'utm_campaign' => 'azar'];

    $link = $service->registerAutomaticLink($utm, 'https://alirezamehrabi.ir/landing/start', 'Azar pub');
    $again = $service->registerAutomaticLink($utm, 'https://alirezamehrabi.ir/landing/start', 'Azar pub');

    expect(UtmLink::query()->count())->toBe(1)
        ->and($again->id)->toBe($link->id);
});

/** ---------------- Segment engine ---------------- */
function seedCustomerWithOrder(array $customerAttrs, array $invoiceAttrs): array
{
    $customer = Customer::query()->create($customerAttrs);
    $invoice = CrmInvoice::query()->create([...$invoiceAttrs, 'customer_id' => $customer->id, 'legacy_id' => (int) ($invoiceAttrs['legacy_id'] ?? random_int(1000, 999999))]);

    return [$customer, $invoice];
}

it('evaluates the unpaid-app system segment with the failed-checkout rule', function () {
    $seed = fn (array $attrs) => Customer::query()->create($attrs);

    $appProduct = Product::query()->create(['name' => 'App', 'sku' => 'app']);

    $unpaid = $seed(['name' => 'Unpaid', 'phone' => '9121111111']);
    $failed = $seed(['name' => 'Failed', 'phone' => '9122222222']); // status 3 on BazaarPay = unpaid
    $paid = $seed(['name' => 'Paid', 'phone' => '9123333333']);
    $unrelated = $seed(['name' => 'Unrelated', 'phone' => '9124444444']);

    $appLine = static fn (CrmInvoice $invoice) => CrmInvoiceLine::create([
        'crm_invoice_id' => $invoice->id, 'product_id' => $appProduct->id, 'product_name' => 'App subscription',
    ]);

    $appLine(CrmInvoice::query()->create(['legacy_id' => 1, 'customer_id' => $unpaid->id, 'status' => 1, 'total' => 100, 'invoice_date' => now()->subDays(5)]));
    $appLine(CrmInvoice::query()->create(['legacy_id' => 2, 'customer_id' => $failed->id, 'status' => 3, 'pay_type' => 8, 'total' => 100, 'invoice_date' => now()->subDays(5)]));
    $appLine(CrmInvoice::query()->create(['legacy_id' => 3, 'customer_id' => $paid->id, 'status' => 1, 'total' => 100, 'invoice_date' => now()->subDays(5)]));
    $appLine(CrmInvoice::query()->create(['legacy_id' => 4, 'customer_id' => $paid->id, 'status' => 2, 'total' => 100, 'invoice_date' => now()->subDays(2)]));

    $segment = LeadSource::query()->where('slug', 'app_not_pay')->firstOrFail();
    $tree = $segment->config['segment'];

    $evaluator = app(SegmentEvaluator::class);
    $ids = $evaluator->evaluate($tree);

    expect($ids)->toContain($unpaid->id, $failed->id)
        ->not->toContain($paid->id)
        ->not->toContain($unrelated->id);
});

it('caches segment snapshots for 600 seconds', function () {
    $segment = LeadSource::query()->where('slug', 'app_users_lead')->firstOrFail();
    $tree = $segment->config['segment'];
    $evaluator = app(SegmentEvaluator::class);

    $product = Product::query()->create(['name' => 'App', 'sku' => 'app']);
    $renewer = Customer::query()->create(['name' => 'Renewer', 'phone' => '9125555555']);
    Subscription::query()->create([
        'customer_id' => $renewer->id,
        'product_id' => $product->id,
        'status' => 'active',
        'amount' => 100,
        'started_at' => now()->subDays(10),
        'ends_at' => now()->addDays(15),
    ]);

    $first = $evaluator->cached($segment->slug, $tree);
    expect($first)->toHaveCount(1);

    // A second read within the TTL keeps the old snapshot even if data grows.
    Customer::query()->create(['name' => 'Another', 'phone' => '9126666666']);
    Subscription::query()->create([
        'customer_id' => Customer::query()->where('name', 'Another')->first()->id,
        'product_id' => $product->id,
        'status' => 'active',
        'amount' => 100,
        'started_at' => now()->subDays(1),
        'ends_at' => now()->addDays(20),
    ]);

    expect($evaluator->cached($segment->slug, $tree))->toHaveCount(1)
        ->and($evaluator->count($tree))->toBe(2); // live preview sees both
});

it('refuses trees deeper than 3 or with more than 20 rules', function () {
    $evaluator = app(SegmentEvaluator::class);

    $deep = ['match' => 'all', 'rules' => [
        ['match' => 'all', 'rules' => [
            ['match' => 'all', 'rules' => [
                ['match' => 'all', 'rules' => [
                    ['criterion' => 'signup', 'params' => []],
                ]],
            ]],
        ]],
    ]];

    $evaluator->evaluate($deep);
})->throws(RuntimeException::class, 'depth');

/** ---------------- Commission PRD ---------------- */
function seedEarningScenario(): array
{
    $agent = User::factory()->create(['name' => 'Agent 107', 'exten' => '107']);
    CrmDiscount::create(['code' => 'vip107', 'operatorid' => $agent->id]);

    $buyer = Customer::query()->create(['name' => 'Buyer', 'phone' => '9121234567']);
    $contact = LeadContact::create(['phone' => '9121234567', 'customer_id' => $buyer->id]);
    $source = LeadSource::query()->where('slug', 'starttraining')->first();
    $source->update(['commission_percent' => 5]);

    $entry = (new LeadCaptureService(new LegacyUserLinker))->capture('starttraining', ['phone' => '9121234567', 'name' => 'Buyer', 'origin_ref' => 'c1']);
    $referral = app(ReferralService::class)->assignOne($source, LeadEntry::query()->findOrFail($entry->entryId), $agent, 'single', null, null);

    $invoice = CrmInvoice::query()->create([
        'legacy_id' => 9001, 'customer_id' => $buyer->id, 'phone' => '9121234567',
        'total' => 2000000, 'discount' => 'VIP107', 'status' => 2, 'pay_type' => 2,
        'invoice_date' => now(), 'paid_at' => now()->addMinutes(1),
    ]);

    return [$agent, $buyer, $source, $invoice, $referral];
}

it('pays the discount-code owner at the assignment source rate', function () {
    config(['crm.commission.start_at' => now()->subDay()->toDateTimeString()]);
    [$agent, $buyer, $source, $invoice, $referral] = seedEarningScenario();

    $result = (new CommissionSyncService)->sync();

    expect($result['processed'])->toBe(1);

    $earning = CommissionEarning::query()->firstOrFail();
    expect($earning->agent_id)->toBe($agent->id)
        ->and((float) $earning->rate)->toBe(5.0)           // source rate (5 %) beats the PRD default
        ->and($earning->paid_amount)->toBe(2000000)
        ->and($earning->amount)->toBe(100000)
        ->and($earning->status)->toBe('ok')
        ->and($earning->discount_code)->toBe('VIP107') // exact code kept for audit
        ->and($earning->month)->toBe(Jalali::monthKey(now()))
        ->and($earning->assignment_id)->toBe($referral->assignment()->first()->id);
});

it('marks ambiguous discount codes for review with no agent', function () {
    config(['crm.commission.start_at' => now()->subDay()->toDateTimeString()]);
    [$agent, $buyer, $source, $invoice, $referral] = seedEarningScenario();

    // The same code is owned by two operators → review.
    $other = User::factory()->create(['exten' => '109']);
    CrmDiscount::create(['code' => 'vip107', 'operatorid' => $other->id]);

    (new CommissionSyncService)->sync();

    $earning = CommissionEarning::query()->firstOrFail();
    expect($earning->agent_id)->toBeNull()
        ->and($earning->status)->toBe('review')
        ->and($earning->amount)->toBe(100000); // amount still computed
});

it('inserts nothing for purchases without an agent code', function () {
    $buyer = Customer::query()->create(['name' => 'Buyer', 'phone' => '9121234567']);

    CrmInvoice::query()->create([
        'legacy_id' => 9002, 'customer_id' => $buyer->id,
        'total' => 500000, 'discount' => null, 'status' => 2,
        'invoice_date' => now(), 'paid_at' => now(),
    ]);

    expect((new CommissionSyncService)->sync()['processed'])->toBe(0)
        ->and(CommissionEarning::query()->count())->toBe(0);
});

it('never records the same invoice twice', function () {
    config(['crm.commission.start_at' => now()->subDay()->toDateTimeString()]);
    seedEarningScenario();
    $service = new CommissionSyncService;

    $service->sync();
    $service->sync();

    expect(CommissionEarning::query()->count())->toBe(1);
});

it('records one lump-sum payout per agent per month', function () {
    $agent = User::factory()->create();
    $month = Jalali::monthKey(now());

    CommissionPayout::create(['agent_id' => $agent->id, 'month' => $month, 'amount' => 500000, 'paid_on' => now()]);

    CommissionPayout::create(['agent_id' => $agent->id, 'month' => $month, 'amount' => 1, 'paid_on' => now()]);
})->throws(UniqueConstraintViolationException::class);
