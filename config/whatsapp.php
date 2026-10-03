<?php

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud API — Cierre Diario delivery (CARTA 9.1E)
|--------------------------------------------------------------------------
|
| ONE central AFORO sender (its own WABA + phone number). Restaurants never
| configure their own WABA in this version; they only choose a recipient.
|
| Every credential lives ONLY in the environment — never in the database,
| API responses, logs or the frontend. See docs/whatsapp-daily-close.md.
|
| graph_api_version is explicit configuration, never "latest": Meta
| versions the Graph API and upgrading must be a deliberate change.
*/

return [
    'enabled' => (bool) env('WHATSAPP_CLOUD_ENABLED', false),

    'graph_base_url' => 'https://graph.facebook.com',
    'graph_api_version' => env('WHATSAPP_GRAPH_API_VERSION'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'waba_id' => env('WHATSAPP_WABA_ID'),

    'template_name' => env('WHATSAPP_TEMPLATE_NAME'),
    'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'es_ES'),

    'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
    'app_secret' => env('META_APP_SECRET'),

    // Seconds. Explicit, short: a send must never hang a worker.
    'timeout' => 10,
    'connect_timeout' => 5,

    // Authenticated frontend route of a close's detail — the report link
    // is NEVER a public URL. {id} is replaced by the close id.
    'web_url' => env('AFORO_WEB_URL'),
    'report_path' => env('AFORO_DAY_CLOSE_REPORT_PATH', '/app/day-close/{id}'),
];
