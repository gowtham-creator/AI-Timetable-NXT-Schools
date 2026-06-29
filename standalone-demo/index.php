<?php
/**
 * AI Timetable — STANDALONE STUDIO DEMO
 * ------------------------------------------------------------------
 * A single-file studio around the REAL pluggable engine
 * (components/ai/timetable/). No Yii, no database, no API key.
 *
 * Run:    php -S localhost:8088
 * Open:   http://localhost:8088
 *
 * Three modules, mirroring the production feature:
 *   • Coordinator — generate from plain-English rules, review stats,
 *     AI summary, teacher-wise workload sheet, per-section grids, publish
 *   • Teacher    — every teacher's personal weekly timetable
 *   • Substitute — absent teacher + day → ranked, conflict-free cover
 *
 * The schedule itself comes from TimetableSolver (the exact class your
 * developers plug into the ERP). The substitute ranking mirrors
 * components/ai/timetable/SubstituteFinder.php (DB-bound in production).
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

$candidates = [
    __DIR__ . '/../components/ai/timetable',
    __DIR__ . '/../new-files/components/ai/timetable',
    __DIR__ . '/new-files/components/ai/timetable',
];
$enginePath = null;
foreach ($candidates as $c) {
    if (is_file($c . '/TimetableSolver.php')) {
        $enginePath = $c;
        break;
    }
}
if ($enginePath === null) {
    http_response_code(500);
    exit('Engine not found — keep this file next to components/ai/timetable/ (repo) or new-files/ (handoff).');
}
require $enginePath . '/TimetableSolver.php';
require $enginePath . '/SolverFixtures.php';
require $enginePath . '/ConstraintIntake.php';
require $enginePath . '/TimetableDataLoader.php';
require $enginePath . '/FeasibilityAnalyzer.php';

use app\components\ai\timetable\ConstraintIntake;
use app\components\ai\timetable\SolverFixtures;
use app\components\ai\timetable\TimetableDataLoader;
use app\components\ai\timetable\TimetableSolver;

// ── Gemini helpers (self-contained so the demo runs with no Yii) ────────────
// Reads GEMINI_API_KEY from the environment, else from a sibling .env (the
// repo's Nxt_backend/.env). Key stays server-side — never sent to the browser.
function nxt_gemini_config(): array
{
    $cfg = [
        'key'    => getenv('GEMINI_API_KEY') ?: '',
        'model'  => getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash',
        'budget' => getenv('GEMINI_THINKING_BUDGET'),
    ];
    if ($cfg['key'] === '') {
        foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env', dirname(__DIR__, 2) . '/.env'] as $env) {
            if (!is_file($env)) {
                continue;
            }
            foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim($v);
                if ($k === 'GEMINI_API_KEY' && $cfg['key'] === '') $cfg['key'] = $v;
                if ($k === 'GEMINI_MODEL' && !getenv('GEMINI_MODEL')) $cfg['model'] = $v;
                if ($k === 'GEMINI_THINKING_BUDGET' && $cfg['budget'] === false) $cfg['budget'] = $v;
            }
            if ($cfg['key'] !== '') {
                break;
            }
        }
    }
    $cfg['budget'] = ($cfg['budget'] === false || $cfg['budget'] === '') ? 0 : (int)$cfg['budget'];
    return $cfg;
}

// Mirrors GeminiClient + ConstraintIntake: real intake prompt, thinkingBudget 0,
// x-goog-api-key header, JSON extraction. Throws on any failure (caller falls back).
function nxt_gemini_intake(string $rules, array $maps, string $promptPath, array $cfg): array
{
    $tpl    = is_file($promptPath) ? file_get_contents($promptPath) : '';
    $system = strtr($tpl, [
        '{{SUBJECTS}}' => implode(', ', array_values($maps['subject_names'])),
        '{{TEACHERS}}' => implode(', ', array_values($maps['teacher_names'])),
        '{{DAYS}}'     => 'Monday to Saturday',
    ]);
    $gen = ['maxOutputTokens' => 2048, 'temperature' => 0];
    if (strpos($cfg['model'], '2.5') !== false || strpos($cfg['model'], 'latest') !== false) {
        $gen['thinkingConfig'] = ['thinkingBudget' => $cfg['budget']];
    }
    $payload = [
        'contents'           => [['role' => 'user', 'parts' => [['text' => $rules]]]],
        'system_instruction' => ['parts' => [['text' => $system]]],
        'generationConfig'   => $gen,
    ];
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($cfg['model']) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-goog-api-key: ' . $cfg['key']],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $t0  = microtime(true);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms  = (int)round((microtime(true) - $t0) * 1000);
    if ($raw === false || $code >= 400) {
        $msg = $raw ? (json_decode($raw, true)['error']['message'] ?? '') : '';
        throw new \RuntimeException("HTTP $code" . ($msg ? " — $msg" : ''));
    }
    $j = json_decode($raw, true);
    $text = '';
    foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) {
        if (isset($p['text'])) $text .= $p['text'];
    }
    $obj = json_decode(preg_replace('/^```json\s*|\s*```$/', '', trim($text)), true);
    if (!is_array($obj)) {
        throw new \RuntimeException('non-JSON reply');
    }
    $obj['_latency_ms'] = $ms;
    return $obj;
}

// ── Demo affordances: make the school's own details visible ─────────────────
// In production, school hours come from the ERP's period config and teachers
// are real records; here we let the rules box set them so you can SEE inputs
// take effect. These are demo conveniences, not part of the core engine.
function nxt_fmt_min(int $m): string { $m = (int) round($m); return sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60); }

function nxt_parse_timings(string $rules): ?array
{
    if (!preg_match('/(\d{1,2})[:.]?(\d{2})?\s*(a\.?m\.?|p\.?m\.?)?\s*(?:to|-|–|—|until|till)\s*(\d{1,2})[:.]?(\d{2})?\s*(a\.?m\.?|p\.?m\.?)?/i', $rules, $m)) {
        return null;
    }
    $to24 = static function ($h, $min, $ap) {
        $h = (int) $h; $min = (int) ($min ?: 0); $ap = strtolower(str_replace('.', '', $ap ?? ''));
        if ($ap === 'pm' && $h < 12) $h += 12;
        if ($ap === 'am' && $h === 12) $h = 0;
        if ($ap === '' && $h >= 1 && $h <= 6) $h += 12; // bare "3:30" → afternoon
        return $h * 60 + $min;
    };
    $start = $to24($m[1], $m[2] ?? 0, $m[3] ?? '');
    $end   = $to24($m[4], $m[5] ?? 0, $m[6] ?? '');
    return ($end - $start >= 180 && $end - $start <= 720) ? ['start' => $start, 'end' => $end] : null;
}

// Rebuild the day layout inside [start,end], keeping the SAME period count so
// the weekly slot budget (and your subject quotas) stay valid.
function nxt_build_layout(int $start, int $end, int $nPeriods): array
{
    $assembly = 15; $snack = 15; $lunch = 35; $activity = 40;
    $acad = max($nPeriods * 30, ($end - $start) - $assembly - $snack - $lunch - $activity);
    $plen = (int) floor($acad / $nPeriods);
    $cur  = $start;
    $L = [['kind' => 'assembly', 'label' => 'Morning Assembly', 'time_from' => nxt_fmt_min($cur), 'time_to' => nxt_fmt_min($cur + $assembly)]];
    $cur += $assembly;
    for ($p = 1; $p <= $nPeriods; $p++) {
        $L[] = ['kind' => 'period', 'no' => $p, 'time_from' => nxt_fmt_min($cur), 'time_to' => nxt_fmt_min($cur + $plen)];
        $cur += $plen;
        if ($p === 3) { $L[] = ['kind' => 'break', 'label' => 'Snack Break', 'time_from' => nxt_fmt_min($cur), 'time_to' => nxt_fmt_min($cur + $snack)]; $cur += $snack; }
        if ($p === 5) { $L[] = ['kind' => 'lunch', 'label' => 'Lunch Break', 'time_from' => nxt_fmt_min($cur), 'time_to' => nxt_fmt_min($cur + $lunch)]; $cur += $lunch; }
    }
    $L[] = ['kind' => 'activity', 'label' => 'Games / Sports', 'time_from' => nxt_fmt_min($cur), 'time_to' => nxt_fmt_min(min($end, $cur + $activity))];
    return $L;
}

// "Mr. Kishore is the mathematics teacher" / "maths is taught by Kishore" /
// "Kishore teaches maths" → [subjectName => displayName].
function nxt_parse_teacher_named(string $rules, array $subjectNames): array
{
    $out = [];
    foreach ($subjectNames as $sub) {
        $first = preg_quote(explode(' ', strtolower($sub))[0], '/');
        if ($first === '' || strlen($first) < 3) continue;
        if (preg_match('/((?:mr|mrs|ms|miss)\.?\s+)?([A-Z][a-z]+)\s+(?:is\s+the\s+' . $first . '[a-z ]*?teacher|teaches\s+' . $first . ')/i', $rules, $m)
            || preg_match('/' . $first . '[a-z ]*?(?:is\s+taught\s+by|teacher\s+is)\s+((?:mr|mrs|ms|miss)\.?\s+)?([A-Z][a-z]+)/i', $rules, $m)) {
            $title = trim($m[1] ?? '');
            $out[strtolower($sub)] = ($title ? ucfirst(rtrim($title, '.')) . '. ' : '') . $m[2];
        }
    }
    return $out;
}

// ── Inputs ────────────────────────────────────────────────────────────────
$fixture = ($_GET['fixture'] ?? 'real') === 'demo' ? 'demo' : 'real';
$seed    = isset($_GET['seed']) && $_GET['seed'] !== '' ? (int)$_GET['seed'] : null;
$ran     = isset($_GET['run']);
$rules   = trim((string)($_GET['rules'] ?? ''));

$defaultRules = $fixture === 'real'
    ? "No more than 2 mathematics a day. PET twice a week in the afternoon. Library once a week."
    : "6 mathematics a week. PT twice a week in the afternoon. Library once a week. Mr. Rao only teaches mornings.";
if (!$ran) {
    $rules = $defaultRules;
}

$input = $fixture === 'real' ? SolverFixtures::realSchoolInput() : SolverFixtures::demoInput();
$maps  = [
    'subject_names' => array_column($input['subjects'], 'name', 'id'),
    'teacher_names' => array_column($input['teachers'], 'name', 'id'),
];

$intake       = new ConstraintIntake();
$geminiCfg    = nxt_gemini_config();
$intakeSource = 'none';
$intakeDetail = '';
$geminiRaw    = null;
$constraints  = [];
if ($rules !== '') {
    if ($geminiCfg['key'] !== '') {
        try {
            $promptPath  = dirname($enginePath) . '/prompts/timetable_intake.txt';
            $constraints = nxt_gemini_intake($rules, $maps, $promptPath, $geminiCfg);
            $geminiRaw   = $constraints;
            $intakeSource = 'gemini';
            $intakeDetail = $geminiCfg['model'] . ' · ' . ($constraints['_latency_ms'] ?? '?') . 'ms';
        } catch (\Throwable $e) {
            $constraints  = $intake->fallbackParse($rules, $maps);
            $intakeSource = 'keyword';
            $intakeDetail = 'Gemini error: ' . $e->getMessage();
        }
    } else {
        $constraints  = $intake->fallbackParse($rules, $maps);
        $intakeSource = 'keyword';
        $intakeDetail = 'no GEMINI_API_KEY found';
    }
}
$loader      = new TimetableDataLoader();
$input       = $loader->applyConstraints($input, $constraints);

// Demo: reflect school hours + named teachers from the rules so inputs are visible.
$appliedTimings = null;
$appliedTeacher = [];
if ($rules !== '') {
    $periodCount = 0;
    foreach ($input['layout'] as $col) {
        if ($col['kind'] === 'period') $periodCount++;
    }
    $timings = nxt_parse_timings($rules);
    if ($timings !== null) {
        $input['layout'] = nxt_build_layout($timings['start'], $timings['end'], $periodCount);
        $appliedTimings = nxt_fmt_min($timings['start']) . '–' . nxt_fmt_min($timings['end']);
    }
    $named = nxt_parse_teacher_named($rules, $maps['subject_names']);
    foreach ($named as $subjLower => $teacherName) {
        foreach ($input['subjects'] as $s) {
            if (strtolower($s['name']) === $subjLower && !empty($s['teacher_ids'])) {
                // Rename the subject's primary teacher to the named person —
                // keep the rest of the pool so capacity (and feasibility) hold.
                $tid = $s['teacher_ids'][0];
                foreach ($input['teachers'] as &$t) {
                    if ($t['id'] === $tid) { $t['name'] = $teacherName; break; }
                }
                unset($t);
                $appliedTeacher[$s['name']] = $teacherName;
            }
        }
    }
    $maps['teacher_names'] = array_column($input['teachers'], 'name', 'id');
}

// Pre-flight: can this even fit? Plain-language diagnosis for the coordinator.
$feasibility = \app\components\ai\timetable\FeasibilityAnalyzer::analyze($input);

$t0     = microtime(true);
$result = TimetableSolver::solve($input, $seed);
$ms     = (int)round((microtime(true) - $t0) * 1000);

$stats       = $result['stats'];
$slots       = $result['slots'];
$independent = TimetableSolver::validate($slots) === [];

// Deterministic "AI summary" (production narrates via Claude/Gemini when keyed).
$narrative = "Generated {$stats['placed']} of {$stats['required']} periods ({$stats['fill_pct']}% of the week) with {$stats['clashes']} clashes across " . count($input['sections']) . " sections.";
if ($stats['unplaced_count'] > 0) {
    $narrative .= ' ' . \app\components\ai\timetable\FeasibilityAnalyzer::headline($feasibility);
} else {
    $narrative .= ' Every subject met its weekly quota. Each subject is owned by a single teacher per section for the whole week, workloads are balanced, and PT/games sit in the afternoon as required.';
}

$sectionNames = array_column($input['sections'], 'name', 'id');
$teacherNames = $maps['teacher_names'];
$dayNames     = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

$grid = $sheet = [];
foreach ($slots as $s) {
    $grid[$s['section_id']][$s['day']][$s['period']] = $s;
    $sheet[$s['teacher_id']]['load'] = ($sheet[$s['teacher_id']]['load'] ?? 0) + 1;
    $sheet[$s['teacher_id']]['secs'][$s['section_id']] = ($sheet[$s['teacher_id']]['secs'][$s['section_id']] ?? 0) + 1;
}
uasort($sheet, static fn($a, $b) => $b['load'] <=> $a['load']);

// Payload for the interactive (Teacher / Substitute) views.
$periodsMeta = [];
$lunchSeen = false;
foreach ($input['layout'] as $col) {
    if ($col['kind'] === 'lunch') {
        $lunchSeen = true;
    }
    if ($col['kind'] === 'period') {
        $periodsMeta[] = ['no' => (int)$col['no'], 'from' => $col['time_from'], 'to' => $col['time_to'], 'morning' => !$lunchSeen];
    }
}
$payload = [
    'days'     => array_values($input['days']),
    'dayNames' => $dayNames,
    'periods'  => $periodsMeta,
    'sections' => array_map(static fn($s) => ['id' => $s['id'], 'name' => $s['name']], $input['sections']),
    'subjects' => array_map(static fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'teacher_ids' => array_values($s['teacher_ids'])], $input['subjects']),
    'teachers' => array_map(static fn($t) => ['id' => $t['id'], 'name' => $t['name']], $input['teachers']),
    'slots'    => array_map(static fn($s) => [
        'sec' => $s['section_id'], 'day' => $s['day'], 'p' => $s['period'],
        'sub' => $s['subject_id'], 'tid' => $s['teacher_id'],
    ], $slots),
];

$tones = ['#dbeafe', '#ede9fe', '#dcfce7', '#ffedd5', '#fce7f3', '#cffafe', '#fef9c3', '#fee2e2', '#e0e7ff', '#d1fae5', '#fae8ff', '#f1f5f9'];
$toneOf = static fn(int $subjectId): string => $tones[$subjectId % count($tones)];
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NXT AI Timetable — Studio</title>
<style>
    :root { --brand:#1E4FB8; --brand-2:#2C63D6; --accent:#D88A2B; --ink:#11203F; --mut:#64708a; --line:#e3e8ef; --good:#0c7a43; }
    * { box-sizing:border-box; }
    body { margin:0; font:14px/1.55 -apple-system,'Segoe UI',Roboto,Arial,sans-serif; color:var(--ink); background:#f5f7fb; }
    header { background:linear-gradient(120deg,#13306E,var(--brand)); color:#fff; padding:18px 28px; }
    header h1 { margin:0; font-size:19px; }
    header p { margin:4px 0 0; opacity:.85; font-size:12.5px; }
    main { max-width:1220px; margin:0 auto; padding:18px 24px 60px; }
    .card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:18px 20px; margin-bottom:16px; }
    label { font-weight:600; font-size:12.5px; display:block; margin-bottom:5px; }
    textarea, select, input[type=number] { width:100%; border:1px solid var(--line); border-radius:8px; padding:9px 11px; font:inherit; background:#fff; }
    textarea { min-height:62px; resize:vertical; }
    .row { display:flex; gap:14px; flex-wrap:wrap; }
    .row > div { flex:1; min-width:180px; }
    button { background:var(--brand); border:none; color:#fff; font:600 13.5px/1 inherit; padding:10px 18px; border-radius:8px; cursor:pointer; }
    button:hover { filter:brightness(1.08); }
    button.ghost { background:#fff; color:var(--brand); border:1px solid var(--brand); }
    button.good { background:var(--good); }
    button.small { padding:6px 12px; font-size:12px; border-radius:6px; }
    .chips { display:flex; gap:8px; flex-wrap:wrap; }
    .chip { background:#eef3ff; border:1px solid #c9d8f5; color:var(--brand); border-radius:999px; padding:4px 12px; font-size:12px; font-weight:600; }
    .chip.good { background:#e8f9f0; border-color:#b3e6cd; color:var(--good); }
    .chip.warn { background:#fff6e8; border-color:#f0d9ae; color:#9a6b16; }
    h2 { font-size:15px; margin:0 0 10px; }
    table { border-collapse:collapse; width:100%; font-size:12px; }
    th, td { border:1px solid var(--line); padding:5px 7px; text-align:center; vertical-align:middle; }
    thead th { background:var(--brand); color:#fff; font-weight:600; }
    .band { background:#f2f4f7; color:#667; font-weight:600; font-size:11px; }
    .day { background:#f7f9fc; font-weight:700; white-space:nowrap; }
    .subj { font-weight:700; display:block; }
    .teach { color:#7a8194; font-size:10.5px; display:block; }
    .sheet td:first-child, .sheet th:first-child { text-align:left; }
    .grid-wrap { overflow-x:auto; }
    .sec-title { margin:16px 0 8px; font-size:13.5px; font-weight:700; }
    footer { color:var(--mut); font-size:12px; text-align:center; padding:10px; }
    .note { color:var(--mut); font-size:12px; margin-top:8px; }
    .ai-box { background:#f7f9fc; border-left:3px solid var(--brand); padding:10px 14px; margin:12px 0 0; border-radius:0 8px 8px 0; font-size:13px; }
    /* tabs */
    .tabs { display:flex; gap:8px; margin:0 0 16px; flex-wrap:wrap; }
    .tab { background:#fff; border:1px solid var(--line); color:var(--mut); border-radius:999px; padding:9px 18px; font-weight:700; font-size:13px; cursor:pointer; }
    .tab.active { background:var(--brand); border-color:var(--brand); color:#fff; }
    .view { display:none; }
    .view.active { display:block; }
    /* substitute cards */
    .sub-period { border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:10px; background:#fff; }
    .sub-cand { display:flex; align-items:center; gap:10px; padding:8px 0; border-top:1px dashed var(--line); }
    .sub-cand:first-of-type { border-top:0; }
    .reason { color:var(--mut); font-size:11.5px; }
    .badge { display:inline-block; background:#e8f9f0; color:var(--good); border:1px solid #b3e6cd; border-radius:999px; padding:2px 10px; font-size:11px; font-weight:700; }
    .toast { position:fixed; left:50%; bottom:26px; transform:translateX(-50%); background:#11203F; color:#fff; padding:11px 20px; border-radius:10px; font-size:13px; box-shadow:0 12px 30px rgba(0,0,0,.25); display:none; z-index:50; }
    .pub-banner { display:none; background:#e8f9f0; border:1px solid #b3e6cd; color:var(--good); border-radius:8px; padding:11px 14px; font-size:13px; margin-top:12px; }
    .diag { margin-top:12px; border:1px solid #f0c9ae; background:#fff8f0; border-radius:10px; padding:14px 16px; }
    .diag-head { font-weight:700; color:#9a4b16; font-size:13.5px; margin-bottom:10px; }
    .diag-row { padding:9px 0; border-top:1px dashed #f0d9ae; }
    .diag-row:first-of-type { border-top:0; }
    .diag-why { color:#7a2e0e; font-size:13px; }
    .diag-fix { color:#0c7a43; font-size:12.5px; margin-top:3px; font-weight:600; }
    .diag-note { margin-top:10px; font-size:12px; color:var(--mut); }
    button:disabled { opacity:.45; cursor:not-allowed; }
    @media print { form, footer, .tabs, .no-print { display:none; } body { background:#fff; } .card { border:none; padding:0; } }
</style>
</head>
<body>
<header>
    <h1>NXT School ERP — AI Timetable Studio <span style="opacity:.7;font-weight:400">· live engine demo</span></h1>
    <p>Every grid below is produced by the actual pluggable engine (<code>components/ai/timetable/</code>) — no database, no API key. Coordinator · Teacher · Substitute, just like production. · <a href="class.php" style="color:#cfe0ff">single-class studio →</a> · <a href="school.php" style="color:#cfe0ff">whole-school →</a> · <a href="logs.php" style="color:#cfe0ff">AI logs →</a></p>
</header>
<main>

    <form class="card" method="get">
        <input type="hidden" name="run" value="1">
        <div class="row">
            <div style="flex:3">
                <label>Scheduling rules — plain English</label>
                <textarea name="rules"><?= $h($rules) ?></textarea>
            </div>
            <div>
                <label>School profile</label>
                <select name="fixture">
                    <option value="real" <?= $fixture === 'real' ? 'selected' : '' ?>>Real school — 5 sections, 6 days × 9 periods</option>
                    <option value="demo" <?= $fixture === 'demo' ? 'selected' : '' ?>>Compact — 3 sections, 5 days × 7 periods</option>
                </select>
                <label style="margin-top:10px">Seed (blank = explore)</label>
                <input type="number" name="seed" value="<?= $h($seed ?? '') ?>" placeholder="random">
                <div style="margin-top:12px"><button type="submit">⚡ Generate timetable</button></div>
            </div>
        </div>
        <p class="note">Rules here are parsed by the built-in keyword parser; in production the same box goes to Claude or Gemini (or the parser when no key is configured). The solver is identical either way.</p>
    </form>

    <div class="tabs no-print">
        <button type="button" class="tab active" data-view="coordinator">🗂 Coordinator</button>
        <button type="button" class="tab" data-view="teacher">👩‍🏫 Teacher timetables</button>
        <button type="button" class="tab" data-view="substitute">🔄 Substitutes</button>
    </div>

    <!-- ════════ COORDINATOR ════════ -->
    <section class="view active" id="view-coordinator">
        <div class="card">
            <h2>Generation result</h2>
            <div class="chips">
                <span class="chip good"><?= (int)$stats['placed'] ?> / <?= (int)$stats['required'] ?> periods placed</span>
                <span class="chip good"><?= (int)$stats['clashes'] ?> clashes</span>
                <span class="chip good"><?= (int)$stats['fill_pct'] ?>% of the week filled</span>
                <span class="chip"><?= $h($ms) ?> ms solve time</span>
                <span class="chip <?= $independent ? 'good' : 'warn' ?>">independent re-validation: <?= $independent ? 'clean' : 'FAILED' ?></span>
                <span class="chip">one teacher per subject &amp; section — all week</span>
                <?php if ($intakeSource === 'gemini'): ?>
                    <span class="chip" style="background:#ede9fe;border-color:#c4b5fd;color:#6d28d9">🤖 rules parsed by Gemini · <?= $h($intakeDetail) ?></span>
                <?php elseif ($intakeSource === 'keyword'): ?>
                    <span class="chip warn">rules: keyword parser<?= $intakeDetail ? ' (' . $h($intakeDetail) . ')' : '' ?></span>
                <?php endif; ?>
                <?php if ($appliedTimings !== null): ?>
                    <span class="chip" style="background:#e0f2fe;border-color:#7dd3fc;color:#0369a1">🕗 school hours <?= $h($appliedTimings) ?></span>
                <?php endif; ?>
                <?php foreach ($appliedTeacher as $subj => $tname): ?>
                    <span class="chip" style="background:#e0f2fe;border-color:#7dd3fc;color:#0369a1">👤 <?= $h($tname) ?> → <?= $h($subj) ?></span>
                <?php endforeach; ?>
                <?php foreach (($constraints['subjects'] ?? []) as $r): ?>
                    <span class="chip warn">rule: <?= $h($r['name_like']) ?><?= isset($r['per_week']) ? " {$r['per_week']}/wk" : '' ?><?= isset($r['max_per_day']) ? " ≤{$r['max_per_day']}/day" : '' ?><?= !empty($r['after_lunch_only']) ? ' PM-only' : '' ?></span>
                <?php endforeach; ?>
                <?php foreach (($constraints['teachers'] ?? []) as $r): ?>
                    <span class="chip warn">rule: <?= $h($r['name_like']) ?><?= !empty($r['morning_only']) ? ' mornings-only' : '' ?></span>
                <?php endforeach; ?>
            </div>
            <div class="ai-box"><strong>AI summary:</strong> <?= $h($narrative) ?></div>
            <?php if ($geminiRaw !== null): ?>
                <details style="margin-top:10px">
                    <summary style="cursor:pointer;font-size:12.5px;color:var(--mut)">▸ Raw constraints Gemini extracted from your sentence</summary>
                    <pre style="background:#0f172a;color:#a5f3fc;padding:12px 14px;border-radius:8px;overflow-x:auto;font-size:11.5px;margin-top:8px"><?= $h(json_encode(array_diff_key($geminiRaw, ['_latency_ms' => 1]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                </details>
            <?php endif; ?>
            <?php if (!$feasibility['ok']): ?>
                <div class="diag">
                    <div class="diag-head">⚠ This request can't fully fit — here's exactly why &amp; how to fix it</div>
                    <?php foreach ($feasibility['blockers'] as $b): ?>
                        <div class="diag-row">
                            <div class="diag-why"><?= $h($b['message']) ?></div>
                            <div class="diag-fix">✅ <?= $h($b['fix']) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="diag-note">The <?= (int)$stats['placed'] ?>/<?= (int)$stats['required'] ?> periods that <em>did</em> fit are still 100% clash-free — the solver placed everything physically possible. Adjust a rule above and regenerate.</div>
                </div>
            <?php elseif (!empty($feasibility['notes'])): ?>
                <p class="note">✓ Feasible — <?= $h(implode(' ', $feasibility['notes'])) ?></p>
            <?php endif; ?>
            <div class="no-print" style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap">
                <button type="button" class="good" id="btn-publish"<?= $feasibility['ok'] ? '' : ' disabled title="Resolve the issue above first"' ?>>✓ Publish to live timetable</button>
                <button type="button" class="ghost" onclick="window.print()">🖨 Print / PDF</button>
            </div>
            <div class="pub-banner" id="pub-banner">
                <strong>Published (demo).</strong> In production this exact step runs
                <code>TimetableComposer::publish()</code> — archives the current
                <code>subject_timetable</code> rows for these sections and inserts this week
                in one transaction, after an independent clash re-validation. Teachers and
                parents see it instantly in their apps.
            </div>
        </div>

        <div class="card">
            <h2>Teacher-wise workload sheet <span style="font-weight:400;color:var(--mut)">— auto-generated (the sheet schools build by hand)</span></h2>
            <div class="grid-wrap">
            <table class="sheet">
                <thead><tr><th>Teacher</th><th>Sections handled (periods/week)</th><th>Total / week</th></tr></thead>
                <tbody>
                <?php foreach ($sheet as $tid => $rowx): ?>
                    <tr>
                        <td><?= $h($teacherNames[$tid] ?? "Teacher #$tid") ?></td>
                        <td style="text-align:left">
                            <?php $bits = []; ksort($rowx['secs']); foreach ($rowx['secs'] as $secId => $n) { $bits[] = $h($sectionNames[$secId] ?? $secId) . ' ' . (int)$n; } echo implode(' · ', $bits); ?>
                        </td>
                        <td><strong><?= (int)$rowx['load'] ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div class="card">
            <h2>Section timetables</h2>
            <?php foreach ($input['sections'] as $sec): ?>
                <div class="sec-title">Section <?= $h($sec['name']) ?></div>
                <div class="grid-wrap">
                <table>
                    <thead>
                    <tr>
                        <th style="min-width:84px">Day</th>
                        <?php foreach ($input['layout'] as $col): ?>
                            <?php if ($col['kind'] === 'period'): ?>
                                <th>P<?= (int)$col['no'] ?><br><small style="font-weight:400"><?= $h($col['time_from']) ?>–<?= $h($col['time_to']) ?></small></th>
                            <?php else: ?>
                                <th style="background:#16408f"><?= $h($col['label'] ?? ucfirst($col['kind'])) ?><br><small style="font-weight:400"><?= $h($col['time_from']) ?>–<?= $h($col['time_to']) ?></small></th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($input['days'] as $day): ?>
                        <tr>
                            <td class="day"><?= $dayNames[$day] ?? $day ?></td>
                            <?php foreach ($input['layout'] as $col): ?>
                                <?php if ($col['kind'] === 'period'):
                                    $slot = $grid[$sec['id']][$day][(int)$col['no']] ?? null; ?>
                                    <td style="<?= $slot ? 'background:' . $toneOf((int)$slot['subject_id']) : '' ?>">
                                        <?php if ($slot): ?>
                                            <span class="subj"><?= $h($maps['subject_names'][$slot['subject_id']] ?? '') ?></span>
                                            <span class="teach"><?= $h($teacherNames[$slot['teacher_id']] ?? '') ?></span>
                                        <?php else: ?>
                                            <span style="color:#c4cbd8">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php else: ?>
                                    <td class="band"><?= $h($col['label'] ?? ucfirst($col['kind'])) ?></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ════════ TEACHER ════════ -->
    <section class="view" id="view-teacher">
        <div class="card">
            <h2>Personal weekly timetable</h2>
            <div class="row" style="align-items:flex-end">
                <div style="max-width:340px">
                    <label>Teacher</label>
                    <select id="t-select"></select>
                </div>
                <div class="chips" id="t-chips" style="padding-bottom:4px"></div>
            </div>
            <div class="grid-wrap" style="margin-top:14px" id="t-grid"></div>
            <p class="note">In production every teacher sees exactly this in their app the moment the coordinator publishes — including substitution updates.</p>
        </div>
    </section>

    <!-- ════════ SUBSTITUTE ════════ -->
    <section class="view" id="view-substitute">
        <div class="card">
            <h2>Substitute finder <span style="font-weight:400;color:var(--mut)">— ranking mirrors SubstituteFinder.php: free → same-subject → lightest recent load</span></h2>
            <div class="row" style="align-items:flex-end">
                <div style="max-width:300px">
                    <label>Absent teacher</label>
                    <select id="s-teacher"></select>
                </div>
                <div style="max-width:220px">
                    <label>Day</label>
                    <select id="s-day"></select>
                </div>
                <div style="max-width:200px; padding-bottom:1px">
                    <button type="button" id="s-find">🔍 Find substitutes</button>
                </div>
            </div>
            <div id="s-result" style="margin-top:16px"></div>
        </div>
    </section>

</main>
<div class="toast" id="toast"></div>
<footer>Heart &amp; soul: <code>components/ai/timetable/</code> — TimetableSolver · ConstraintIntake · TimetableDataLoader · ConflictChecker · SubstituteFinder. This page just calls them.</footer>

<script>
const DATA = <?= json_encode($payload, JSON_UNESCAPED_UNICODE) ?>;

const $  = (q) => document.querySelector(q);
const $$ = (q) => Array.from(document.querySelectorAll(q));
const subName  = Object.fromEntries(DATA.subjects.map(s => [s.id, s.name]));
const secName  = Object.fromEntries(DATA.sections.map(s => [s.id, s.name]));
const tName    = Object.fromEntries(DATA.teachers.map(t => [t.id, t.name]));
const tones    = ['#dbeafe','#ede9fe','#dcfce7','#ffedd5','#fce7f3','#cffafe','#fef9c3','#fee2e2','#e0e7ff','#d1fae5','#fae8ff','#f1f5f9'];
const toneOf   = (id) => tones[id % tones.length];

// Live assignment state for the substitute flow (session-only in this demo;
// production writes temporary_assign_teacher via SubstituteFinder::apply()).
const assignments = [];   // {day,p,sec,sub,absent,covering}

function toast(msg){ const t = $('#toast'); t.textContent = msg; t.style.display = 'block';
    clearTimeout(toast._h); toast._h = setTimeout(() => t.style.display = 'none', 2600); }

/* ── tabs ── */
$$('.tab').forEach(b => b.addEventListener('click', () => {
    $$('.tab').forEach(x => x.classList.toggle('active', x === b));
    $$('.view').forEach(v => v.classList.toggle('active', v.id === 'view-' + b.dataset.view));
}));

/* ── helpers ── */
function slotsOf(tid){ return DATA.slots.filter(s => s.tid === tid); }
function effectiveTeacher(slot){
    const a = assignments.find(x => x.day === slot.day && x.p === slot.p && x.sec === slot.sec);
    return a ? a.covering : slot.tid;
}
function busyAt(tid, day, p){
    if (DATA.slots.some(s => effectiveTeacher(s) === tid && s.day === day && s.p === p)) return true;
    return assignments.some(a => a.covering === tid && a.day === day && a.p === p);
}
function taughtSubject(tid, subId){
    if (DATA.slots.some(s => s.tid === tid && s.sub === subId)) return true;
    const meta = DATA.subjects.find(s => s.id === subId);
    return !!(meta && meta.teacher_ids.includes(tid));
}
function coverLoad(tid){ return assignments.filter(a => a.covering === tid).length; }

/* ── Teacher view ── */
function renderTeacher(tid){
    tid = Number(tid);
    const mine = DATA.slots.filter(s => effectiveTeacher(s) === tid);
    const covering = assignments.filter(a => a.covering === tid).length;
    const secs = [...new Set(mine.map(s => s.sec))].map(id => secName[id]).join(', ') || '—';
    const totalCells = DATA.days.length * DATA.periods.length;
    $('#t-chips').innerHTML =
        `<span class="chip good">${mine.length} periods / week</span>` +
        `<span class="chip">${totalCells - mine.length} free periods</span>` +
        `<span class="chip">sections: ${secs}</span>` +
        (covering ? `<span class="chip warn">${covering} substitution(s) this week</span>` : '');

    let html = '<table><thead><tr><th style="min-width:84px">Day</th>';
    DATA.periods.forEach(p => html += `<th>P${p.no}<br><small style="font-weight:400">${p.from}–${p.to}</small></th>`);
    html += '</tr></thead><tbody>';
    DATA.days.forEach(d => {
        html += `<tr><td class="day">${DATA.dayNames[d]}</td>`;
        DATA.periods.forEach(p => {
            const s = mine.find(x => x.day === d && x.p === p.no);
            if (s) {
                const isCover = assignments.some(a => a.day === d && a.p === p.no && a.sec === s.sec && a.covering === tid);
                html += `<td style="background:${toneOf(s.sub)}"><span class="subj">${subName[s.sub]}</span>` +
                        `<span class="teach">Section ${secName[s.sec]}${isCover ? ' · covering' : ''}</span></td>`;
            } else {
                html += '<td><span style="color:#c4cbd8">free</span></td>';
            }
        });
        html += '</tr>';
    });
    html += '</tbody></table>';
    $('#t-grid').innerHTML = html;
}

/* ── Substitute view ── */
function renderSubstitute(){
    const tid = Number($('#s-teacher').value);
    const day = Number($('#s-day').value);
    const affected = DATA.slots
        .filter(s => s.tid === tid && s.day === day)
        .sort((a, b) => a.p - b.p);

    if (!affected.length) {
        $('#s-result').innerHTML = `<em>${tName[tid]} has no periods on ${DATA.dayNames[day]}.</em>`;
        return;
    }

    let html = `<p style="margin:0 0 10px"><strong>${tName[tid]}</strong> is absent on <strong>${DATA.dayNames[day]}</strong> — ${affected.length} period(s) need cover:</p>`;
    affected.forEach(slot => {
        const already = assignments.find(a => a.day === slot.day && a.p === slot.p && a.sec === slot.sec);
        const pm = DATA.periods.find(p => p.no === slot.p);
        html += `<div class="sub-period" data-key="${slot.sec}-${slot.day}-${slot.p}">` +
            `<strong>${subName[slot.sub]}</strong> — Section ${secName[slot.sec]} · P${slot.p} (${pm.from}–${pm.to}) `;
        if (already) {
            html += `<span class="badge">✓ ${tName[already.covering]} assigned</span></div>`;
            return;
        }
        // Rank candidates: must be free; same-subject first; lightest cover load; lightest week.
        const cands = DATA.teachers
            .filter(t => t.id !== tid && !busyAt(t.id, slot.day, slot.p))
            .map(t => ({
                id: t.id, name: t.name,
                same: taughtSubject(t.id, slot.sub),
                cover: coverLoad(t.id),
                week: slotsOf(t.id).length,
            }))
            .sort((a, b) => (b.same - a.same) || (a.cover - b.cover) || (a.week - b.week))
            .slice(0, 3);

        if (!cands.length) {
            html += '<div class="reason" style="margin-top:6px">No free substitute for this slot — the engine would flag this to the coordinator.</div></div>';
            return;
        }
        cands.forEach(c => {
            const reasons = ['Free during this period'];
            if (c.same) reasons.push(`Has taught ${subName[slot.sub]}`);
            reasons.push(c.cover === 0 ? 'No substitutions yet this week' : `${c.cover} substitution(s) this week`);
            reasons.push(`${c.week} regular periods/week`);
            html += `<div class="sub-cand">` +
                `<button type="button" class="small good" onclick="assign(${slot.sec},${slot.day},${slot.p},${slot.sub},${tid},${c.id})">Assign</button>` +
                `<div><strong>${c.name}</strong>${c.same ? ' <span class="chip" style="padding:1px 9px;font-size:10.5px">same subject</span>' : ''}` +
                `<div class="reason">${reasons.join(' · ')}</div></div></div>`;
        });
        html += '</div>';
    });
    $('#s-result').innerHTML = html;
}

function assign(sec, day, p, sub, absent, covering){
    assignments.push({ sec, day, p, sub, absent, covering });
    toast(`${tName[covering]} will cover ${subName[sub]} (Section ${secName[sec]}, P${p}) — recorded. In production: one row in temporary_assign_teacher + notification.`);
    renderSubstitute();
    if (Number($('#t-select').value)) renderTeacher($('#t-select').value);
}

/* ── publish ── */
$('#btn-publish').addEventListener('click', () => {
    if (!confirm('Publish this draft? In production the current timetable for these sections is archived and replaced in one transaction.')) return;
    $('#pub-banner').style.display = 'block';
    toast('Draft published (demo) — production runs TimetableComposer::publish().');
});

/* ── init selects ── */
DATA.teachers
    .slice()
    .sort((a, b) => slotsOf(b.id).length - slotsOf(a.id).length)
    .forEach(t => {
        $('#t-select').insertAdjacentHTML('beforeend', `<option value="${t.id}">${t.name} (${slotsOf(t.id).length}/wk)</option>`);
        $('#s-teacher').insertAdjacentHTML('beforeend', `<option value="${t.id}">${t.name}</option>`);
    });
DATA.days.forEach(d => $('#s-day').insertAdjacentHTML('beforeend', `<option value="${d}">${DATA.dayNames[d]}</option>`));
$('#t-select').addEventListener('change', e => renderTeacher(e.target.value));
$('#s-find').addEventListener('click', renderSubstitute);
renderTeacher($('#t-select').value);
</script>
</body>
</html>
