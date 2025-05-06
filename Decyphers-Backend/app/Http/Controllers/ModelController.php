<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class ModelController extends BaseController
{
    private $availableModels = [
        [ 'id' => 'openai/gpt-4o-2024-11-20', 'name' => 'GPT-4o', 'description' => 'Fast and efficient analysis', 'premium' => true, 'creditCost' => 1.25, 'beta' => false ],
        [ 'id' => 'google/gemini-2.0-flash-001', 'name' => 'Gemini 2.0 Flash', 'description' => 'Rapid data processing capabilities', 'premium' => true, 'creditCost' => 0.5, 'beta' => false ],
        [ 'id' => 'anthropic/claude-3.7-sonnet', 'name' => 'Claude 3.7 Sonnet', 'description' => 'Advanced reasoning and analysis', 'premium' => true, 'creditCost' => 1.5, 'beta' => false ],
        [ 'id' => 'qwen/qwen2.5-vl-72b-instruct:free', 'name' => 'Qwen 2.5 VL-72B', 'description' => 'Multimodal analysis', 'premium' => false, 'creditCost' => 0.35, 'beta' => false ],
        [ 'id' => 'google/gemini-2.0-pro-exp-02-05:free', 'name' => 'Gemini 2.0 Pro', 'description' => 'General analysis', 'premium' => false, 'creditCost' => 0.3, 'beta' => false ],
        [ 'id' => 'qwen/qwen-vl-plus:free', 'name' => 'Qwen VL Plus', 'description' => 'Multimodal analysis', 'premium' => false, 'creditCost' => 0.3, 'beta' => false ],
        [ 'id' => 'deepseek/deepseek-chat:free', 'name' => 'DeepSeek V3', 'description' => 'Advanced reasoning and analysis', 'premium' => false, 'creditCost' => 0.3, 'beta' => false ]
    ];

    private $MODEL_BASE_COSTS = [
        'openai/gpt-4o-2024-11-20' => [ 'input' => 0.005, 'output' => 0.015 ],
        'google/gemini-2.0-flash-001' => [ 'input' => 0.00035, 'output' => 0.00105 ],
        'anthropic/claude-3.7-sonnet' => [ 'input' => 0.003, 'output' => 0.015 ],
        'qwen/qwen2.5-vl-72b-instruct:free' => [ 'input' => 0.0002, 'output' => 0.0006 ],
        'google/gemini-2.0-pro-exp-02-05:free' => [ 'input' => 0.00025, 'output' => 0.00075 ],
        'qwen/qwen-vl-plus:free' => [ 'input' => 0.0002, 'output' => 0.0006 ],
        'deepseek/deepseek-chat:free' => [ 'input' => 0.0002, 'output' => 0.0006 ],
        // fallback
        'openai/gpt-4o-mini' => [ 'input' => 0.0025, 'output' => 0.0075 ]
    ];
    private $ESTIMATED_CHAT_TOKENS = [ 'input' => 800, 'output' => 400 ];
    private $ESTIMATED_ANALYSIS_TOKENS = [ 'input' => 2000, 'output' => 1000 ];
    private $TOKENS_PER_DOLLAR = 667;

    public function getAvailableModels()
    {
        return response()->json($this->availableModels);
    }

    public function calculateTokenCost(Request $request)
    {
        $modelId = $request->input('modelId');
        $isAnalysis = $request->input('isAnalysis', false);
        $baseCosts = $this->MODEL_BASE_COSTS[$modelId] ?? $this->MODEL_BASE_COSTS['openai/gpt-4o-mini'];
        $tokenEstimate = $isAnalysis ? $this->ESTIMATED_ANALYSIS_TOKENS : $this->ESTIMATED_CHAT_TOKENS;
        $tokenCost = $this->calculateCost($modelId, $tokenEstimate['input'], $tokenEstimate['output']);
        return response()->json(['cost' => ceil($tokenCost)]);
    }

    private function calculateCost($modelId, $inputTokens, $outputTokens)
    {
        $costs = $this->MODEL_BASE_COSTS[$modelId] ?? $this->MODEL_BASE_COSTS['openai/gpt-4o-mini'];
        $inputCost = ($inputTokens / 1000.0) * $costs['input'];
        $outputCost = ($outputTokens / 1000.0) * $costs['output'];
        $totalCost = $inputCost + $outputCost;
        $costInUSD = $totalCost;
        $appTokenCost = ceil($costInUSD * $this->TOKENS_PER_DOLLAR);
        return $appTokenCost;
    }
}
