<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Call log (the spec's rayan_crm_call_log). Identity quirks preserved:
 * an incoming call to a ring group creates one row per ringing extension
 * (same uniqueid); the agent is the extension, never user_id.
 *
 * @property string|null $phone
 * @property string|null $exten
 * @property string $type
 * @property string|null $accepted_call
 * @property int|null $call_time
 * @property string|null $status_asterisk
 * @property bool $cdr_verify
 * @property Carbon $called_at
 */
class CrmCallLog extends Model
{
    public const TYPE_INCOMING = 'incoming';

    public const TYPE_OUTGOING = 'outgoing';

    protected $fillable = [
        'phone',
        'phone_raw',
        'exten',
        'type',
        'uniqueid',
        'accepted_call',
        'hangup',
        'call_time',
        'status_asterisk',
        'cdr_verify',
        'called_at',
    ];

    protected function casts(): array
    {
        return [
            'cdr_verify' => 'boolean',
            'called_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exten', 'exten');
    }

    /** Scope: answered calls (incoming with an answering extension, or outgoing). */
    /** @param Builder<self> $query */
    public function scopeAnswered($query): mixed
    {
        return $query->where(static function ($q) {
            $q->where('type', self::TYPE_OUTGOING)
                ->orWhere(static function ($q2) {
                    $q2->where('type', self::TYPE_INCOMING)
                        ->whereNotNull('accepted_call')
                        ->where('accepted_call', '!=', '');
                });
        });
    }

    /** Answered = an answering extension recorded (incoming) or outgoing. */
    public function isAnswered(): bool
    {
        if ($this->type === self::TYPE_OUTGOING) {
            return true;
        }

        return $this->accepted_call !== null && $this->accepted_call !== '';
    }

    /** Duration H:i:s for display (04 §3.1). */
    public function durationLabel(): string
    {
        if ($this->call_time === null) {
            return '';
        }

        $h = intdiv($this->call_time, 3600);
        $m = intdiv($this->call_time % 3600, 60);
        $s = $this->call_time % 60;

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}
