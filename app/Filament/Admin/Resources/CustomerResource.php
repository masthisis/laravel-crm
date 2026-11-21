<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CustomerResource\Pages;
use App\Filament\Agent\Pages\MyReferrals;
use App\Models\CrmCallLog;
use App\Models\CrmDepartment;
use App\Models\CrmInstallment;
use App\Models\CrmInvoice;
use App\Models\CrmNoteCategory;
use App\Models\CrmReferral;
use App\Models\Customer;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Persian;
use App\Support\PhoneNormalizer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('email')->email()->maxLength(255),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(32),
                Forms\Components\TextInput::make('company')->maxLength(255),
                Forms\Components\TextInput::make('country')->maxLength(255),
                Forms\Components\Textarea::make('notes')->columnSpanFull(),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\TextColumn::make('company')->toggleable(),
                Tables\Columns\TextColumn::make('orders_count')->counts('orders')->label('Purchases'),
                Tables\Columns\TextColumn::make('subscriptions_count')->counts('subscriptions')->label('Subscriptions'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Shared customer-360 infolist (also reused by the agent panel).
     */
    public static function customerInfolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Profile')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email')->icon('heroicon-m-envelope'),
                    TextEntry::make('phone')->icon('heroicon-m-phone'),
                    TextEntry::make('company')->placeholder('—'),
                    TextEntry::make('country')->placeholder('—'),
                    TextEntry::make('notes')->columnSpanFull()->placeholder('—'),
                ]),
            ]),
            Section::make('Purchases')
                ->description('Every product this customer bought and when.')
                ->schema([
                    RepeatableEntry::make('orders')
                        ->hiddenLabel()
                        ->state(fn (Customer $record) => $record->orders->map(fn ($o) => [
                            'product' => $o->product->name ?? '—',
                            'amount' => number_format((float) $o->amount, 2).' '.config('crm.currency', 'USD'),
                            'agent' => $o->agent->name ?? '—',
                            'sold_at' => $o->sold_at?->format('Y-m-d') ?? '—',
                        ])->all())
                        ->schema([
                            TextEntry::make('product'),
                            TextEntry::make('amount'),
                            TextEntry::make('agent'),
                            TextEntry::make('sold_at'),
                        ])
                        ->columns(4),
                ]),
            Section::make('Subscriptions')
                ->schema([
                    RepeatableEntry::make('subscriptions')
                        ->hiddenLabel()
                        ->state(fn (Customer $record) => $record->subscriptions->map(fn ($s) => [
                            'product' => $s->product->name ?? '—',
                            'status' => $s->status->label(),
                            'amount' => number_format((float) $s->amount, 2).' '.config('crm.currency', 'USD').' / '.$s->interval,
                            'started_at' => $s->started_at?->format('Y-m-d') ?? '—',
                            'ends_at' => $s->ends_at?->format('Y-m-d') ?? 'open ended',
                        ])->all())
                        ->schema([
                            TextEntry::make('product'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('amount'),
                            TextEntry::make('started_at'),
                            TextEntry::make('ends_at'),
                        ])
                        ->columns(5),
                ]),
            Section::make('Lead history')
                ->schema([
                    RepeatableEntry::make('leads')
                        ->hiddenLabel()
                        ->state(fn (Customer $record) => $record->leads->map(fn ($l) => [
                            'name' => $l->name,
                            'status' => $l->status->label(),
                            'source' => $l->source->name ?? '—',
                            'created_at' => $l->created_at?->format('Y-m-d') ?? '—',
                        ])->all())
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('source'),
                            TextEntry::make('created_at'),
                        ])
                        ->columns(4),
                ])
                ->collapsed(),
            Section::make('کارتابل CRM — لید و منابع')
                ->description('مالک فعلی، منابع ورود و تاریخچه ارجاع (07 §2).')
                ->schema([
                    RepeatableEntry::make('leadContacts')
                        ->hiddenLabel()
                        ->state(fn (Customer $record) => $record->leadContacts->map(fn ($c) => [
                            'phone' => $c->phone,
                            'owner' => User::find($c->owner_operator_id)->name ?? '—',
                            'owner_code' => $c->owner_code ?? '—',
                            'assigned_at' => Jalali::format($c->owner_assigned_at),
                            'first_seen' => Jalali::format($c->first_seen_at),
                            'last_seen' => Jalali::format($c->last_seen_at),
                            'sources' => $c->contactSources->map(fn ($s) => $s->source->name.' ('.$s->entries.')')->implode('، ') ?: '—',
                        ])->all())
                        ->schema([
                            TextEntry::make('phone'),
                            TextEntry::make('owner'),
                            TextEntry::make('owner_code'),
                            TextEntry::make('assigned_at'),
                            TextEntry::make('first_seen'),
                            TextEntry::make('last_seen'),
                            TextEntry::make('sources')->columnSpanFull(),
                        ])
                        ->columns(6),
                ])
                ->collapsed(),
            Section::make('یادداشت‌های CRM')
                ->schema([
                    RepeatableEntry::make('crmNotes')
                        ->hiddenLabel()
                        ->state(fn (Customer $record) => $record->crmNotes->map(fn ($n) => [
                            'subject' => $n->subject,
                            'operator' => $n->operator->name ?? '—',
                            'department' => CrmDepartment::query()->whereKey($n->department_id)->value('name') ?? '—',
                            'category' => CrmNoteCategory::query()->whereKey($n->category_id)->value('name') ?? '—',
                            'created_at' => Jalali::format($n->created_at),
                            'replies' => (string) $n->replies->count(),
                        ])->all())
                        ->schema([
                            TextEntry::make('subject'),
                            TextEntry::make('operator'),
                            TextEntry::make('department'),
                            TextEntry::make('category'),
                            TextEntry::make('created_at'),
                            TextEntry::make('replies'),
                        ])
                        ->columns(6),
                ])
                ->collapsed(),
            Section::make('تماس‌ها')
                ->description('بر اساس شماره اصلی — هویت تماس در لاگ PBX با exten است (01 §2.4).')
                ->schema([
                    RepeatableEntry::make('crmCalls')
                        ->hiddenLabel()
                        ->state(function (Customer $record) {
                            return CrmCallLog::query()
                                ->where('phone', PhoneNormalizer::canonical($record->phone) ?? $record->phone)
                                ->orderByDesc('called_at')
                                ->limit(50)
                                ->get()
                                ->map(fn ($call) => [
                                    'phone' => $call->phone,
                                    'operator' => User::where('exten', $call->accepted_call ?? '')->value('name') ?? ($call->accepted_call ?: 'ندارد'),
                                    'direction' => $call->type === 'incoming' ? 'ورودی' : 'خروجی',
                                    'duration' => $call->durationLabel() ?: '—',
                                    'called_at' => Jalali::format($call->called_at, 'Y/m/d H:i'),
                                ])->all();
                        })
                        ->schema([
                            TextEntry::make('phone'),
                            TextEntry::make('operator'),
                            TextEntry::make('direction'),
                            TextEntry::make('duration'),
                            TextEntry::make('called_at'),
                        ])
                        ->columns(5),
                ])
                ->collapsed(),
            Section::make('ارجاع‌ها')
                ->schema([
                    RepeatableEntry::make('crmReferrals')
                        ->hiddenLabel()
                        ->state(function (Customer $record) {
                            $canonical = PhoneNormalizer::canonical($record->phone) ?? $record->phone;

                            return CrmReferral::query()
                                ->where('phone', $canonical)
                                ->orderByDesc('id')
                                ->limit(20)
                                ->get()
                                ->map(fn ($r) => [
                                    'lead_type' => MyReferrals::leadTypeLabel($r->lead_type),
                                    'operator' => User::where('exten', $r->reagent_code)->value('name') ?? $r->reagent_code,
                                    'status' => $r->status === '1' ? 'باز' : 'بسته',
                                    'reason' => $r->agent_description ?? '—',
                                    'course' => $r->course ?? '—',
                                    'referred_at' => Jalali::format($r->referred_at),
                                ])->all();
                        })
                        ->schema([
                            TextEntry::make('lead_type')->badge(),
                            TextEntry::make('operator'),
                            TextEntry::make('status')->badge()
                                ->color(static fn (string $state) => $state === 'باز' ? 'warning' : 'success'),
                            TextEntry::make('reason'),
                            TextEntry::make('course'),
                            TextEntry::make('referred_at'),
                        ])
                        ->columns(6),
                ])
                ->collapsed(),
            Section::make('اقساط')
                ->schema([
                    RepeatableEntry::make('crmInstallments')
                        ->hiddenLabel()
                        ->state(fn (Customer $record) => $record->crmInstallments->map(fn ($i) => [
                            'product' => $i->product_title ?? '—',
                            'amount' => Persian::toman($i->amount),
                            'due_date' => Jalali::format($i->due_date),
                            'status' => $i->isOverdue() ? 'معوق' : ($i->status === 1 ? 'پرداخت شده' : 'پرداخت نشده'),
                        ])->all())
                        ->schema([
                            TextEntry::make('product'),
                            TextEntry::make('amount'),
                            TextEntry::make('due_date'),
                            TextEntry::make('status')->badge()
                                ->color(static fn (string $state) => $state === 'معوق' ? 'danger' : ($state === 'پرداخت شده' ? 'success' : 'warning')),
                        ])
                        ->columns(4),
                ])
                ->collapsed(),
            Section::make('نکات گفتگو (Talking points)')
                ->description('پیشنهادهای خودکار برای تماس (07 §2).')
                ->schema([
                    TextEntry::make('talking_points')
                        ->hiddenLabel()
                        ->state(function (Customer $record) {
                            $points = [];
                            $sub = $record->subscriptions()->whereNotNull('ends_at')->orderByDesc('ends_at')->first();
                            if ($sub !== null) {
                                if ($sub->ends_at->isPast()) {
                                    $points[] = 'اشتراک اپ منقضی شده است ('.Jalali::format($sub->ends_at).')';
                                } elseif (Carbon::parse($sub->ends_at)->diffInDays(now()) <= 30) {
                                    $points[] = 'اشتراک اپ تا '.Jalali::format($sub->ends_at).' منقضی می‌شود — فرصت تمدید';
                                }
                            }
                            $unpaid = $record->orders->count() > 0 ? null : null;
                            $cart = CrmInvoice::query()
                                ->where('customer_id', $record->id)
                                ->whereIn('status', [1])
                                ->orderByDesc('id')->first();
                            if ($cart !== null) {
                                $points[] = 'سبد پرداخت‌نشده از '.Jalali::format($cart->invoice_date).' باز است';
                            }
                            $installment = CrmInstallment::query()->where('customer_id', $record->id)->overdue()->first();
                            if ($installment !== null) {
                                $points[] = 'قسط معوق به مبلغ '.Persian::toman($installment->amount);
                            }
                            $lastNote = $record->crmNotes()->latest('created_at')->first();
                            if ($lastNote !== null) {
                                $points[] = 'آخرین یادداشت: «'.$lastNote->subject.'» در '.Jalali::format($lastNote->created_at);
                            }
                            if ($points === []) {
                                $points[] = 'نکته خاصی پیدا نشد';
                            }

                            return $points;
                        })
                        ->listWithLineBreaks()
                        ->columnSpanFull(),
                ])
                ->collapsed(),
            Section::make('Upcoming renewals')
                ->description('Subscriptions renewing within 30 days — call before they lapse.')
                ->schema([
                    RepeatableEntry::make('renewalAlerts')
                        ->hiddenLabel()
                        ->state(function (Customer $record) {
                            $window = now()->addDays((int) config('crm.subscriptions.renewal_window', 30));

                            return $record->subscriptions()
                                ->whereNotNull('ends_at')
                                ->where('ends_at', '<=', $window)
                                ->orderBy('ends_at')
                                ->get()
                                ->map(fn ($s) => [
                                    'product' => $s->product->name ?? '—',
                                    'renews_at' => $s->getAttribute('ends_at')?->format('Y-m-d').' ('.$s->getAttribute('ends_at')?->diffForHumans().')',
                                    'status' => $s->status->label(),
                                ])->all();
                        })
                        ->schema([
                            TextEntry::make('product')->badge()->color('warning'),
                            TextEntry::make('renews_at'),
                            TextEntry::make('status')->badge(),
                        ])->columns(3)
                        ->placeholder('No subscriptions renewing in the next 30 days.'),
                ])
                ->collapsed(),
            Section::make('Customer activity')
                ->description('Notes and calls across this customer and their leads — newest first.')
                ->schema([
                    RepeatableEntry::make('activityTimeline')
                        ->hiddenLabel()
                        ->state(function (Customer $record) {
                            return $record->leads->flatMap->activities
                                ->merge($record->activities)
                                ->sortByDesc('occurred_at')
                                ->values()
                                ->map(fn ($a) => [
                                    'type' => $a->type->label(),
                                    'notes' => $a->notes,
                                    'occurred_at' => $a->occurred_at->format('Y-m-d H:i'),
                                    'by' => $a->agent->name ?? 'system',
                                ])->all();
                        })
                        ->schema([
                            TextEntry::make('type')->badge(),
                            TextEntry::make('notes'),
                            TextEntry::make('occurred_at'),
                            TextEntry::make('by'),
                        ])->columns(4)
                        ->placeholder('No activity recorded yet.'),
                ])
                ->collapsed(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
