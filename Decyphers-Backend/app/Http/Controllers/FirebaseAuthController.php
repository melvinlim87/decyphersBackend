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

            // Find or create user
            $user = User::firstOrCreate(
                ['firebase_uid' => $firebaseUid],
                [
                    'email' => $email,
                    'name' => $name,
                    'password' => Hash::make(Str::random(32)), // random password
                ]
            );

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
            
            return response()->json([
                'message' => 'Invalid Firebase token',
                'error' => $e->getMessage()
            ], 401);
        }
    }
}
