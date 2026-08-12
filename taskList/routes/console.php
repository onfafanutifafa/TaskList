<?php

use Illuminate\Support\Facades\Schedule;

// Reconciliation safety net: settle transactions whose callbacks were missed.
Schedule::command('psp:poll-pending')->everyMinute()->withoutOverlapping();

// Crypto deposits whose watcher webhook was missed/delayed.
Schedule::command('crypto:poll-deposits')->everyMinute()->withoutOverlapping();

// Outbound bank payouts awaiting the partner's confirmation.
Schedule::command('bank:poll-payouts')->everyMinute()->withoutOverlapping();

// Retry merchant webhook deliveries that failed and are now due.
Schedule::command('webhooks:flush')->everyMinute()->withoutOverlapping();

// Horizon queue metrics for the dashboard.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
