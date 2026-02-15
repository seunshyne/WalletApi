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

        // Generate signed URL for email verification
        $backendUrl = URL::temporarySignedRoute(
        'verification.verify',
        Carbon::now()->addMinutes(60),
        [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]
    );

    //Extract query string (expires + signature)
    $query = parse_url($backendUrl, PHP_URL_QUERY);

    // 3️⃣ Build frontend URL
    $verificationUrl = config('app.frontend_url')
        . "/verify-email/{$user->id}/" . sha1($user->email)
        . "?{$query}";

    Mail::to($user->email)
        ->send(new VerifyEmailMail($verificationUrl));
    }
}
