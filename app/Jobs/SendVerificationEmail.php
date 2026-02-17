<?php

namespace App\Jobs;

use App\Models\User;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;
use App\Mail\VerifyEmailMail;
use Illuminate\Support\Facades\Log;



class SendVerificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::findOrFail($this->userId);

        // Force HTTPS and correct domain for signed URL
        URL::forceRootUrl(config('app.url'));
        URL::forceScheme('https');

        // Generate signed URL for email verification
        $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        Carbon::now()->addMinutes(60),
        [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]
    );

    Log::info('Verification URL generated', [
        'url' => $verificationUrl,
        'user_id' => $user->id
    ]);

    Mail::to($user->email)
        ->send(new VerifyEmailMail($verificationUrl));
    }
}
