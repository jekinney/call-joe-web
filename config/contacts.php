<?php

return [
    // Where contacts' pictures are stored: the local public disk in development, DigitalOcean Spaces (s3) in production.
    'avatar_disk' => env('AVATAR_DISK', 'public'),

    // Voice providers Vapi can speak with (a contact's voice is one of these plus that provider's voice id).
    'voice_providers' => ['vapi', '11labs', 'cartesia', 'playht', 'azure', 'openai', 'deepgram'],

    // Genders a contact can have (used later to match relationships: sister vs brother, and so on).
    'genders' => ['woman', 'man', 'nonbinary'],
];
