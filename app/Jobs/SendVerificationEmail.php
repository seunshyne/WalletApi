<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;
use App\Mail\VerifyEmailMail;
use Illuminate\Contracts\Mail\Mailable;




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

        $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        Carbon::now()->addMinutes(60),
        [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]
    );

    Mail::to($user->email)
        ->send(new VerifyEmailMail($verificationUrl));
    }
}
