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
