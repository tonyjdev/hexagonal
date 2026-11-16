<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Servicio importer (services/importer): lee los Excel de un plan
    | nutricional y los devuelve como JSON (HEXM-0031, ADR 0024).
    |
    | CONCEPTO: la dirección de un adaptador es configuración
    | Ni el puerto PlanReader ni el adaptador HttpPlanReader saben dónde está
    | el servicio: se lo da MealPlanningServiceProvider leyendo esta clave.
    | En local es el `uv run poe serve` (puerto 8001); en producción, lo que
    | diga IMPORTER_URL. El timeout se convierte a float aquí porque env()
    | siempre devuelve texto.
    */
    'importer' => [
        'url' => env('IMPORTER_URL', 'http://127.0.0.1:8001'),
        'timeout' => (float) env('IMPORTER_TIMEOUT', 10),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
