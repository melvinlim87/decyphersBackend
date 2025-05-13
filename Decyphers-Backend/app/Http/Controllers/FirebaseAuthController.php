<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use Kreait\Firebase\Factory;

class FirebaseAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'idToken' => 'required|string',
        ]);

        try {
            // Initialize Firebase directly without dependency injection
            $serviceAccountPath = storage_path('app/firebase/firebase_credentials.json');
            $factory = (new Factory)
                ->withServiceAccount($serviceAccountPath)
                ->withProjectId('ai-crm-windsurf');
            
            $auth = $factory->createAuth();
            
            // Verify the token
            $verifiedIdToken = $auth->verifyIdToken($request->idToken);
            $firebaseUid = $verifiedIdToken->claims()->get('sub');
            $email = $verifiedIdToken->claims()->get('email');
            $name = $verifiedIdToken->claims()->get('name', $email);

            // Debug log for Firebase UID and claims
            \Log::info('Firebase UID Debug', [
                'firebaseUid' => $firebaseUid,
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
        } catch (\Exception $e) {
            // Log the specific error for debugging
            \Log::error('Firebase auth error: ' . $e->getMessage());
            
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
