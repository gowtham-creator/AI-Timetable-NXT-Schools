<?php

namespace app\components\ai;

use Yii;

/**
 * LlmRouter — picks whichever LLM provider the school has configured and
 * normalises responses, so features never hard-depend on one vendor:
 *
 *   1. Anthropic Claude  — ANTHROPIC_API_KEY  (the `ai` component)
 *   2. Google Gemini     — GEMINI_API_KEY     (free tier available)
 *   3. none              — callers fall back to deterministic logic
 *
 * Every feature built on this MUST keep a no-LLM fallback path; the router
 * returning null is a normal, supported state — not an error.
 */
class LlmRouter
{
    /** @return array{client:object,kind:string,model:string}|null */
    public static function resolve(): ?array
    {
        $anthropic = Yii::$app->get('ai', false);
        if ($anthropic !== null && $anthropic->apiKey !== '') {
            return ['client' => $anthropic, 'kind' => 'anthropic', 'model' => $anthropic->model];
        }
        $gemini = new GeminiClient();
        if ($gemini->apiKey !== '') {
            return ['client' => $gemini, 'kind' => 'gemini', 'model' => $gemini->model];
        }
        return null;
    }

    /**
     * Provider-agnostic chat. Returns ['text' =>, 'tokens_in' =>, 'tokens_out' =>, 'latency_ms' =>].
     */
    public static function chat(array $llm, array $messages, ?string $systemPrompt = null): array
    {
        if ($llm['kind'] === 'anthropic') {
            $out  = $llm['client']->chat($messages, $systemPrompt, false);
            $resp = $out['response'];
            $text = '';
            foreach (($resp['content'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'];
                }
            }
            return [
                'text'       => $text,
                'tokens_in'  => $resp['usage']['input_tokens'] ?? null,
                'tokens_out' => $resp['usage']['output_tokens'] ?? null,
                'latency_ms' => $out['latency_ms'],
            ];
        }

        // gemini
        $out  = $llm['client']->chat($messages, $systemPrompt);
        $resp = $out['response'];
        $text = '';
        foreach (($resp['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        return [
            'text'       => $text,
            'tokens_in'  => $resp['usageMetadata']['promptTokenCount'] ?? null,
            'tokens_out' => $resp['usageMetadata']['candidatesTokenCount'] ?? null,
            'latency_ms' => $out['latency_ms'],
        ];
    }
}
