<?php

return [
    /*
     * The version of the Terms, Privacy Policy and AI disclosure (resources/views/legal). Change it when any of them
     * changes materially: everyone is asked to accept again before using the app (App\Http\Middleware\EnsureTermsAccepted).
     */
    'terms_version' => '2026-10-25',

    // Where people write about their account, privacy or a safety concern.
    'contact_email' => env('LEGAL_CONTACT_EMAIL', 'support@example.com'),
];
