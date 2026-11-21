<?php

namespace App\Console\Commands;

use App\Services\Phone\PbxService;
use Illuminate\Console\Command;

class VerifyCdrCommand extends Command
{
    protected $signature = 'crm:verify-cdr {--minutes=40 : How far back to look for unverified calls}';

    protected $description = 'Fill call durations and dispositions from the PBX CDR bridge (spec 04 §5.5)';

    public function handle(PbxService $pbx): int
    {
        if (! $pbx->bridgeEnabled()) {
            $this->error('PBX bridge is not configured (CRM_PBX_BRIDGE).');

            return self::FAILURE;
        }

        $verified = $pbx->verifyCdr((int) $this->option('minutes'));

        $this->info("Verified {$verified} call record(s).");

        return self::SUCCESS;
    }
}
