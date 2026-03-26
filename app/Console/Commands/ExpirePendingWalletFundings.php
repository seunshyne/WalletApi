<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WalletFunding;

class ExpirePendingWalletFundings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
protected $signature   = 'wallet-fundings:expire-pending';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark wallet funding records older than 60 minutes as expired';

    /**
     * Execute the console command.
     */
    public function handle()
    {
         $expiredCount = WalletFunding::query()
            ->where('status', WalletFunding::STATUS_PENDING)
            ->where('created_at', '<=', now()->subMinutes(60))
            ->update(['status' => WalletFunding::STATUS_EXPIRED]);

        $this->info("Expired {$expiredCount} pending wallet funding(s).");

    }
}
