<?php

namespace app\components\ai\timetable;

use app\components\ai\AuditLogger;
use app\components\ai\LlmRouter;
use app\components\ai\PiiRedactor;
use Yii;

/**
 * ConstraintIntake — turns a coordinator's plain-English rules into the
 * constraints JSON consumed by TimetableDataLoader::applyConstraints().
 *
 * Primary path: whichever LLM the school configured (LlmRouter — Claude via
 * ANTHROPIC_API_KEY, or Gemini via GEMINI_API_KEY), audited + PII-redacted.
 * Fallback path: deterministic keyword parser, so generation always works
 * even with NO API key configured. The result carries `_source` so the UI
 * can show which path produced it.
 */
class ConstraintIntake
{
    /**
     * @param string $rulesText plain-English rules from the coordinator
     * @param array  $maps      ['subject_names'=>[],'teacher_names'=>[]] for grounding
     * @param array  $context   ['user_id'=>..,'campus_id'=>..,'institute_id'=>..]
     */
    public function parse(string $rulesText, array $maps, array $context = []): array
    {
        $rulesText = trim($rulesText);
        if ($rulesText === '') {
            return ['_source' => 'none'];
        }

        $llm = LlmRouter::resolve();
        if ($llm !== null) {
            try {
                return $this->parseWithLlm($llm, $rulesText, $maps, $context);
            } catch (\Throwable $e) {
                Yii::warning('Timetable intake LLM failed, using fallback: ' . $e->getMessage(), 'ai');
            }
        }
        return $this->fallbackParse($rulesText, $maps);
    }

    /** @param array{client:object,kind:string,model:string} $llm */
    private function parseWithLlm(array $llm, string $rulesText, array $maps, array $context): array
    {
        $template = file_get_contents(__DIR__ . '/../prompts/timetable_intake.txt');
        $system = strtr($template, [
            '{{SUBJECTS}}' => implode(', ', array_values($maps['subject_names'] ?? [])),
            '{{TEACHERS}}' => implode(', ', array_values($maps['teacher_names'] ?? [])),
            '{{DAYS}}'     => 'Monday to Friday (Saturday optional)',
        ]);

        $clean = PiiRedactor::redact($rulesText);
        $messages = [['role' => 'user', 'content' => $clean]];

        $invId = AuditLogger::start(
            'timetable_intake',
            $context['user_id'] ?? null,
            $context['institute_id'] ?? null,
            $context['campus_id'] ?? null,
            ['rules' => $clean],
            $llm['kind'] . ':' . $llm['model']
        );

        $t0 = microtime(true);
        try {
            $out = LlmRouter::chat($llm, $messages, $system);
        } catch (\Throwable $e) {
            AuditLogger::finish($invId, ['error' => $e->getMessage()], null, null,
                (int)((microtime(true) - $t0) * 1000), 'error', $e->getMessage());
            throw $e;
        }

        AuditLogger::finish(
            $invId,
            ['text' => $out['text']],
            $out['tokens_in'],
            $out['tokens_out'],
            $out['latency_ms'],
            'success'
        );

        $constraints = $this->extractJson($out['text']);
        if ($constraints === null) {
            throw new \RuntimeException('Intake LLM did not return valid JSON');
        }
        $constraints = $this->sanitize($constraints);
        $constraints['_source'] = 'ai';
        $constraints['_provider'] = $llm['kind'];
        $constraints['_invocation_id'] = $invId;
        return $constraints;
    }

    /**
     * Deterministic keyword parser. Covers the most common rule phrasings so
     * the feature degrades gracefully without an API key.
     */
    public function fallbackParse(string $rulesText, array $maps): array
    {
        $text = strtolower($rulesText);
        $out  = ['subjects' => [], 'teachers' => [], '_source' => 'fallback'];

        // Saturday working?
        if (strpos($text, 'saturday') !== false && !preg_match('/no\s+(?:classes?\s+)?(?:on\s+)?saturday/', $text)) {
            $out['days'] = [1, 2, 3, 4, 5, 6];
        }

        $subjectNames = array_map('strval', array_values($maps['subject_names'] ?? []));
        $teacherNames = array_map('strval', array_values($maps['teacher_names'] ?? []));

        foreach ($subjectNames as $name) {
            $n = strtolower($name);
            $first = preg_quote(explode(' ', $n)[0], '/');
            if ($first === '') {
                continue;
            }
            $rule = [];

            // "6 periods of maths" / "6 maths (periods) a week" / "maths 6 per week".
            // Weekly-quota patterns require explicit week context so they never
            // swallow day-cap sentences like "no more than 2 maths a day".
            if (preg_match('/(\d{1,2})\s+periods?\s+of\s+' . $first . '/', $text, $m)
                || preg_match('/(\d{1,2})\s+' . $first . '\s*(?:periods?)?\s*(?:per|a|each)\s+week/', $text, $m)
                || preg_match('/' . $first . '[a-z ]{0,12}?(\d{1,2})\s*(?:periods?)?\s*(?:per|a|each)\s+week/', $text, $m)) {
                $rule['per_week'] = (int)$m[1];
            }
            // "twice a week" / "once a week" near the subject word
            if (preg_match('/' . $first . '[a-z &\/]{0,20}twice\s+a\s+week/', $text)) {
                $rule['per_week'] = 2;
            }
            if (preg_match('/' . $first . '[a-z &\/]{0,20}once\s+a\s+week/', $text)) {
                $rule['per_week'] = 1;
            }
            // "no more than 2 maths a day" / "max 2 maths per day"
            if (preg_match('/(?:no\s+more\s+than|max(?:imum)?)\s+(\d)\s+' . $first . '[a-z ]{0,12}(?:a|per)\s+day/', $text, $m)) {
                $rule['max_per_day'] = (int)$m[1];
            }
            // "PT in the afternoon / after lunch"
            if (preg_match('/' . $first . '[a-z &\/]{0,24}(?:after\s+lunch|afternoon|evening)/', $text)) {
                $rule['after_lunch_only'] = true;
            }

            if ($rule !== []) {
                $rule['name_like'] = explode(' ', $n)[0];
                $out['subjects'][] = $rule;
            }
        }

        foreach ($teacherNames as $name) {
            $n = strtolower(trim($name));
            // Match on the most distinctive token of the teacher's name.
            $tokens = array_filter(explode(' ', preg_replace('/[^a-z ]/', '', $n)), static fn($w) => strlen($w) > 2);
            $token = $tokens !== [] ? end($tokens) : '';
            if ($token === '') {
                continue;
            }
            $rule = [];
            if (preg_match('/' . preg_quote($token, '/') . '[a-z .]{0,30}(?:only\s+(?:teaches\s+)?(?:in\s+the\s+)?morning|morning\s+only)/', $text)) {
                $rule['morning_only'] = true;
            }
            if (preg_match('/' . preg_quote($token, '/') . '[a-z .]{0,30}max(?:imum)?\s+(\d)\s+periods?\s+(?:a|per)\s+day/', $text, $m)) {
                $rule['max_per_day'] = (int)$m[1];
            }
            if ($rule !== []) {
                $rule['name_like'] = $token;
                $out['teachers'][] = $rule;
            }
        }

        if ($out['subjects'] === []) {
            unset($out['subjects']);
        }
        if ($out['teachers'] === []) {
            unset($out['teachers']);
        }
        return $out;
    }

    /** Pull the first JSON object out of an LLM reply (tolerates fences/prose). */
    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Whitelist keys & clamp values so a misbehaving reply can't poison the solver. */
    private function sanitize(array $c): array
    {
        $out = [];
        if (isset($c['days']) && is_array($c['days'])) {
            // Accept ints (1-7) OR day names — the prompt asks for ints but
            // LLMs sometimes return "Monday". Map both to 1=Mon … 7=Sun.
            $names = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
                      'friday' => 5, 'saturday' => 6, 'sunday' => 7];
            $days = [];
            foreach ($c['days'] as $d) {
                $n = is_numeric($d) ? (int)$d : ($names[strtolower(trim((string)$d))] ?? 0);
                if ($n >= 1 && $n <= 7) {
                    $days[$n] = $n;
                }
            }
            ksort($days);
            $out['days'] = array_values($days);
        }
        foreach (['subjects' => ['per_week', 'max_per_day', 'after_lunch_only', 'teacher_ids'],
                  'teachers' => ['morning_only', 'max_per_day', 'max_per_week', 'unavailable']] as $group => $allowed) {
            if (!isset($c[$group]) || !is_array($c[$group])) {
                continue;
            }
            foreach ($c[$group] as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $cleanRule = [];
                if (isset($rule['name_like']) && is_string($rule['name_like'])) {
                    $cleanRule['name_like'] = mb_substr($rule['name_like'], 0, 40);
                }
                if (isset($rule['id'])) {
                    $cleanRule['id'] = (int)$rule['id'];
                }
                foreach ($allowed as $key) {
                    // Skip absent AND explicit-null fields. Gemini emits
                    // {"per_week": null} for unspecified values — without this
                    // guard (int)null = 0 would zero out a subject's quota.
                    if (!array_key_exists($key, $rule) || $rule[$key] === null) {
                        continue;
                    }
                    $cleanRule[$key] = match ($key) {
                        'after_lunch_only', 'morning_only' => (bool)$rule[$key],
                        'teacher_ids' => is_array($rule[$key]) ? array_map('intval', $rule[$key]) : [],
                        'unavailable' => is_array($rule[$key]) ? array_values(array_filter(array_map(
                            static fn($u) => isset($u['day'], $u['period'])
                                ? ['day' => (int)$u['day'], 'period' => (int)$u['period']] : null,
                            $rule[$key]
                        ))) : [],
                        default => (int)$rule[$key],
                    };
                }
                if ($cleanRule !== [] && (isset($cleanRule['name_like']) || isset($cleanRule['id']))) {
                    $out[$group][] = $cleanRule;
                }
            }
        }
        return $out;
    }
}
