<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Events\Verified;
use App\Jobs\SendVerificationEmail;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{

    /**
     * Register a new user with automatic wallet creation
     */
    public function register(Request $request)
    {
        Log::info('Register endpoint hit', ['data' => $request->email]);


        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|confirmed|min:8',
        ], [
            'name.required' => 'Name is required',
            'email.required' => 'Email is required',
            'email.email' => 'Enter a valid email address',
            'email.unique' => 'This email is already registered',
            'password.required' => 'Password is required',
            'password.confirmed' => 'Passwords do not match',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $fields = $validator->validated();

        try {
            $user = DB::transaction(function () use ($fields) {

                // Create user
                $user = User::create([
                    'name' => $fields['name'],
                    'email' => $fields['email'],
                    'password' => Hash::make($fields['password']),
                ]);
                // Send email verification
                SendVerificationEmail::dispatch($user->id);

                Log::info('User created', ['user_id' => $user->id]);

                return $user;
            });

            Log::info('Token created');

            Log::info('Registration completed successfully', ['user_id' => $user->id]);

            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'created_at' => $user->created_at,
                ],
                'status' => 'success',
                'message' => 'Registration successful. Please verify your email before logging in.'
            ], 201);
        } catch (Exception $e) {
            // Log the detailed error for debugging
            Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return a generic error message to the client
            return response()->json([
                'message' => 'Registration failed. Please try again.'
            ], 500);
        }
    }

    //verify email via signed link
       public function verifyEmail(Request $request, $id, $hash)
{
    try {
        $user = User::findOrFail($id);

        // Check hash matches first (before signature check)
        if (!hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            return redirect(config('app.frontend_url') . '/login?verified=invalid');
        }

        // Already verified - redirect to success
        if ($user->hasVerifiedEmail()) {
            return redirect(config('app.frontend_url') . '/login?verified=already');
        }

        // Manually validate signature (more reliable)
        if (!$request->hasValidSignature()) {
            // Log what's happening
            Log::warning('Invalid signature', [
                'url' => $request->fullUrl(),
                'user_id' => $id,
            ]);
            return redirect(config('app.frontend_url') . '/login?verified=invalid');
        }

        // Mark as verified and fire event
        $user->markEmailAsVerified();
        event(new Verified($user));

        Log::info('Email verified successfully', ['user_id' => $user->id]);

        return redirect(config('app.frontend_url') . '/login?verified=success');

    } catch (\Exception $e) {
        Log::error('Email verification failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return redirect(config('app.frontend_url') . '/login?verified=error');
    }
}


    public function resendVerificationEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Email already verified',
            ]);
        }

        // Dispatch the job again
        SendVerificationEmail::dispatch($user->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Verification email resent. Please check your inbox.',
        ]);
    }



    /**
     * Log in a user
     */
    public function login(Request $request)
    {
        try {
            $fields = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
            ]);

            $user = User::where('email', $fields['email'])->first();

            if (!$user || !Hash::check($fields['password'], $user->password)) {
                return response()->json([
                    'errors' => [
                        'email' => ['The provided credentials are incorrect.']
                    ]
                ], 401);
            } 

            //Block login if email not verified
            if (!$user->hasVerifiedEmail()) {
                return response()->json([
                    'status' => 'unverified',
                    'message' => 'Please verify your email to activate your account.',
                    'email' => $user->email,
                ], 403);
            }


            // Get user's wallet
            $wallet = $user->wallet;

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                ],
                'wallet' => $wallet,
                'token' => $token,
            ], 200);
        } catch (Exception $e) {
            Log::error('Login error', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Login failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Log out a user
     */
    public function logout(Request $request)
    {
        try {

            if ($request->user() && $request->user()->currentAccessToken()) {
                $request->user()->currentAccessToken()->delete();
            }

            return response()->json([
                'message' => 'Logged out successfully'
            ]);

        } catch (Exception $e) {

            Log::error('Logout failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Logout failed'
            ], 500);
        }
    }

}
