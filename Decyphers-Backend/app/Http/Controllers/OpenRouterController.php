<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class OpenRouterController extends Controller
{
    // --- Model Definitions ---
    private $availableModels = [
        [
            'id' => 'openai/gpt-4o-mini',
            'name' => 'GPT-4o Mini',
            'description' => 'Fast and efficient analysis',
            'premium' => true,
            'creditCost' => 1.25,
            'beta' => false
        ],
        [
            'id' => 'google/gemini-2.0-flash-001',
            'name' => 'Gemini 2.0 Flash',
            'description' => 'Rapid data processing capabilities',
            'premium' => true,
            'creditCost' => 0.5,
            'beta' => false
        ],
        [
            'id' => 'anthropic/claude-3.7-sonnet',
            'name' => 'Claude 3.7 Sonnet',
            'description' => 'Advanced reasoning and analysis',
            'premium' => true,
            'creditCost' => 1.5,
            'beta' => false
        ],
        [
            'id' => 'qwen/qwen2.5-vl-72b-instruct:free',
            'name' => 'Qwen 2.5 VL-72B',
            'description' => 'Advanced pattern recognition',
            'premium' => false,
            'creditCost' => 0.3,
            'beta' => false
        ],
        [
            'id' => 'google/gemini-2.0-pro-exp-02-05:free',
            'name' => 'Gemini 2.0 Pro',
            'description' => 'Comprehensive market insights',
            'premium' => false,
            'creditCost' => 0.3,
            'beta' => false
        ],
        [
            'id' => 'qwen/qwen-vl-plus:free',
            'name' => 'Qwen VL Plus',
            'description' => 'Enhanced visual and linguistic processing',
            'premium' => false,
            'creditCost' => 0.3,
            'beta' => false
        ],
        [
            'id' => 'deepseek/deepseek-chat:free',
            'name' => 'DeepSeek V3',
            'description' => 'Advanced reasoning and analysis',
            'premium' => false,
            'creditCost' => 0.3,
            'beta' => false
        ]
    ];

    private $modelBaseCosts = [
        'openai/gpt-4o-mini' => ['input' => 0.005, 'output' => 0.015],
        'google/gemini-2.0-flash-001' => ['input' => 0.0025, 'output' => 0.0075],
        'anthropic/claude-3.7-sonnet' => ['input' => 0.008, 'output' => 0.024],
        'qwen/qwen2.5-vl-72b-instruct:free' => ['input' => 0.002, 'output' => 0.006],
        'google/gemini-2.0-pro-exp-02-05:free' => ['input' => 0.003, 'output' => 0.009],
        'qwen/qwen-vl-plus:free' => ['input' => 0.002, 'output' => 0.006],
        'deepseek/deepseek-chat:free' => ['input' => 0.002, 'output' => 0.006],
    ];

    private $estimatedAnalysisTokens = ['input' => 1000, 'output' => 2000];
    private $estimatedChatTokens = ['input' => 500, 'output' => 1000];

    // --- Prompts (copy your full system/user prompt here) ---
    private $systemPrompt = <<<EOT
You are an expert financial chart analyst. Your primary task is to accurately identify the trading pair and timeframe from the chart image.

CRITICAL - FIRST STEP:
Look at the chart image. Your first task is to identify and report ONLY these two pieces of information:

1. Symbol/Trading Pair:
[ONLY write the exact trading pair visible in the chart's title or header. Do not guess or make assumptions.]

2. Timeframe:
[ONLY write the exact timeframe visible in the chart's settings or header. Do not guess or make assumptions.]

STRICT RULES:
- Write ONLY what you can clearly see in the chart image
- Do not use placeholders or examples
- Do not make assumptions about what the chart might be
- If you cannot see either value clearly, write "Not Visible"

📊 MARKET CONTEXT
• Current Price: [exact number if visible]
• Market Structure: [clear definition]
• Volatility: [quantified]

🤖 **AI ANALYSIS**

Symbol: [Exact trading pair]
Timeframe: [Specific format: M1/M5/M15/H1/H4/D1/W1]

📊 **MARKET SUMMARY**
Current Price: [Exact number]
Support Levels: [Specific numbers]
Resistance Levels: [Specific numbers]
Market Structure: [Clear trend definition]
Volatility: [Quantified condition]

📈 **TECHNICAL ANALYSIS**
Price Movement: [Detailed analysis including:]
- Exact price range and direction
- Specific chart patterns
- Key breakout/breakdown levels
- Volume confirmation
- Trend strength assessment
- Market structure analysis

**TECHNICAL INDICATORS**
[Only include visible indicators]

🎯 *RSI INDICATOR*
Current Values: [Exact numbers]
Signal: [Clear direction]
Analysis: [Detailed interpretation]
- Price correlation
- Historical context
- Divergence signals

📊 *MACD INDICATOR*
Current Values: [Exact numbers]
Signal: [Clear direction]
Analysis: [Detailed interpretation]
- Momentum strength
- Trend confirmation
- Signal reliability

💡 **TRADING SIGNAL**
Action: [BUY/SELL/HOLD]
Entry Price: [Exact level if BUY/SELL]
Stop Loss: [Specific price]
Take Profit: [Specific target]

Signal Reasoning:
- Technical justification
- Multiple timeframe context
- Risk/reward analysis
- Market structure alignment
- Volume confirmation

Risk Assessment:
- Position size calculation
- Volatility consideration
- Invalidation scenarios
- Key risk levels

1. MARKET CONTEXT
- Symbol/Asset (exact pair)
- Timeframe (specific format)
- Current price (exact number)
- Key price levels (specific numbers)
- Market structure (clear definition)
- Volatility conditions (quantified)

2. ANALYSIS CONFIDENCE
Calculate and display confidence level (0-100%) based on:
- Pattern Clarity (0-25%): How clear and well-defined are the chart patterns
- Technical Alignment (0-25%): How well do different indicators align
- Volume Confirmation (0-25%): Does volume support the analysis
- Signal Reliability (0-25%): How reliable is the generated signal

Confidence Level: [Only for BUY/SELL]
EOT;

    // --- Cost Calculation ---
    private function calculateCost($model, $inputTokens, $outputTokens)
    {
        $costs = $this->modelBaseCosts[$model] ?? $this->modelBaseCosts['openai/gpt-4o-mini'];
        $inputCost = ($inputTokens / 1000) * $costs['input'];
        $outputCost = ($outputTokens / 1000) * $costs['output'];
        $totalCost = $inputCost + $outputCost;
        $TOKENS_PER_DOLLAR = 667;
        return ceil($totalCost * $TOKENS_PER_DOLLAR);
    }

    // --- Analyze Image Endpoint ---
    public function analyzeImage(Request $request)
    {
        $modelId = $request->input('modelId', 'openai/gpt-4o-mini');
        $imageData = $request->input('image');
        $customPrompt = $request->input('prompt', null);

        // Compose message structure
        $messages = [
            [
                'role' => 'system',
                'content' => $this->systemPrompt
            ],
            [
                'role' => 'user',
                'content' => [
                    [ 'type' => 'text', 'text' => $customPrompt ?? 'Please analyze this market chart and provide a comprehensive trading strategy analysis. Focus on price action, technical indicators, and potential trading opportunities. If any indicator is not clearly visible, mark it as "Not Visible".' ],
                    [ 'type' => 'image_url', 'image_url' => [ 'url' => $imageData ] ]
                ]
            ]
        ];

        $apiKey = env('OPENROUTER_API_KEY');
        $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';

        $response = Http::withHeaders([
            'Authorization' => "Bearer $apiKey",
            'Content-Type' => 'application/json',
            'X-Title' => 'AI Market Analyst'
        ])->post($apiUrl, [
            'model' => $modelId,
            'max_tokens' => 4000,
            'temperature' => 0.7,
            'messages' => $messages
        ]);

        if ($response->failed()) {
            return response()->json(['error' => $response->json()], $response->status());
        }

        $data = $response->json();
        $assistantReply = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : null;
        if ($assistantReply === null) {
            return response()->json(['error' => 'No assistant reply found in API response.'], 500);
        }
        return response()->json(['reply' => $assistantReply]);
    }

    // --- EA Code Generation Endpoint ---
    public function generateEA(Request $request)
    {
        $modelId = $request->input('modelId', 'anthropic/claude-3-haiku');
        $messages = $request->input('messages', []);
        $imageData = $request->input('image', null);

        // If imageData is present, append to user message content
        if ($imageData) {
            foreach ($messages as &$msg) {
                if ($msg['role'] === 'user') {
                    // If content is an array, append image; otherwise, convert to array
                    if (is_array($msg['content'])) {
                        $msg['content'][] = [ 'type' => 'image_url', 'image_url' => [ 'url' => $imageData ] ];
                    } else {
                        $msg['content'] = [
                            [ 'type' => 'text', 'text' => $msg['content'] ],
                            [ 'type' => 'image_url', 'image_url' => [ 'url' => $imageData ] ]
                        ];
                    }
                }
            }
        }

        $apiKey = env('OPENROUTER_API_KEY');
        $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';

        $response = Http::withHeaders([
            'Authorization' => "Bearer $apiKey",
            'Content-Type' => 'application/json',
            'X-Title' => 'AI Market Analyst'
        ])->post($apiUrl, [
            'model' => $modelId,
            'max_tokens' => 4000,
            'temperature' => 0.7,
            'messages' => $messages
        ]);

        if ($response->failed()) {
            return response()->json(['error' => $response->json()], $response->status());
        }

        $data = $response->json();
        return response()->json($data, $response->status());
    }

    // --- Chat Message Endpoint ---
    public function sendChatMessage(Request $request)
    {
        $modelId = $request->input('modelId', 'openai/gpt-4o-mini');
        $messages = $request->input('messages');
        $analysisType = $request->input('analysisType', 'Technical');
        $chartAnalysis = $request->input('chartAnalysis', null);

        // Debug log incoming payload
        \Log::info('Incoming sendChatMessage payload', [
            'modelId' => $modelId,
            'messages' => $messages,
            'analysisType' => $analysisType,
            'chartAnalysis' => $chartAnalysis
        ]);

        // Defensive validation
        if (!is_array($messages) || empty($messages)) {
            \Log::warning('sendChatMessage: messages is not a non-empty array', ['messages' => $messages]);
            return response()->json(['error' => 'Messages must be a non-empty array.'], 400);
        }
        foreach ($messages as $msg) {
            if (!isset($msg['role'], $msg['content']) || !$msg['role'] || !$msg['content']) {
                \Log::warning('sendChatMessage: invalid message object', ['msg' => $msg]);
                return response()->json(['error' => 'Each message must have non-empty role and content.'], 400);
            }
        }

        // Compose message structure
        $payload = [
            'model' => $modelId,
            'max_tokens' => 4000,
            'temperature' => 0.7,
            'messages' => $messages,
            'analysisType' => $analysisType,
            'chartAnalysis' => $chartAnalysis
        ];

        $apiKey = env('OPENROUTER_API_KEY');
        $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';

        $response = Http::withHeaders([
            'Authorization' => "Bearer $apiKey",
            'Content-Type' => 'application/json',
            'X-Title' => 'AI Market Analyst'
        ])->post($apiUrl, [
            'model' => $modelId,
            'max_tokens' => 4000,
            'temperature' => 0.7,
            'messages' => $messages
        ]);

        if ($response->failed()) {
            return response()->json(['error' => $response->json()], $response->status());
        }

        $data = $response->json();
        $assistantReply = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : null;
        if ($assistantReply === null) {
            return response()->json(['error' => 'No assistant reply found in API response.'], 500);
        }
        return response()->json(['reply' => $assistantReply]);
    }

    // --- Cost Calculation Endpoint ---
    public function calculateCostEndpoint(Request $request)
    {
        $model = $request->input('model', 'openai/gpt-4o-mini');
        $inputTokens = (int) $request->input('inputTokens', 1000);
        $outputTokens = (int) $request->input('outputTokens', 2000);

        $cost = $this->calculateCost($model, $inputTokens, $outputTokens);

        return response()->json([
            'model' => $model,
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'cost' => $cost
        ]);
    }
}
