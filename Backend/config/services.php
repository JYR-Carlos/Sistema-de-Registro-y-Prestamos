<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URL'),
    ],

    'gmail' => [
        'refresh_token' => env('GMAIL_REFRESH_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | UTAmed - inicio de sesion desde el sistema academico
    |--------------------------------------------------------------------------
    |
    | UTAmed firma un token con la identidad de la persona (RUT, correo, rol) y
    | SGEQ lo verifica con la clave publica. Ver App\Http\Controllers\Auth\SsoController.
    |
    | 'public_key_path'        Clave publica que entrega UTAmed. Solo la publica:
    |                          SGEQ verifica, nunca emite.
    | 'issuer' / 'audience'    Tienen que coincidir con lo que firma UTAmed.
    | 'frontend_callback_url'  Pantalla del SPA que recibe el ticket.
    |
    | Quien puede entrar y con que rol lo decide UTAmed; aca solo se aceptan los
    | roles de SsoController::ROLES_PERMITIDOS.
    |
    */
    'utamed_sso' => [
        'public_key_path' => env('UTAMED_SSO_PUBLIC_KEY_PATH', storage_path('app/sso/utamed-sso-public.pem')),
        'issuer' => env('UTAMED_SSO_ISSUER', 'utamed'),
        'audience' => env('UTAMED_SSO_AUDIENCE', 'sgeq'),
        'frontend_callback_url' => env('UTAMED_SSO_FRONTEND_CALLBACK_URL', env('FRONTEND_URL', 'http://localhost:4200') . '/auth/sso-callback'),
    ],

];
