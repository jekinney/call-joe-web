<?php

return [
    // Where contacts' pictures are stored: the local public disk in development, DigitalOcean Spaces (s3) in production.
    'avatar_disk' => env('AVATAR_DISK', 'public'),

    // Genders a contact can have (used later to match relationships: sister vs brother, and so on).
    'genders' => ['woman', 'man', 'nonbinary'],
];
