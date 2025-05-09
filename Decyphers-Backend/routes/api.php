<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FirebaseAuthController;
use App\Http\Controllers\StripeController;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/firebase-login', [FirebaseAuthController::class, 'login']);
Route::get('/config/recaptcha', [\App\Http\Controllers\ConfigController::class, 'getReCaptchaSiteKey']);
Route::get('/config/telegram', [\App\Http\Controllers\ConfigController::class, 'getTelegramConfig']);
Route::post('/verify-recaptcha', [\App\Http\Controllers\ConfigController::class, 'verifyReCaptcha']);

// Stripe payment routes
Route::post('/stripe/create-checkout', [StripeController::class, 'createCheckoutSession']);
Route::get('/stripe/verify-session', [StripeController::class, 'verifySession']);
Route::post('/stripe/verify-session', [StripeController::class, 'verifySessionPost']);
Route::post('/stripe/webhook', [StripeController::class, 'handleWebhook']);

// Existing OpenRouter routes
Route::post('/openrouter/generate-ea', [App\Http\Controllers\OpenRouterController::class, 'generateEA']);

// Protected routes
Route::middleware([EnsureFrontendRequestsAreStateful::class,'auth:sanctum',])->group(function () {

Route::get('/user', [AuthController::class, 'user']);
Route::post('/logout', [AuthController::class, 'logout']);

// OpenRouter imitation endpoints
Route::post('/openrouter/analyze-image', [\App\Http\Controllers\OpenRouterController::class, 'analyzeImage']);
Route::post('/openrouter/send-chat', [\App\Http\Controllers\OpenRouterController::class, 'sendChatMessage']);
Route::post('/openrouter/calculate-cost', [\App\Http\Controllers\OpenRouterController::class, 'calculateCostEndpoint']);

});

// Model endpoints
Route::get('/models', [\App\Http\Controllers\ModelController::class, 'getAvailableModels']);
Route::post('/model-cost', [\App\Http\Controllers\ModelController::class, 'calculateTokenCost']);
