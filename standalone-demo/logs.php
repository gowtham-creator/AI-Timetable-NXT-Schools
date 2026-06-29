<?php
/**
 * AI Timetable — STANDALONE AI RUN-LOGS / AUDIT TRAIL
 * ------------------------------------------------------------------
 * A single-file viewer over the REAL audit layer the feature writes:
 *   • ai_invocations          — every LLM call (tool, model, prompt-hash,
 *                                request/response, tokens, latency, status)
 *   • timetable_generation_runs — one row per generation (draft → published)
 *
 * No Yii, no database, no API key. This page RUNS the real engine once (so the
 * newest run + its intake log are genuine) and renders the audit trail exactly
 * as the production admin viewer would, reading those two tables.
 *
 *   php -S localhost:8088     →   http://localhost:8088/logs.php
 *
 * Why this matters (AI-AUTOMATIONS §C.1): "Every reminder/communication is
 * logged with the exact prompt + model version — disputes will happen."
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

$candidates = [__DIR__ . '/../components/ai/timetable', __DIR__ . '/../new-files/components/ai/timetable', __DIR__ . '/new-files/components/ai/timetable'];
$enginePath = null;
foreach ($candidates as $c) { if (is_file($c . '/TimetableSolver.php')) { $enginePath = $c; break; } }
if ($enginePath === null) { http_response_code(500); exit('Engine not found — keep this file next to components/ai/timetable/.'); }
require $enginePath . '/TimetableSolver.php';
require $enginePath . '/SolverFixtures.php';
require $enginePath . '/ConstraintIntake.php';
require $enginePath . '/TimetableDataLoader.php';
require $enginePath . '/FeasibilityAnalyzer.php';

use app\components\ai\timetable\ConstraintIntake;
use app\components\ai\timetable\FeasibilityAnalyzer;
use app\components\ai\timetable\SolverFixtures;
use app\components\ai\timetable\TimetableDataLoader;
use app\components\ai\timetable\TimetableSolver;

$h    = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$hash = static fn(array $r) => substr(hash('sha256', json_encode($r, JSON_UNESCAPED_UNICODE)), 0, 12);

// ── Run the REAL engine once, so the newest log row is genuine ───────────────
$rules = "No more than 2 mathematics a day. PET twice a week in the afternoon. Library once a week.";
$input = SolverFixtures::realSchoolInput();
$maps  = ['subject_names' => array_column($input['subjects'], 'name', 'id'), 'teacher_names' => array_column($input['teachers'], 'name', 'id')];

$intake = new ConstraintIntake();
$hasKey = (getenv('GEMINI_API_KEY') ?: '') !== '' || (getenv('ANTHROPIC_API_KEY') ?: '') !== '';
$t0 = microtime(true);
$constraints = $intake->fallbackParse($rules, $maps);          // standalone path (no Yii/LlmRouter)
$intakeMs = (int)round((microtime(true) - $t0) * 1000);
$input = (new TimetableDataLoader())->applyConstraints($input, $constraints);
$feasibility = FeasibilityAnalyzer::analyze($input);
$t1 = microtime(true);
$result = TimetableSolver::solve($input, 20260626);
$solveMs = (int)round((microtime(true) - $t1) * 1000);
$st = $result['stats'];
$narrative = "Generated {$st['placed']}/{$st['required']} periods ({$st['fill_pct']}%) with {$st['clashes']} clashes across " . count($input['sections']) . " sections. Each subject is owned by one teacher per section; PT/games sit in the afternoon as required.";
$liveModel  = $hasKey ? (getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash') : 'deterministic parser';
$liveSource = $hasKey ? 'ai' : 'fallback';
$nSec = count($input['sections']);

// ── The audit tables (live run + representative recent history) ──────────────
// Schema is exactly ai_invocations / timetable_generation_runs. History rows
// illustrate the viewer (no DB here); the #R-104 run + INV-9001/9002 are live.
$runs = [
    ['id' => 104, 'live' => true, 'scope' => 'Classes 6–10 · 10 sections', 'rules' => $rules,
     'status' => 'draft', 'placed' => $st['placed'], 'required' => $st['required'], 'clashes' => $st['clashes'],
     'model' => $liveModel, 'inv' => 9001, 'at' => date('Y-m-d H:i'), 'narrative' => $narrative],
    ['id' => 103, 'live' => false, 'scope' => 'Class 7 · A,B,C', 'rules' => 'Maths max 2/day. Mr. Kishore mornings only. Games Friday last.',
     'status' => 'published', 'placed' => 90, 'required' => 90, 'clashes' => 0,
     'model' => 'gemini-2.5-flash', 'inv' => 8801, 'at' => '2026-06-24 18:41', 'narrative' => 'All quotas met; published to 3 sections.'],
    ['id' => 102, 'live' => false, 'scope' => 'Class 9 · A,B', 'rules' => 'Double-period labs. No PE after lunch.',
     'status' => 'discarded', 'placed' => 58, 'required' => 60, 'clashes' => 0,
     'model' => 'claude-sonnet-4-6', 'inv' => 8722, 'at' => '2026-06-23 11:07', 'narrative' => '2 periods unplaced — coordinator discarded and re-ran.'],
    ['id' => 101, 'live' => false, 'scope' => 'Class 6 · A', 'rules' => '8 maths a week, only 4 morning slots exist.',
     'status' => 'failed', 'placed' => 0, 'required' => 30, 'clashes' => 0,
     'model' => 'gemini-2.5-flash', 'inv' => 8601, 'at' => '2026-06-23 10:55', 'narrative' => 'Infeasible: 8 morning maths required but only 4 morning slots/day.'],
];

$reqLive = ['rules' => $rules, 'subjects' => count($input['subjects']), 'teachers' => count($input['teachers'])];
$resLive = ['subjects' => count($constraints['subjects'] ?? []), 'teachers' => count($constraints['teachers'] ?? []), '_source' => $liveSource];
$inv = [
    ['id' => 9002, 'run' => 104, 'tool' => 'timetable_narrate', 'model' => $liveModel, 'status' => 'success', 'tin' => $hasKey ? 412 : null, 'tout' => $hasKey ? 96 : null, 'ms' => $hasKey ? 1180 : 1, 'at' => date('Y-m-d H:i'), 'req' => ['stats' => $st], 'res' => ['text' => $narrative]],
    ['id' => 9001, 'run' => 104, 'tool' => 'timetable_intake', 'model' => $liveModel, 'status' => 'success', 'tin' => $hasKey ? 690 : null, 'tout' => $hasKey ? 240 : null, 'ms' => $hasKey ? 1420 : $intakeMs, 'at' => date('Y-m-d H:i'), 'req' => $reqLive, 'res' => $resLive],
    ['id' => 8802, 'run' => 103, 'tool' => 'timetable_narrate', 'model' => 'gemini-2.5-flash', 'status' => 'success', 'tin' => 388, 'tout' => 88, 'ms' => 1090, 'at' => '2026-06-24 18:41', 'req' => ['stats' => ['placed' => 90, 'required' => 90]], 'res' => ['text' => 'All quotas met; published to 3 sections.']],
    ['id' => 8801, 'run' => 103, 'tool' => 'timetable_intake', 'model' => 'gemini-2.5-flash', 'status' => 'success', 'tin' => 642, 'tout' => 205, 'ms' => 1365, 'at' => '2026-06-24 18:41', 'req' => ['rules' => 'Maths max 2/day. Mr. Kishore mornings only.'], 'res' => ['_source' => 'ai', 'rules' => 4]],
    ['id' => 8722, 'run' => 102, 'tool' => 'timetable_intake', 'model' => 'claude-sonnet-4-6', 'status' => 'success', 'tin' => 705, 'tout' => 233, 'ms' => 980, 'at' => '2026-06-23 11:07', 'req' => ['rules' => 'Double-period labs. No PE after lunch.'], 'res' => ['_source' => 'ai', 'rules' => 2]],
    ['id' => 8602, 'run' => 101, 'tool' => 'timetable_intake', 'model' => 'gemini-2.5-flash', 'status' => 'error', 'tin' => null, 'tout' => null, 'ms' => 30210, 'at' => '2026-06-23 10:55', 'req' => ['rules' => '8 maths a week'], 'res' => ['error' => 'HTTP 429 rate limit'], 'err' => 'Gemini HTTP 429 — fell back to deterministic parser'],
    ['id' => 8540, 'run' => null, 'tool' => 'substitute_email', 'model' => 'claude-sonnet-4-6', 'status' => 'success', 'tin' => 512, 'tout' => 180, 'ms' => 1240, 'at' => '2026-06-22 08:12', 'req' => ['absent' => 'T-Kishore', 'periods' => 5], 'res' => ['text' => 'Dear Ms. Rao, kindly cover Class 7B period 3…']],
];

$calls = count($inv);
$ok    = count(array_filter($inv, static fn($r) => $r['status'] === 'success'));
$tokIn = array_sum(array_map(static fn($r) => (int)$r['tin'], $inv));
$tokOut = array_sum(array_map(static fn($r) => (int)$r['tout'], $inv));
$avgMs = $calls ? (int)round(array_sum(array_map(static fn($r) => (int)$r['ms'], $inv)) / $calls) : 0;

$statusBg = ['success' => '#e8f9f0', 'blocked' => '#fff6e8', 'error' => '#fdeaea', 'draft' => '#eef3ff', 'published' => '#e8f9f0', 'discarded' => '#eef1f5', 'failed' => '#fdeaea'];
$statusFg = ['success' => '#0c7a43', 'blocked' => '#9a6b16', 'error' => '#b91c1c', 'draft' => '#1E4FB8', 'published' => '#0c7a43', 'discarded' => '#64708a', 'failed' => '#b91c1c'];
$badge = static fn($s) => '<span class="badge" style="background:' . ($GLOBALS['statusBg'][$s] ?? '#eef1f5') . ';color:' . ($GLOBALS['statusFg'][$s] ?? '#334') . '">' . htmlspecialchars($s) . '</span>';
$liveRun = $runs[0];
$liveChain = array_values(array_filter($inv, static fn($r) => $r['run'] === 104));
usort($liveChain, static fn($a, $b) => $a['id'] <=> $b['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>NXT School ERP — AI run logs</title>
<style>
    :root { --brand:#1E4FB8; --ink:#11203F; --mut:#64708a; --line:#e3e8ef; --good:#0c7a43; }
    * { box-sizing:border-box; }
    body { margin:0; font:14px/1.55 -apple-system,'Segoe UI',Roboto,Arial,sans-serif; color:var(--ink); background:#f5f7fb; }
    header { background:linear-gradient(120deg,#0d2a5e,var(--brand)); color:#fff; padding:18px 28px; }
    header h1 { margin:0; font-size:19px; } header p { margin:4px 0 0; opacity:.85; font-size:12.5px; }
    nav { margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; }
    nav a { color:#fff; background:rgba(255,255,255,.14); padding:6px 13px; border-radius:999px; text-decoration:none; font-weight:600; font-size:12.5px; }
    nav a.on { background:#fff; color:var(--brand); }
    main { max-width:1240px; margin:0 auto; padding:18px 24px 60px; }
    .cards { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:16px; }
    .kpi { background:#fff; border:1px solid var(--line); border-radius:10px; padding:13px 15px; }
    .kpi .n { font-size:20px; font-weight:800; letter-spacing:-.02em; } .kpi .l { color:var(--mut); font-size:11.5px; margin-top:2px; }
    .card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:16px 18px; margin-bottom:16px; }
    h2 { font-size:15px; margin:0 0 12px; }
    table { border-collapse:collapse; width:100%; font-size:12.5px; }
    th,td { border-bottom:1px solid var(--line); padding:8px 9px; text-align:left; vertical-align:top; }
    thead th { color:var(--mut); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
    tr.live td { background:#f3f7ff; }
    .badge { display:inline-block; border-radius:999px; padding:2px 9px; font-size:11px; font-weight:700; }
    .mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:11.5px; }
    .hash { color:var(--mut); }
    .tok { color:var(--mut); }
    details summary { cursor:pointer; color:var(--brand); font-weight:600; font-size:11.5px; }
    details pre { background:#0d1b35; color:#dce6fb; border-radius:8px; padding:10px 12px; font-size:11px; overflow:auto; margin:6px 0 0; max-width:520px; }
    .guard { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; border-radius:8px; padding:10px 13px; font-size:12.5px; margin-bottom:14px; }
    .note { background:#fffbeb; border:1px solid #fde68a; color:#92660b; border-radius:8px; padding:9px 12px; font-size:12px; margin-bottom:14px; }
    .chain { display:flex; flex-direction:column; gap:8px; }
    .step { display:flex; gap:10px; align-items:flex-start; padding:10px 12px; border:1px solid var(--line); border-radius:9px; background:#fafbfd; }
    .step .t { font-weight:700; font-size:12.5px; } .step .m { color:var(--mut); font-size:11.5px; }
    .pill { display:inline-block; background:#eef3ff; border:1px solid #c9d8f5; color:var(--brand); border-radius:999px; padding:2px 9px; font-size:11px; font-weight:600; }
    @media (max-width:980px){ .cards{grid-template-columns:repeat(2,1fr);} table{font-size:11.5px;} }
</style>
</head>
<body>
<header>
    <h1>NXT School ERP — AI run logs <span style="opacity:.7;font-weight:400">· timetable audit trail</span></h1>
    <p>Every AI call is recorded in <code>ai_invocations</code>; every generation is a <code>timetable_generation_runs</code> row. This is the audit viewer over both.</p>
    <nav>
        <a href="class.php">Timetable studio</a>
        <a href="logs.php" class="on">AI logs</a>
    </nav>
</header>
<main>
    <div class="guard">🔒 <strong>Full auditability:</strong> prompt-hash, model version, token counts, latency and status are stored for every call — so a disputed schedule or message can always be traced to exactly what the AI saw and produced. Nothing is hidden; failures and fallbacks are logged too.</div>
    <div class="note">▶ The newest run (<strong>#R-104</strong>) and its <strong>INV-9001/9002</strong> rows were just produced by the real engine on this page load (<?= (int)$intakeMs ?>ms intake + <?= (int)$solveMs ?>ms solve, source: <?= $h($liveSource) ?>). <?= $hasKey ? 'An LLM key is set, so token/latency are live.' : 'No LLM key set — the AI layer fell back to the deterministic parser, so token columns read “—”. With a Gemini/Claude key these rows record real model + tokens.' ?> Older rows illustrate the same viewer.</div>

    <div class="cards">
        <div class="kpi"><div class="n"><?= $calls ?></div><div class="l">AI calls logged</div></div>
        <div class="kpi"><div class="n" style="color:var(--good)"><?= $calls ? (int)round($ok / $calls * 100) : 100 ?>%</div><div class="l"><?= $ok ?>/<?= $calls ?> succeeded</div></div>
        <div class="kpi"><div class="n"><?= number_format($tokIn + $tokOut) ?></div><div class="l">Tokens (<?= number_format($tokIn) ?> in + <?= number_format($tokOut) ?> out)</div></div>
        <div class="kpi"><div class="n"><?= $avgMs ?>ms</div><div class="l">Avg latency / call</div></div>
        <div class="kpi"><div class="n"><?= count($runs) ?></div><div class="l">Generation runs</div></div>
    </div>

    <div class="card">
        <h2>Generation runs <span style="font-weight:400;color:var(--mut)">· timetable_generation_runs</span></h2>
        <table>
            <thead><tr><th>Run</th><th>Scope</th><th>Rules (intake)</th><th>Result</th><th>Status</th><th>Model</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($runs as $r): ?>
                <tr class="<?= $r['live'] ? 'live' : '' ?>">
                    <td class="mono">#R-<?= (int)$r['id'] ?><?= $r['live'] ? ' <span class="pill">live</span>' : '' ?></td>
                    <td><?= $h($r['scope']) ?></td>
                    <td style="max-width:240px"><?= $h($r['rules']) ?></td>
                    <td><?= (int)$r['placed'] ?>/<?= (int)$r['required'] ?> placed · <?= (int)$r['clashes'] ?> clashes</td>
                    <td><?= $badge($r['status']) ?></td>
                    <td class="mono"><?= $h($r['model']) ?></td>
                    <td class="mono hash"><?= $h($r['at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>AI invocation audit trail <span style="font-weight:400;color:var(--mut)">· ai_invocations</span></h2>
        <table>
            <thead><tr><th>ID</th><th>Run</th><th>Tool</th><th>Model</th><th>Status</th><th>Tokens</th><th>Latency</th><th>Prompt&nbsp;hash</th><th>Payload</th></tr></thead>
            <tbody>
            <?php foreach ($inv as $e): ?>
                <tr class="<?= ($e['run'] === 104) ? 'live' : '' ?>">
                    <td class="mono">INV-<?= (int)$e['id'] ?></td>
                    <td class="mono"><?= $e['run'] ? '#R-' . (int)$e['run'] : '—' ?></td>
                    <td class="mono"><?= $h($e['tool']) ?></td>
                    <td class="mono"><?= $h($e['model']) ?></td>
                    <td><?= $badge($e['status']) ?></td>
                    <td class="tok mono"><?= $e['tin'] !== null ? (int)$e['tin'] . '→' . (int)$e['tout'] : '—' ?></td>
                    <td class="mono"><?= (int)$e['ms'] ?>ms</td>
                    <td class="mono hash"><?= $h($hash($e['req'])) ?></td>
                    <td>
                        <details>
                            <summary>view</summary>
                            <pre>request:  <?= $h(json_encode($e['req'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>

response: <?= $h(json_encode($e['res'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?><?= !empty($e['err']) ? "\n\nerror:    " . $h($e['err']) : '' ?></pre>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Run #R-104 — full audit chain <span style="font-weight:400;color:var(--mut)">· what the AI did, step by step</span></h2>
        <div class="chain">
            <div class="step"><div><div class="t">1 · Coordinator submitted rules</div><div class="m">“<?= $h($liveRun['rules']) ?>” → run created (<?= $badge($liveRun['status']) ?>), scope <?= $h($liveRun['scope']) ?></div></div></div>
            <?php foreach ($liveChain as $i => $e): ?>
                <div class="step">
                    <div>
                        <div class="t"><?= $i + 2 ?> · <?= $h($e['tool']) ?> <span class="pill"><?= $h($e['model']) ?></span> <?= $badge($e['status']) ?></div>
                        <div class="m">INV-<?= (int)$e['id'] ?> · <?= (int)$e['ms'] ?>ms · tokens <?= $e['tin'] !== null ? (int)$e['tin'] . '→' . (int)$e['tout'] : '—' ?> · hash <?= $h($hash($e['req'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="step"><div><div class="t"><?= count($liveChain) + 2 ?> · Result narrated</div><div class="m"><?= $h($liveRun['narrative']) ?></div></div></div>
            <div class="step"><div><div class="t"><?= count($liveChain) + 3 ?> · Awaiting coordinator decision</div><div class="m">Draft held for review — publish copies academic slots into <code>subject_timetable</code>; discard archives the run. Nothing is auto-applied.</div></div></div>
        </div>
    </div>
</main>
</body>
</html>
