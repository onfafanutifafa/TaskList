<?php

use Illuminate\Support\Facades\Schedule;

// Reconciliation safety net: settle transactions whose callbacks were missed.
Schedule::command('psp:poll-pending')->everyMinute()->withoutOverlapping();

// Retry merchant webhook deliveries that failed and are now due.
Schedule::command('webhooks:flush')->everyMinute()->withoutOverlapping();
