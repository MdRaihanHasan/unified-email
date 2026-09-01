<?php

use Illuminate\Support\Facades\Schedule;

// Polled accounts (Gmail API historyId, Graph deltaLink). IMAP accounts are driven
// by their IDLE daemon instead and are skipped here.
Schedule::command('mail:sync')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Safety net: pull IMAP accounts in too, in case an IDLE daemon has died quietly.
Schedule::command('mail:sync --all')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('mail:watchdog')->everyFifteenMinutes();

// A queued send whose Redis job evaporated, or one killed mid-flight, has no
// retry of its own — this is the only thing that ever brings it back.
Schedule::command('mail:sweep-outbound')->everyFiveMinutes()->withoutOverlapping();
