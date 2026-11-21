<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Api\Controller;
use App\Models\CrmCallLog;
use App\Models\Customer;
use App\Models\CustomerAdminLog;
use App\Models\LeadContact;
use App\Models\User;
use App\Services\Leads\EngagementService;
use App\Services\Phone\PbxService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;

/**
 * «ثبت کاربر» — the unknown-number register page (spec 04 §3.4): the
 * number passes through FixNumber; a known number redirects to the
 * customer file; otherwise show the engagement + calls + the add form.
 */
class RegisterCustomerController extends Controller
{
    public function show(string $phone)
    {
        $number = PbxService::fixNumber(urldecode($phone));

        if ($number === null || $number === '0') {
            return redirect('/agent');
        }

        $customer = Customer::findByCanonicalPhone($number);
        if ($customer !== null) {
            return redirect('/agent/customers/'.$customer->id);
        }

        $contact = LeadContact::query()->where('phone', $number)->first();
        $engagement = null;
        if ($contact !== null) {
            $profile = app(EngagementService::class)->profile($contact);
            $engagement = [
                'first' => $profile['first'] !== null ? ['label' => $profile['first']['label'], 'at' => $profile['first']['at']] : null,
                'latest' => $profile['latest'] !== null ? ['label' => $profile['latest']['label'], 'at' => $profile['latest']['at']] : null,
            ];
        }

        $operators = User::query()->pluck('name', 'exten');
        $calls = CrmCallLog::query()
            ->where('phone', $number)
            ->where(static function ($q) {
                $q->whereNotNull('accepted_call')->where('accepted_call', '!=', '');
            })
            ->orderByDesc('called_at')
            ->limit(20)
            ->get()
            ->map(static fn ($c) => [
                'phone' => $c->phone,
                'operator' => $operators[$c->accepted_call ?? ''] ?? null,
                'duration' => $c->durationLabel(),
                'called_at' => $c->called_at,
            ]);

        return view('agent.register-customer', [
            'phone' => $number,
            'engagement' => $engagement,
            'calls' => $calls,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'phone2' => 'nullable|string|max:20',
            'phone3' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
        ]);

        $number = PbxService::fixNumber($request->route('phone') ?? ($data['phone'] ?? ''));
        $canonical = PhoneNormalizer::canonical($number);

        $existing = $canonical !== null ? Customer::findByCanonicalPhone($canonical) : null;
        if ($existing !== null) {
            return response()->json(['status' => 'dublicate'], 409);
        }

        $customer = Customer::create([
            'name' => $data['name'],
            'phone' => $canonical ?? $number,
            'phone2' => PhoneNormalizer::canonical($data['phone2'] ?? null),
            'phone3' => PhoneNormalizer::canonical($data['phone3'] ?? null),
            'address' => $data['address'] ?? null,
            'account_status' => 'pending',
            'is_crm_created' => true,
            'created_by_operator_id' => $request->user()->id,
        ]);

        CustomerAdminLog::create([
            'admin_id' => $request->user()->id,
            'customer_id' => $customer->id,
            'action' => 'register',
            'details' => ['phone' => $customer->phone],
            'ip' => $request->ip(),
        ]);

        return redirect('/agent/customers/'.$customer->id);
    }
}
