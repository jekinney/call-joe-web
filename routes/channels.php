<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Each user's private channel: texts, rings, notices. Only that user may listen.
Broadcast::channel('App.Models.User.{id}', function (User $user, int|string $id): bool {
    return (int) $user->id === (int) $id;
});
