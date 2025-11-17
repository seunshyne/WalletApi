<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Wallet;
use Exception;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Register a new user with automatic wallet creation
     */
    public function register(Request $request)
    {
        Log::info('Register endpoint hit', ['data' => $request->all()]);
        
        // Use database transaction to ensure data consistency
        DB::beginTransaction();

        try {
            $fields = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email',
                'password' => 'required|string|confirmed|min:6',
            ]);

            Log::info('Validation passed');

            // Create user
            $user = User::create([
                'name' => $fields['name'],
                'email' => $fields['email'],
                'password' => Hash::make($fields['password']),
            ]);

            Log::info('User created', ['user_id' => $user->id]);

            // Generate unique wallet address
            $walletAddress = 'WAL' . str_pad(random_int(0, 9999999), 7, '0', STR_PAD_LEFT);


            // Create wallet for the user
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'address' => $walletAddress,
                'balance' => 0.00,
                'currency' => 'NGN',
            ]);

            Log::info('Wallet created', ['wallet_id' => $wallet->id, 'address' => $wallet->address]);

            // Create auth token
            $token = $user->createToken('auth_token')->plainTextToken;

            Log::info('Token created');

            DB::commit();

            Log::info('Registration completed successfully', ['user_id' => $user->id]);

            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'created_at' => $user->created_at,
                ],
                'wallet' => [
                    'address' => $wallet->address,
                    'balance' => $wallet->balance,
                    'currency' => $wallet->currency,
                ],
                'token' => $token,
                'message' => 'Registration successful'
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'message' => 'Registration failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
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

            // Get user's wallet
            $wallet = Wallet::where('user_id', $user->id)->first();

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'wallet' => $wallet ? [
                    'address' => $wallet->address,
                    'balance' => $wallet->balance,
                    'currency' => $wallet->currency,
                ] : null,
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