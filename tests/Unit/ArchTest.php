<?php

// The shape of the codebase: Laravel's conventions, no debugging leftovers, strict types in our own code.

arch()->preset()->laravel();

arch('no debugging calls are left in')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('models are Eloquent models')
    ->expect('App\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('middleware has a handle method')
    ->expect('App\Http\Middleware')
    ->toHaveMethod('handle');
