<?php
/**
 * Unified AI Provider Router
 *
 * Routes AI calls to OpenAI, Anthropic (Claude), or Google (Gemini)
 * based on the model ID. All plugins should use itu_ai_call() instead
 * of direct API calls.
 *
 * Depends on: ai-settings.php (mu-plugin) for itu_ai_key() and itu_ai_model()
 */

if (!defined('ABSPATH')) exit;

/**
 * Detect the AI provider from a model ID.
 *
 * @param string $model Model identifier
 * @return string 'openai', 'anthropic', or 'gemini'
 */
function itu_ai_detect_provider($model) {
    if (str_starts_with($model, 'claude-')) return 'anthropic';
    if (str_starts_with($model, 'gemini-')) return 'gemini';
    return 'openai';
}

/**
 * Universal AI call function.
 *
 * @param string $instruction  System prompt / instruction
 * @param string $user_prompt  User message (optional)
 * @param string $model        Model ID (auto-detected if empty)
 * @param float  $temperature  0.0-1.0
 * @param array  $args         Optional: key_name, max_tokens, timeout
 * @return string|WP_Error     Generated text or error
 */
function itu_ai_call($instruction, $user_prompt = '', $model = '', $temperature = 0.7, $args = []) {
    // Resolve model
    if (empty($model)) {
        $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';
    }

    // Detect provider
    $provider = itu_ai_detect_provider($model);

    // Resolve API key
    $key_name = $args['key_name'] ?? $provider;
    $api_key = function_exists('itu_ai_key') ? itu_ai_key($key_name) : '';

    // If the key_name doesn't match the provider (e.g., key_name='blog_writer' but model is claude-),
    // fall back to the provider-specific key
    if (empty($api_key) && $key_name !== $provider) {
        $api_key = function_exists('itu_ai_key') ? itu_ai_key($provider) : '';
    }

    if (empty($api_key)) {
        return new WP_Error('no_key', "No API key configured for provider: {$provider} (key: {$key_name})");
    }

    $timeout = $args['timeout'] ?? 180;

    // Route to provider adapter
    switch ($provider) {
        case 'anthropic':
            return _itu_ai_call_anthropic($api_key, $model, $instruction, $user_prompt, $temperature, $args, $timeout);
        case 'gemini':
            return _itu_ai_call_gemini($api_key, $model, $instruction, $user_prompt, $temperature, $args, $timeout);
        default:
            return _itu_ai_call_openai($api_key, $model, $instruction, $user_prompt, $temperature, $args, $timeout);
    }
}

// ─── OpenAI Adapter ──────────────────────────────────────────────────────────

function _itu_ai_call_openai($api_key, $model, $instruction, $user_prompt, $temperature, $args, $timeout) {
    $messages = [['role' => 'system', 'content' => $instruction]];
    if ($user_prompt) {
        $messages[] = ['role' => 'user', 'content' => $user_prompt];
    }

    // Detect GPT-5.x and reasoning models (o3, o4) which don't support temperature
    $is_reasoning = str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o3') || str_starts_with($model, 'o4');

    $body = [
        'model'    => $model,
        'messages' => $messages,
    ];

    if ($is_reasoning) {
        // GPT-5.x: use reasoning_effort instead of temperature
        // Map temperature to reasoning_effort: low temp = less reasoning, high = more
        if ($temperature <= 0.2) $body['reasoning_effort'] = 'low';
        elseif ($temperature <= 0.5) $body['reasoning_effort'] = 'medium';
        else $body['reasoning_effort'] = 'none'; // Creative tasks — skip reasoning, faster
        // Note: temperature, top_p, logprobs are NOT supported when reasoning is active
    } else {
        $body['temperature'] = $temperature;
    }

    if (!empty($args['max_tokens'])) {
        $body['max_tokens'] = $args['max_tokens'];
    }

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => json_encode($body),
        'timeout' => $timeout,
    ]);

    if (is_wp_error($response)) return $response;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code >= 400) {
        $err = $data['error']['message'] ?? "HTTP {$code}";
        return new WP_Error('openai_error', "OpenAI error: {$err}");
    }

    $content = trim($data['choices'][0]['message']['content'] ?? '');
    if (empty($content)) return new WP_Error('empty', 'No content returned from OpenAI.');

    return $content;
}

// ─── Anthropic Claude Adapter ────────────────────────────────────────────────

function _itu_ai_call_anthropic($api_key, $model, $instruction, $user_prompt, $temperature, $args, $timeout) {
    $max_tokens = $args['max_tokens'] ?? 4096;

    // Claude: system prompt is a top-level parameter, NOT in messages
    $messages = [];
    if ($user_prompt) {
        $messages[] = ['role' => 'user', 'content' => $user_prompt];
    } else {
        // Claude requires at least one user message
        $messages[] = ['role' => 'user', 'content' => $instruction];
        $instruction = ''; // Already in the user message
    }

    $body = [
        'model'       => $model,
        'max_tokens'  => $max_tokens,
        'temperature' => $temperature,
        'messages'    => $messages,
    ];

    // Only add system if we have a separate instruction
    if (!empty($instruction)) {
        $body['system'] = $instruction;
    }

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'headers' => [
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ],
        'body'    => json_encode($body),
        'timeout' => $timeout,
    ]);

    if (is_wp_error($response)) return $response;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code >= 400) {
        $err = $data['error']['message'] ?? "HTTP {$code}";
        return new WP_Error('anthropic_error', "Claude error: {$err}");
    }

    // Claude response: content is an array of blocks
    $content = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $content .= $block['text'];
        }
    }

    $content = trim($content);
    if (empty($content)) return new WP_Error('empty', 'No content returned from Claude.');

    return $content;
}

// ─── Google Gemini Adapter ───────────────────────────────────────────────────

function _itu_ai_call_gemini($api_key, $model, $instruction, $user_prompt, $temperature, $args, $timeout) {
    // Gemini: API key in URL, different body structure
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

    $body = [
        'contents' => [
            [
                'role'  => 'user',
                'parts' => [['text' => $user_prompt ?: $instruction]],
            ],
        ],
        'generationConfig' => [
            'temperature' => $temperature,
        ],
    ];

    // Add system instruction if we have both instruction and user_prompt
    if (!empty($instruction) && !empty($user_prompt)) {
        $body['system_instruction'] = [
            'parts' => [['text' => $instruction]],
        ];
    }

    if (!empty($args['max_tokens'])) {
        $body['generationConfig']['maxOutputTokens'] = $args['max_tokens'];
    }

    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($body),
        'timeout' => $timeout,
    ]);

    if (is_wp_error($response)) return $response;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code >= 400) {
        $err = $data['error']['message'] ?? "HTTP {$code}";
        return new WP_Error('gemini_error', "Gemini error: {$err}");
    }

    // Check for safety filter blocks
    $finish = $data['candidates'][0]['finishReason'] ?? '';
    if ($finish === 'SAFETY') {
        return new WP_Error('gemini_safety', 'Gemini blocked this content due to safety filters.');
    }

    $content = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if (empty($content)) return new WP_Error('empty', 'No content returned from Gemini.');

    return $content;
}
