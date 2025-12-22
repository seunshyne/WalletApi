<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\URL;
use App\Models\User;


use Exception;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function verifyEmail($id, $hash)
    {
        $user = User::findOrFail($id);

        // Check if hash matches
        if (! hash_equals((string) $hash, sha1($user->email))) {
            return response()->json(['message' => 'Invalid verification link'], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified']);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return response()->json(['message' => 'Email verified successfully']);
    }
    /**
     * Register a new user with automatic wallet creation
     */
    public function register(Request $request)
    {
        Log::info('Register endpoint hit', ['data' => $request->email]);


        $fields = $request->validate([
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

        try {
            $user = DB::transaction(function () use ($fields, &$user) {

                // Create user
                $user = User::create([
                    'name' => $fields['name'],
                    'email' => $fields['email'],
                    'password' => Hash::make($fields['password']),
                ]);
                // Send email verification
                $user->sendEmailVerificationNotification();

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
            if (! $user->hasVerifiedEmail()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Please verify your email to activate your account.'
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
            $request->user()->currentAccessToken()->delete();

            return response()->json(['message' => 'Logged out successfully']);
        } catch (Exception $e) {
            Log::error('Logout failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Logout failed'
            ], 500);
        }
    }
}
