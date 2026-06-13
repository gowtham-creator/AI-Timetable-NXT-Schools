<?php

namespace app\components\ai;

use yii\base\Component;

/**
 * GeminiClient — minimal Google Gemini REST client with the same calling
 * shape as AIClient::chat(), so the timetable feature can run on whichever
 * provider the school can afford. Configured purely via environment:
 *
 *   GEMINI_API_KEY=...                 (aistudio.google.com — free tier available)
 *   GEMINI_MODEL=gemini-2.5-flash      (default; flash is fast + cheap)
 *   GEMINI_THINKING_BUDGET=0           (default off — see note below)
 *
 * Thinking budget: Gemini 2.5 models are *reasoning* models that spend a
 * large, variable share of maxOutputTokens on hidden "thinking" tokens before
 * emitting the answer — verified live, a structured-JSON request burned ~765
 * thinking tokens and truncated. Both our tasks (rules→JSON extraction, short
 * factual narration) need no chain-of-thought, so thinking is OFF by default
 * (thinkingBudget=0): complete output, ~1.5s, cheaper. Raise it only for a
 * task that genuinely benefits from reasoning.
 */
class GeminiClient extends Component
{
    public string $apiKey = '';
    public string $model = 'gemini-2.5-flash';
    public string $apiBase = 'https://generativelanguage.googleapis.com/v1beta/models';
    public int $maxTokens = 2048;
    /** 0 = thinking off (default, correct for extraction/narration); -1 = let the model decide. */
    public int $thinkingBudget = 0;

    public function init()
    {
        parent::init();
        if ($this->apiKey === '') {
            $this->apiKey = getenv('GEMINI_API_KEY') ?: '';
        }
        $envModel = getenv('GEMINI_MODEL');
        if ($envModel) {
            $this->model = $envModel;
        }
        $envBudget = getenv('GEMINI_THINKING_BUDGET');
        if ($envBudget !== false && $envBudget !== '') {
            $this->thinkingBudget = (int)$envBudget;
        }
    }

    /**
     * @param array       $messages     [['role'=>'user','content'=>'...'], ...]
     * @param string|null $systemPrompt
     * @return array{response:array,latency_ms:int}
     */
    public function chat(array $messages, ?string $systemPrompt = null): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY is not set');
        }

        $contents = [];
        foreach ($messages as $m) {
            $contents[] = [
                'role'  => ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string)$m['content']]],
            ];
        }
        $generationConfig = ['maxOutputTokens' => $this->maxTokens];
        // thinkingConfig only exists on 2.5 / *-latest models; sending it to a
        // 2.0 model would 400. budget < 0 = let the model decide (omit config).
        if ($this->thinkingBudget >= 0
            && (strpos($this->model, '2.5') !== false || strpos($this->model, 'latest') !== false)) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => $this->thinkingBudget];
        }
        $payload = [
            'contents'         => $contents,
            'generationConfig' => $generationConfig,
        ];
        if ($systemPrompt) {
            $payload['system_instruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        // API key in a header (x-goog-api-key), not the URL — keeps it out of
        // access logs / proxies that record query strings.
        $url = $this->apiBase . '/' . rawurlencode($this->model) . ':generateContent';

        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-goog-api-key: ' . $this->apiKey],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5 — omitted.
        $latency = (int)((microtime(true) - $t0) * 1000);

        if ($raw === false || $code >= 400) {
            throw new \RuntimeException("Gemini API error ($code): " . ($err ?: $raw));
        }

        return [
            'response'   => json_decode($raw, true),
            'latency_ms' => $latency,
        ];
    }
}
