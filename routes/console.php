<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------
| Scheduled commands
|--------------------------------------------------------------------
| The external-database sync pulls leads from the foreign database
| configured in config/crm.php (external_sync). It is a no-op unless
| EXTERNAL_SYNC_ENABLED=true.
*/
Schedule::command('crm:sync-external-leads')->hourly();
