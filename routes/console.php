<?php

use Illuminate\Support\Facades\Schedule;

// Contacts reach out on their own (App\Services\Outreach). Runs on the server whether or not anyone is online.
Schedule::command('outreach:run')->everyMinute()->withoutOverlapping();
