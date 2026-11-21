<?php

namespace App\Services\Phone;

use App\Models\CrmNote;
use App\Models\CrmNoteReply;
use App\Models\CrmOncall;
use App\Models\CrmSmsMessage;
use App\Models\Customer;
use App\Models\LeadContact;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Operator workspace actions (spec 04 §3.3, §3.5, §3.7): notes with
 * replies, call-queue outcomes (which write a call-status note), and the
 * SMS sender the legacy UI never had (bug 11 #11 fixed).
 */
class WorkspaceService
{
    /**
     * Add a note (POST add_new_ticket per 04 §3.3).
     */
    public function addNote(int $customerId, User $operator, string $subject, string $content, ?int $departmentId = null, ?int $categoryId = null, int $status = CrmNote::STATUS_NOTE): CrmNote
    {
        $note = CrmNote::create([
            'customer_id' => $customerId,
            'operator_id' => $operator->id,
            'subject' => mb_substr($subject, 0, 255),
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'status' => $status,
            'ticket_number' => random_int(10000000, 99999999),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->reply($note, $operator, $content);

        return $note;
    }

    /** Reply to a note (04 §3.3 note view) — no debug dumps (11 #14). */
    public function reply(CrmNote $note, User $operator, string $message): CrmNoteReply
    {
        $note->fill(['updated_at' => now()])->save();

        return CrmNoteReply::create([
            'crm_note_id' => $note->id,
            'operator_id' => $operator->id,
            'message' => $message,
            'created_at' => now(),
        ]);
    }

    /**
     * Record a call-queue outcome (add_status_oncalls per 04 §3.7): a
     * call-status note (status 2) plus the queue row status update.
     */
    public function recordOutcome(CrmOncall $oncall, User $operator, int $statusId, string $content): void
    {
        $this->addNote(
            customerId: $this->customerIdForPhone($oncall->phone) ?? 0,
            operator: $operator,
            subject: 'وضعیت تماس',
            content: $content !== '' ? $content : ('نتیجه تماس: '.$statusId),
            status: CrmNote::STATUS_CALL_NOTE,
        );

        $oncall->fill(['status' => $statusId])->save();
    }

    /**
     * Send an SMS through the provider and log it (04 §3.5). When no
     * provider is configured the message is stored as 'logged' only.
     */
    public function sendSms(string $phone, string $body, ?User $operator = null, ?int $referralId = null, ?int $template = null): CrmSmsMessage
    {
        $message = CrmSmsMessage::create([
            'phone' => $phone,
            'operator_id' => $operator?->id,
            'referral_id' => $referralId,
            'template' => $template,
            'body' => $body,
            'parts' => CrmSmsMessage::partsFor($body),
            'status' => 'pending',
        ]);

        $endpoint = config('crm.sms.endpoint');
        $apiKey = config('crm.sms.api_key');

        if ($endpoint === null || $apiKey === null) {
            // No provider configured — keep it logged, do not pretend.
            $message->fill(['status' => 'logged'])->save();

            return $message;
        }

        try {
            $response = Http::timeout(10)->post($endpoint, [
                'apiKey' => $apiKey,
                'mobile' => $phone,
                'text' => $body,
            ]);

            $message->fill([
                'status' => $response->successful() ? 'sent' : 'failed',
                'provider_reference' => (string) $response->json('data.messageId', ''),
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('SMS send failed', ['phone' => $phone, 'error' => $e->getMessage()]);
            $message->fill(['status' => 'failed'])->save();
        }

        return $message;
    }

    /** The three hard-coded templates of ارجاع روز (04 §3.5). */
    public static function smsTemplates(string $operatorName): array
    {
        return [
            1 => [
                'label' => 'معرفی ویدیو',
                'body' => "سلام، من {$operatorName} هستم از تیم علیرضا مهرابی؛ لطفاً این ویدیو را ببینید تا با اپلیکیشن آرم اینوست آشنا شوید.",
            ],
            2 => [
                'label' => 'کد تخفیف و لینک ثبت‌نام',
                'body' => 'سلام؛ کد تخفیف اختصاصی شما: {code} — لینک ثبت‌نام: {link}',
            ],
            3 => [
                'label' => 'تماس بی‌پاسخ',
                'body' => "سلام، {$operatorName} هستم؛ تماس شما را دریافت کردم اما پاسخ ندادید. لطفاً در اولین فرصت با من تماس بگیرید.",
            ],
        ];
    }

    private function customerIdForPhone(string $phone): ?int
    {
        return LeadContact::query()->where('phone', $phone)->value('customer_id')
            ?? Customer::query()->where('phone', $phone)->value('id');
    }
}
