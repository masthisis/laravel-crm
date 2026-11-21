<?php

namespace App\Services\Leads;

/**
 * Result of a capture attempt (spec 05 §3 semantics).
 */
final class CaptureResult
{
    public const CAPTURED = 'captured';

    public const ALREADY_CAPTURED = 'already_captured';

    public const RETURNING = 'returning';

    public const INVALID_PHONE = 'invalid_phone';

    public const REFUSED = 'refused';

    public function __construct(
        public readonly string $status,
        public readonly ?int $entryId = null,
        public readonly ?int $contactId = null,
        public readonly ?string $phone = null,
        public readonly ?string $reason = null,
    ) {}

    public function isCaptured(): bool
    {
        return $this->status === self::CAPTURED || $this->status === self::RETURNING;
    }
}
