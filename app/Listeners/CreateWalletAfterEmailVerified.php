<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Verified;
use App\Services\WalletService;

class CreateWalletAfterEmailVerified
{
    protected WalletService $walletService;
    /**
     * Create the event listener.
     */
    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }


    /**
     * Handle the event.
     */
    public function handle(Verified $event): void
    {
        // Create a wallet for the user after email verification
        
        $this->walletService->createForUser($event->user);
    }
}
