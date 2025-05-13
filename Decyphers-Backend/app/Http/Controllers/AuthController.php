<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Kreait\Firebase\Auth as FirebaseAuth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // Register new user
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
        ], 201);
    }

    // Login user
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    // Get authenticated user
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    // Logout user
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    // Firebase login for bridging Firebase Auth to Laravel Sanctum
    public function firebaseLogin(Request $request, FirebaseAuth $firebaseAuth)
    {
        $request->validate([
            'idToken' => 'required|string',
        ]);

        try {
            $verifiedIdToken = $firebaseAuth->verifyIdToken($request->idToken);
            $firebaseUid = $verifiedIdToken->claims()->get('sub');
            $email = $verifiedIdToken->claims()->get('email');
            $name = $verifiedIdToken->claims()->get('name', $email);

            // Debug log for Firebase UID and claims
            \Log::info('Firebase Login Debug', [
                'firebaseUid' => $firebaseUid,
                'email' => $email,
                'claims' => $verifiedIdToken->claims()->all(),
            ]);

            // First check if user exists by email
            $existingUser = User::where('email', $email)->first();
            
            if ($existingUser && empty($existingUser->firebase_uid)) {
                // User exists but doesn't have Firebase UID - update it
                $existingUser->firebase_uid = $firebaseUid;
                $existingUser->save();
                $user = $existingUser;
                \Log::info('Updated existing user with Firebase UID', ['user_id' => $user->id]);
            } else {
                // Find or create user by Firebase UID
                $user = User::firstOrCreate(
                    ['firebase_uid' => $firebaseUid],
                    [
                        'email' => $email,
                        'name' => $name,
                        'password' => Hash::make(Str::random(32)), // random password
                    ]
                );
            }

            // Issue Sanctum token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ]);
        } catch (\Throwable $e) {
            // Log the specific error for debugging
            \Log::error('Firebase auth error in AuthController: ' . $e->getMessage());
            
            // Check for specific Firebase errors
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'auth/email-already-in-use') !== false) {
                return response()->json([
                    'message' => 'This email is already registered. Please try signing in with your password.',
                    'error' => 'email-already-in-use',
                    'error_details' => $errorMessage
                ], 409); // Conflict status code
            }
            
            return response()->json([
                'message' => 'Authentication failed',
                'error' => $errorMessage
            ], 401);
        }
    }
}
