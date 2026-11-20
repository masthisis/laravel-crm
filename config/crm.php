<?php

return [

    /*
    |------------------------------------------------------------------
    | CRM application settings
    |------------------------------------------------------------------
    */

    'currency' => env('CRM_CURRENCY', 'USD'),

    'commission' => [
        'rounding' => (int) env('CRM_COMMISSION_ROUNDING', 2),
    ],

    'capture' => [
        'throttle' => (int) env('CRM_CAPTURE_THROTTLE', 30),
    ],

    'leads' => [
        // A lead is "stale" when it is open and has had no activity
        // for this many days (admin + agent stale filters).
        'stale_days' => (int) env('CRM_LEADS_STALE_DAYS', 7),
    ],

    'import' => [
        // skip: rows whose phone matches an existing open lead are counted
        // as duplicates and not imported (safe default — re-importing the
        // same file imports nothing). import: allow them through (e.g.
        // first migration of a legacy CRM).
        'duplicate' => env('CRM_IMPORT_DUPLICATE', 'skip'),
    ],

    /*
    |------------------------------------------------------------------
    | External lead database sync
    |------------------------------------------------------------------
    | Column mapping used by `crm:sync-external-leads` so that any
    | foreign table can be ingested without touching its schema.
    */
    'external_sync' => [
        'enabled' => (bool) env('EXTERNAL_SYNC_ENABLED', false),
        'connection' => env('EXTERNAL_SYNC_CONNECTION', 'external'),
        'table' => env('EXTERNAL_SYNC_TABLE', 'external_leads'),
        'reference_column' => env('EXTERNAL_SYNC_REF_COLUMN', 'id'),
        'columns' => [
            'name' => env('EXTERNAL_SYNC_COL_NAME', 'full_name'),
            'phone' => env('EXTERNAL_SYNC_COL_PHONE', 'phone'),
            'email' => env('EXTERNAL_SYNC_COL_EMAIL', 'email'),
            'source' => env('EXTERNAL_SYNC_COL_SOURCE', 'source_name'),
        ],
    ],

];
