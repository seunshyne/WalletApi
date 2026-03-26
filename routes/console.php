<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('wallet-fundings:expire-pending')->everyTenMinutes();
