<?php

return [
    // Where contacts' pictures are stored: the local public disk in development, DigitalOcean Spaces (s3) in production.
    'avatar_disk' => env('AVATAR_DISK', 'public'),

    // Where pictures contacts send are stored: private (served only to their user), DigitalOcean Spaces in production.
    'images_disk' => env('IMAGES_DISK', 'local'),

    // Gemini Live's built-in voices: a contact's voice is one of these (no cloned voices for now).
    'voices' => [
        'Zephyr', 'Puck', 'Charon', 'Kore', 'Fenrir', 'Leda', 'Orus', 'Aoede', 'Callirrhoe', 'Autonoe',
        'Enceladus', 'Iapetus', 'Umbriel', 'Algieba', 'Despina', 'Erinome', 'Algenib', 'Rasalgethi', 'Laomedeia', 'Achernar',
        'Alnilam', 'Schedar', 'Gacrux', 'Pulcherrima', 'Achird', 'Zubenelgenubi', 'Vindemiatrix', 'Sadachbia', 'Sadaltager', 'Sulafat',
    ],

    // Genders a contact can have (used later to match relationships: sister vs brother, and so on).
    'genders' => ['woman', 'man', 'nonbinary'],
];
