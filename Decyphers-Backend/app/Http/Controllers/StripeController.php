<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\FirebaseService;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;

class StripeController extends Controller
{
    // Map price IDs to token amounts
    private const TOKEN_AMOUNTS = [
        // New token packages
        '7000_tokens' => 7000,
        '40000_tokens' => 40000,
        '100000_tokens' => 100000,
        // Specific price IDs
        'price_1R4cZ22NO6PNHfEnEhmEzX2y' => 7000,   // 7,000 tokens
        'price_1R4cZj2NO6PNHfEn4XiPU4tI' => 40000,  // 40,000 tokens
        'price_1R4caA2NO6PNHfEncTFmFBd4' => 100000  // 100,000 tokens
    ];

    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        // Initialize Stripe with the secret key
        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
        $this->firebaseService = $firebaseService;
    }

    /**
     * Create a Stripe checkout session
     */
    public function createCheckoutSession(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'priceId' => 'required|string',
                'userId' => 'required|string',
                'customerInfo.name' => 'nullable|string',
                'customerInfo.email' => 'nullable|email',
            ]);

            $priceId = $validated['priceId'];
            $userId = $validated['userId'];
            $customerInfo = $validated['customerInfo'] ?? [];

            // Verify price ID exists in Stripe
            try {
                $price = \Stripe\Price::retrieve($priceId);
                
                if (!$price->active) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Price is not active'
                    ], 400);
                }
            } catch (ApiErrorException $e) {
                Log::error('Error retrieving price from Stripe: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive price ID'
                ], 400);
            }

            // Create or retrieve customer if email is provided
            $customerId = null;
            if (isset($customerInfo['email'])) {
                try {
                    // Search for existing customers with this email
                    $customers = \Stripe\Customer::all([
                        'email' => $customerInfo['email'],
                        'limit' => 1
                    ]);
                    
                    if (count($customers->data) > 0) {
                        // Update existing customer
                        $customerId = $customers->data[0]->id;
                        \Stripe\Customer::update($customerId, [
                            'name' => $customerInfo['name'] ?? null
                        ]);
                    } else {
                        // Create new customer
                        $customer = \Stripe\Customer::create([
                            'email' => $customerInfo['email'],
                            'name' => $customerInfo['name'] ?? null,
                            'metadata' => [
                                'userId' => $userId
                            ]
                        ]);
                        $customerId = $customer->id;
                    }
                } catch (ApiErrorException $e) {
                    Log::error('Error creating/updating customer: ' . $e->getMessage());
                    // Continue without customer ID if there's an error
                }
            }

            // Create checkout session with appropriate customer parameters
            $sessionConfig = [
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'line_items' => [
                    [
                        'price' => $priceId,
                        'quantity' => 1,
                    ],
                ],
                'success_url' => env('FRONTEND_URL') . '/profile?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => env('FRONTEND_URL') . '/profile',
                'metadata' => [
                    'userId' => $userId,
                    'customer_name' => $customerInfo['name'] ?? '',
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'userId' => $userId,
                        'price_id' => $priceId,
                        'session_id' => '{CHECKOUT_SESSION_ID}',
                        'customer_name' => $customerInfo['name'] ?? '',
                        'customer_email' => $customerInfo['email'] ?? '',
                    ]
                ]
            ];
            
            // Add customer-related parameters based on what we have
            if ($customerId) {
                // If we have a customer ID, use it
                $sessionConfig['customer'] = $customerId;
            } else if (isset($customerInfo['email'])) {
                // If we have an email but no customer ID, let Stripe create a customer
                $sessionConfig['customer_email'] = $customerInfo['email'];
                $sessionConfig['customer_creation'] = 'always';
            }
            
            $session = Session::create($sessionConfig);

            return response()->json([
                'success' => true,
                'id' => $session->id,
                'sessionUrl' => $session->url
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Stripe error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error creating checkout session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session'
            ], 500);
        }
    }

    /**
     * Verify a Stripe checkout session
     */
    public function verifySession(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'session_id' => 'required|string',
            ]);

            $sessionId = $validated['session_id'];

            // Retrieve the session
            $session = Session::retrieve($sessionId);
            
            // Check if payment was successful
            if ($session->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'error' => 'Payment not completed',
                    'status' => $session->payment_status
                ], 400);
            }

            $userId = $session->metadata->userId ?? null;
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'error' => 'No user ID found in session metadata'
                ], 400);
            }

            // Get line items to determine token amount
            $lineItems = \Stripe\Checkout\Session::allLineItems($sessionId, ['limit' => 1]);
            
            if (count($lineItems->data) === 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'No line items found for this session'
                ], 400);
            }

            // Get the price ID from the line item
            $priceId = $lineItems->data[0]->price->id;
            
            // Determine which token package was purchased
            // This would be replaced with your actual mapping logic
            $packageKey = null;
            foreach (self::TOKEN_AMOUNTS as $key => $amount) {
                if (strpos($priceId, $key) !== false) {
                    $packageKey = $key;
                    break;
                }
            }
            
            $tokensToAdd = self::TOKEN_AMOUNTS[$packageKey] ?? 0;
            
            if ($tokensToAdd === 0) {
                // Fallback: Try to extract token amount from price description or metadata
                // This is a simplified example - you would need to adapt this to your actual data structure
                $tokensToAdd = $lineItems->data[0]->price->metadata->tokens ?? 0;
            }
            
            if ($tokensToAdd === 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'Could not determine token amount for this purchase'
                ], 400);
            }

            // Update user tokens in Firebase
            try {
                $purchaseData = [
                    'sessionId' => $sessionId,
                    'priceId' => $priceId,
                    'amount' => ($lineItems->data[0]->amount_total ?? 0) / 100, // Convert to dollars
                    'status' => $session->payment_status,
                    'customerEmail' => $session->customer_details->email ?? null,
                    'currency' => $lineItems->data[0]->currency ?? 'usd'
                ];
                
                $result = $this->firebaseService->updateUserTokens($userId, $tokensToAdd, $purchaseData);
                
                return response()->json([
                    'success' => true,
                    'tokensAdded' => $tokensToAdd,
                    'previousTotal' => $result['previousTokens'],
                    'newTotal' => $result['newTotal']
                ]);
            } catch (\Exception $e) {
                Log::error('Error updating user tokens: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to update user tokens: ' . $e->getMessage()
                ], 500);
            }
        } catch (ApiErrorException $e) {
            Log::error('Stripe error verifying session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Verification error: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error verifying session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Verification error'
            ], 500);
        }
    }

    /**
     * Verify a Stripe checkout session via POST request
     */
    public function verifySessionPost(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'priceId' => 'required|string',
                'userId' => 'required|string',
                'customerInfo.name' => 'nullable|string',
                'customerInfo.email' => 'nullable|email',
            ]);

            $priceId = $validated['priceId'];
            $userId = $validated['userId'];
            $customerInfo = $validated['customerInfo'] ?? [];

            // Verify price ID exists in Stripe
            try {
                $price = \Stripe\Price::retrieve($priceId);
                
                if (!$price->active) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Price is not active'
                    ], 400);
                }
            } catch (ApiErrorException $e) {
                Log::error('Error retrieving price from Stripe: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid or inactive price ID'
                ], 400);
            }

            // Direct lookup by price ID first
            $tokensToAdd = self::TOKEN_AMOUNTS[$priceId] ?? 0;
            Log::info('Direct price ID lookup result: ' . $tokensToAdd . ' for price ID: ' . $priceId);
            
            // If direct lookup fails, try partial matching
            if ($tokensToAdd === 0) {
                Log::info('Attempting partial matching for price ID: ' . $priceId);
                foreach (self::TOKEN_AMOUNTS as $key => $amount) {
                    Log::info('Checking if price ID ' . $priceId . ' contains ' . $key);
                    if (strpos($priceId, $key) !== false) {
                        $tokensToAdd = $amount;
                        Log::info('Found match: ' . $key . ' with amount: ' . $amount);
                        break;
                    }
                }
            }
            
            // If still no match, try to get token amount from price metadata
            if ($tokensToAdd === 0) {
                Log::info('Checking price metadata for tokens');
                if (isset($price->metadata) && isset($price->metadata->tokens)) {
                    $tokensToAdd = $price->metadata->tokens;
                    Log::info('Found tokens in metadata: ' . $tokensToAdd);
                } else {
                    Log::info('No tokens found in metadata');
                }
            }
            
            // Fallback: Assign a default value based on price amount
            if ($tokensToAdd === 0) {
                $priceAmount = ($price->unit_amount ?? 0) / 100;
                Log::info('Using fallback based on price amount: $' . $priceAmount);
                
                // Simple fallback logic - adjust as needed
                if ($priceAmount <= 10) {
                    $tokensToAdd = 7000;
                } else if ($priceAmount <= 50) {
                    $tokensToAdd = 40000;
                } else {
                    $tokensToAdd = 100000;
                }
                
                Log::info('Assigned fallback tokens: ' . $tokensToAdd);
            }
            
            if ($tokensToAdd === 0) {
                Log::error('Failed to determine token amount for price ID: ' . $priceId);
                return response()->json([
                    'success' => false,
                    'error' => 'Could not determine token amount for this price',
                    'priceId' => $priceId
                ], 400);
            }

            // Create or retrieve customer in Stripe
            $customerId = null;
            try {
                if (isset($customerInfo['email'])) {
                    // Search for existing customers with this email
                    $customers = \Stripe\Customer::all([
                        'email' => $customerInfo['email'],
                        'limit' => 1
                    ]);
                    
                    if (count($customers->data) > 0) {
                        // Use existing customer
                        $customerId = $customers->data[0]->id;
                        Log::info('Using existing customer: ' . $customerId);
                    } else {
                        // Create new customer
                        $customer = \Stripe\Customer::create([
                            'email' => $customerInfo['email'],
                            'name' => $customerInfo['name'] ?? null,
                            'metadata' => [
                                'userId' => $userId
                            ]
                        ]);
                        $customerId = $customer->id;
                        Log::info('Created new customer: ' . $customerId);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Error creating/finding customer: ' . $e->getMessage());
                // Continue without customer ID
            }
            
            // Create a checkout session instead of direct payment
            try {
                $sessionConfig = [
                    'mode' => 'payment',
                    'payment_method_types' => ['card'],
                    'line_items' => [
                        [
                            'price' => $priceId,
                            'quantity' => 1,
                        ],
                    ],
                    'success_url' => env('FRONTEND_URL', 'https://decyphers.com') . '/profile?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => env('FRONTEND_URL', 'https://decyphers.com') . '/profile',
                    'metadata' => [
                        'userId' => $userId,
                        'customer_name' => $customerInfo['name'] ?? '',
                        'direct_verification' => 'true'
                    ],
                    'payment_intent_data' => [
                        'metadata' => [
                            'userId' => $userId,
                            'price_id' => $priceId,
                            'tokens' => $tokensToAdd,
                            'session_id' => '{CHECKOUT_SESSION_ID}',
                            'customer_name' => $customerInfo['name'] ?? '',
                            'customer_email' => $customerInfo['email'] ?? '',
                        ]
                    ]
                ];
                
                // Add customer-related parameters based on what we have
                if ($customerId) {
                    // If we have a customer ID, use it
                    $sessionConfig['customer'] = $customerId;
                } else if (isset($customerInfo['email'])) {
                    // If we have an email but no customer ID, let Stripe create a customer
                    $sessionConfig['customer_email'] = $customerInfo['email'];
                    $sessionConfig['customer_creation'] = 'always';
                }
                
                $session = Session::create($sessionConfig);
                Log::info('Created checkout session: ' . $session->id);
                
                // For now, we'll still update tokens in Firebase
                // In production, you might want to wait for the webhook or session verification
                $purchaseData = [
                    'sessionId' => $session->id,
                    'priceId' => $priceId,
                    'amount' => ($price->unit_amount ?? 0) / 100, // Convert to dollars
                    'status' => 'pending', // Status is pending until payment is completed
                    'customerEmail' => $customerInfo['email'] ?? null,
                    'currency' => $price->currency ?? 'usd',
                    'type' => 'purchase' // Explicitly set the transaction type
                ];
                
                // Update user tokens in Firebase
                $result = $this->firebaseService->updateUserTokens($userId, $tokensToAdd, $purchaseData);
                
                return response()->json([
                    'success' => true,
                    'sessionId' => $session->id,
                    'sessionUrl' => $session->url, // URL to redirect the user to complete payment
                    'tokensAdded' => $tokensToAdd,
                    'previousTotal' => $result['previousTokens'],
                    'newTotal' => $result['newTotal']
                ]);
            } catch (\Exception $e) {
                Log::error('Error updating user tokens: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to update user tokens: ' . $e->getMessage()
                ], 500);
            }
        } catch (ApiErrorException $e) {
            Log::error('Stripe error verifying session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Verification error: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error verifying session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Verification error'
            ], 500);
        }
    }

    /**
     * Handle Stripe webhook events
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = env('STRIPE_WEBHOOK_SECRET');

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sigHeader, $endpointSecret
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            Log::error('Invalid payload: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            Log::error('Invalid signature: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Handle the event
        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                
                // Make sure it's a paid session
                if ($session->payment_status === 'paid') {
                    $userId = $session->metadata->userId ?? null;
                    
                    if ($userId) {
                        // Process the successful payment
                        // Add tokens to user account, record the transaction, etc.
                        Log::info('Payment successful for user: ' . $userId);
                        
                        // Here you would update your database
                        // This is just a placeholder
                    }
                }
                break;
                
            // Add other event types as needed
            
            default:
                Log::info('Unhandled event type: ' . $event->type);
        }

        return response()->json(['success' => true]);
    }
}
