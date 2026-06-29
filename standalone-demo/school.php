<?php
/**
 * Whole-School Timetable & Teacher Allocation — STANDALONE DEMO
 * ----------------------------------------------------------------------
 * Shows the cross-class guarantee a coordinator/principal cares about:
 * one teacher who teaches a subject across many classes is allocated per
 * class-section, balanced, and NEVER double-booked at the same time.
 *
 *   php -S localhost:8088   →   http://localhost:8088/school.php
 *
 * Runs the REAL engine (components/ai/timetable/) on a 10-section, classes
 * 6–10 fixture. No Yii, no DB, no API key.
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

$cands = [__DIR__ . '/../components/ai/timetable', __DIR__ . '/../new-files/components/ai/timetable', __DIR__ . '/new-files/components/ai/timetable'];
$engine = null;
foreach ($cands as $c) {
    if (is_file($c . '/TimetableSolver.php')) { $engine = $c; break; }
}
if ($engine === null) { http_response_code(500); exit('Engine not found.'); }
require $engine . '/TimetableSolver.php';
require $engine . '/SolverFixtures.php';
require $engine . '/ConstraintIntake.php';

use app\components\ai\timetable\ConstraintIntake;
use app\components\ai\timetable\SolverFixtures;
use app\components\ai\timetable\TimetableSolver;

$promptsDir = dirname($engine) . '/prompts';

// ── Gemini (self-contained; reads key from .env, like the other demos) ──────
function sch_gemini_cfg(): array
{
    $cfg = ['key' => getenv('GEMINI_API_KEY') ?: '', 'model' => getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash'];
    if ($cfg['key'] === '') {
        foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env', dirname(__DIR__, 2) . '/.env'] as $env) {
            if (!is_file($env)) continue;
            foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim($v);
                if ($k === 'GEMINI_API_KEY' && $cfg['key'] === '') $cfg['key'] = $v;
                if ($k === 'GEMINI_MODEL' && !getenv('GEMINI_MODEL')) $cfg['model'] = $v;
            }
            if ($cfg['key'] !== '') break;
        }
    }
    return $cfg;
}
function sch_gemini_intake(string $rules, array $maps, string $promptPath, array $cfg): array
{
    $tpl = is_file($promptPath) ? file_get_contents($promptPath) : '';
    $system = strtr($tpl, [
        '{{SUBJECTS}}' => implode(', ', array_values($maps['subject_names'])),
        '{{TEACHERS}}' => implode(', ', array_values($maps['teacher_names'])),
        '{{DAYS}}'     => 'Monday to Saturday',
    ]);
    $gen = ['maxOutputTokens' => 2048, 'temperature' => 0];
    if (strpos($cfg['model'], '2.5') !== false || strpos($cfg['model'], 'latest') !== false) {
        $gen['thinkingConfig'] = ['thinkingBudget' => 0];
    }
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($cfg['model']) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-goog-api-key: ' . $cfg['key']],
        CURLOPT_POSTFIELDS => json_encode(['contents' => [['role' => 'user', 'parts' => [['text' => $rules]]]],
            'system_instruction' => ['parts' => [['text' => $system]]], 'generationConfig' => $gen], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false || $code >= 400) throw new \RuntimeException('HTTP ' . $code);
    $j = json_decode($raw, true); $t = '';
    foreach ($j['candidates'][0]['content']['parts'] ?? [] as $p) if (isset($p['text'])) $t .= $p['text'];
    $obj = json_decode(preg_replace('/^```json\s*|\s*```$/', '', trim($t)), true);
    if (!is_array($obj)) throw new \RuntimeException('non-JSON');
    return $obj;
}
function sch_fmt_min(int $m): string { return sprintf('%02d:%02d', intdiv((int) round($m), 60) % 24, (int) round($m) % 60); }
function sch_parse_timings(string $r): ?array
{
    if (!preg_match('/(\d{1,2})[:.]?(\d{2})?\s*(a\.?m\.?|p\.?m\.?)?\s*(?:to|-|–|—|until|till)\s*(\d{1,2})[:.]?(\d{2})?\s*(a\.?m\.?|p\.?m\.?)?/i', $r, $m)) return null;
    $to = static function ($h, $mi, $ap) { $h = (int) $h; $mi = (int) ($mi ?: 0); $ap = strtolower(str_replace('.', '', $ap ?? '')); if ($ap === 'pm' && $h < 12) $h += 12; if ($ap === 'am' && $h === 12) $h = 0; if ($ap === '' && $h >= 1 && $h <= 6) $h += 12; return $h * 60 + $mi; };
    $s = $to($m[1], $m[2] ?? 0, $m[3] ?? ''); $e = $to($m[4], $m[5] ?? 0, $m[6] ?? '');
    return ($e - $s >= 180 && $e - $s <= 720) ? ['start' => $s, 'end' => $e] : null;
}
function sch_build_layout(int $start, int $end, int $n): array
{
    $A = 15; $S = 15; $L = 35; $G = 0; $acad = max($n * 30, ($end - $start) - $A - $S - $L); $pl = (int) floor($acad / $n); $cur = $start;
    $out = [['kind' => 'assembly', 'label' => 'Assembly', 'time_from' => sch_fmt_min($cur), 'time_to' => sch_fmt_min($cur + $A)]]; $cur += $A;
    for ($p = 1; $p <= $n; $p++) {
        $out[] = ['kind' => 'period', 'no' => $p, 'time_from' => sch_fmt_min($cur), 'time_to' => sch_fmt_min($cur + $pl)]; $cur += $pl;
        if ($p === 3) { $out[] = ['kind' => 'break', 'label' => 'Snack', 'time_from' => sch_fmt_min($cur), 'time_to' => sch_fmt_min($cur + $S)]; $cur += $S; }
        if ($p === 5) { $out[] = ['kind' => 'lunch', 'label' => 'Lunch', 'time_from' => sch_fmt_min($cur), 'time_to' => sch_fmt_min($cur + $L)]; $cur += $L; }
    }
    return $out;
}
// Apply parsed constraints school-wide: teachers/days globally, subject rules to
// EVERY section's per-class subject list.
function sch_apply(array $input, array $c): array
{
    if (!empty($c['days']) && is_array($c['days'])) {
        $d = array_values(array_filter(array_map('intval', $c['days']), fn($x) => $x >= 1 && $x <= 7));
        if ($d) { sort($d); $input['days'] = array_values(array_unique($d)); }
    }
    foreach ($c['teachers'] ?? [] as $rule) {
        foreach ($input['teachers'] as &$t) {
            $match = (isset($rule['name_like']) && stripos($t['name'], (string) $rule['name_like']) !== false);
            if (!$match) continue;
            if (isset($rule['morning_only'])) $t['morning_only'] = (bool) $rule['morning_only'];
            if (isset($rule['max_per_day'])) $t['max_per_day'] = max(1, min(8, (int) $rule['max_per_day']));
            if (isset($rule['max_per_week'])) $t['max_per_week'] = max(1, min(48, (int) $rule['max_per_week']));
        }
        unset($t);
    }
    foreach ($input['sections'] as &$sec) {
        if (empty($sec['subjects'])) continue;
        foreach ($sec['subjects'] as &$sub) {
            foreach ($c['subjects'] ?? [] as $rule) {
                if (!isset($rule['name_like']) || stripos($sub['name'], (string) $rule['name_like']) === false) continue;
                if (isset($rule['per_week'])) $sub['per_week'] = max(0, min(15, (int) $rule['per_week']));
                if (isset($rule['max_per_day'])) $sub['max_per_day'] = max(1, min(4, (int) $rule['max_per_day']));
                if (isset($rule['after_lunch_only'])) $sub['after_lunch_only'] = (bool) $rule['after_lunch_only'];
            }
            unset($sub);
        }
        unset($sec);
    }
    return $input;
}

$input  = SolverFixtures::wholeSchoolInput();
$seed   = isset($_GET['seed']) && $_GET['seed'] !== '' ? (int)$_GET['seed'] : 20260615;
$rules  = isset($_GET['rules']) ? trim((string) $_GET['rules']) : 'Mr. Kishore only teaches mornings. PE in the afternoon. No more than 2 Mathematics a day. Library once a week. Classes run Monday to Saturday.';

// Build grounding maps (subject + teacher names) for the parser.
$mapsForIntake = [
    'subject_names' => [],
    'teacher_names' => array_column($input['teachers'], 'name', 'id'),
];
foreach ($input['sections'] as $sx) foreach (($sx['subjects'] ?? []) as $su) $mapsForIntake['subject_names'][$su['id']] = $su['name'];

$intakeSource = 'none'; $intakeDetail = ''; $constraints = []; $appliedTimings = null;
if ($rules !== '') {
    $cfg = sch_gemini_cfg();
    if ($cfg['key'] !== '') {
        try {
            $constraints = sch_gemini_intake($rules, $mapsForIntake, $promptsDir . '/timetable_intake.txt', $cfg);
            $intakeSource = 'gemini'; $intakeDetail = $cfg['model'];
        } catch (\Throwable $e) {
            $constraints = (new ConstraintIntake())->fallbackParse($rules, $mapsForIntake);
            $intakeSource = 'keyword'; $intakeDetail = 'Gemini error: ' . $e->getMessage();
        }
    } else {
        $constraints = (new ConstraintIntake())->fallbackParse($rules, $mapsForIntake);
        $intakeSource = 'keyword'; $intakeDetail = 'no GEMINI_API_KEY';
    }
    // School hours → rebuild layout (keep period count so quotas stay valid).
    $appliedTimings = null;
    $tm = sch_parse_timings($rules);
    if ($tm !== null) {
        $pc = 0; foreach ($input['layout'] as $col) if ($col['kind'] === 'period') $pc++;
        $input['layout'] = sch_build_layout($tm['start'], $tm['end'], $pc);
        $appliedTimings = sch_fmt_min($tm['start']) . '–' . sch_fmt_min($tm['end']);
    }
    $input = sch_apply($input, $constraints);
}

$result = TimetableSolver::solve($input, $seed);
$slots  = $result['slots'];
$stats  = $result['stats'];

$teacherName = array_column($input['teachers'], 'name', 'id');
$sectionName = [];
$sectionClass = [];
foreach ($input['sections'] as $s) { $sectionName[$s['id']] = $s['name']; $sectionClass[$s['id']] = $s['class']; }

// subject id → name (union across streams)
$subjName = [];
foreach ($input['sections'] as $sec) {
    foreach (($sec['subjects'] ?? $input['subjects']) as $su) { $subjName[$su['id']] = $su['name']; }
}

// index slots
$bySecDayP = [];      // [secId][day][period] => slot
$teacherWeek = [];    // [tid][day][period] => section_name
$allocation = [];     // [subjId][secId] => tid  (who teaches subject in section)
$teacherLoad = [];
foreach ($slots as $s) {
    $bySecDayP[$s['section_id']][$s['day']][$s['period']] = $s;
    $teacherWeek[$s['teacher_id']][$s['day']][$s['period']] = $s['section_name'];
    $allocation[$s['subject_id']][$s['section_id']] = $s['teacher_id'];
    $teacherLoad[$s['teacher_id']] = ($teacherLoad[$s['teacher_id']] ?? 0) + 1;
}

$periodNos = [];
foreach ($input['layout'] as $c) if ($c['kind'] === 'period') $periodNos[] = (int)$c['no'];
$days = $input['days'];
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

// selections
$selTeacher = isset($_GET['teacher']) ? (int)$_GET['teacher'] : 1; // default Mr. Kishore
if (!isset($teacherName[$selTeacher])) $selTeacher = (int)array_key_first($teacherName);
$selSection = isset($_GET['sid']) ? (int)$_GET['sid'] : (int)$input['sections'][0]['id'];

// ── Substitute finder (whole-school) ────────────────────────────────────────
// who is busy at each (day,period), and which teachers can teach each subject
$busyAt = [];          // [day][period][tid] => true
$subjectTeachers = []; // [subjId][tid] => true (competence, from the generated grid)
foreach ($slots as $s) {
    $busyAt[$s['day']][$s['period']][$s['teacher_id']] = true;
    $subjectTeachers[$s['subject_id']][$s['teacher_id']] = true;
}
$subTeacher = isset($_GET['sub_teacher']) ? (int)$_GET['sub_teacher'] : 0;
$subDay     = isset($_GET['sub_day']) ? (int)$_GET['sub_day'] : 0;
$subResult  = [];
if ($subTeacher && $subDay) {
    $affected = array_values(array_filter($slots, fn($s) => $s['teacher_id'] === $subTeacher && $s['day'] === $subDay));
    usort($affected, fn($a, $b) => $a['period'] <=> $b['period']);
    foreach ($affected as $ap) {
        $cands = [];
        foreach ($input['teachers'] as $t) {
            $tid = $t['id'];
            if ($tid === $subTeacher) continue;
            if (isset($busyAt[$subDay][$ap['period']][$tid])) continue; // must be free that slot
            $cands[] = [
                'id'   => $tid,
                'name' => $teacherName[$tid],
                'same' => isset($subjectTeachers[$ap['subject_id']][$tid]), // taught this subject before
                'load' => $teacherLoad[$tid] ?? 0,
            ];
        }
        // rank: same-subject first, then lightest weekly load (fairness)
        usort($cands, fn($a, $b) => ($b['same'] <=> $a['same']) ?: ($a['load'] <=> $b['load']));
        $subResult[] = ['period' => $ap, 'cands' => array_slice($cands, 0, 3)];
    }
}

// verify: is the selected teacher ever double-booked? (proof)
$selClash = 0; $seen = [];
foreach ($slots as $s) {
    if ($s['teacher_id'] !== $selTeacher) continue;
    $k = $s['day'] . '|' . $s['period'];
    if (isset($seen[$k])) $selClash++;
    $seen[$k] = true;
}

// distinct teachers per subject (alternative-allocation proof) for a headline subject
$mathTeachers = [];
foreach ($allocation as $subjId => $bySec) {
    if (($subjName[$subjId] ?? '') === 'Mathematics') {
        foreach ($bySec as $tid) $mathTeachers[$tid] = true;
    }
}

$tones = ['#dbeafe','#ede9fe','#dcfce7','#ffedd5','#fce7f3','#cffafe','#fef9c3','#fee2e2','#e0e7ff','#d1fae5','#fae8ff','#f1f5f9','#fde68a','#bbf7d0','#fecaca','#c7d2fe','#a5f3fc','#fbcfe8','#d9f99d','#e9d5ff','#bae6fd','#fed7aa'];
$toneOf = fn(int $id) => $tones[$id % count($tones)];
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

// classes list
$classes = [];
foreach ($input['sections'] as $s) $classes[$s['class']][] = $s;
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>NXT — Whole-School Timetable & Teacher Allocation</title>
<style>
    :root{--brand:#1E4FB8;--ink:#11203F;--mut:#64708a;--line:#e3e8ef;--good:#0c7a43;}
    *{box-sizing:border-box;} body{margin:0;font:14px/1.55 -apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:var(--ink);background:#f5f7fb;}
    header{background:linear-gradient(120deg,#13306E,var(--brand));color:#fff;padding:18px 28px;}
    header h1{margin:0;font-size:19px;} header p{margin:4px 0 0;opacity:.88;font-size:12.5px;}
    header a{color:#cfe0ff;}
    main{max-width:1320px;margin:0 auto;padding:18px 24px 60px;}
    .card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin-bottom:16px;}
    .chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px;}
    .chip{background:#eef3ff;border:1px solid #c9d8f5;color:var(--brand);border-radius:999px;padding:4px 12px;font-size:12px;font-weight:600;}
    .chip.good{background:#e8f9f0;border-color:#b3e6cd;color:var(--good);}
    h2{font-size:15px;margin:0 0 12px;}
    table{border-collapse:collapse;width:100%;font-size:12px;}
    th,td{border:1px solid var(--line);padding:5px 7px;text-align:center;vertical-align:middle;}
    thead th{background:var(--brand);color:#fff;font-weight:600;}
    .rowhead{background:#f7f9fc;font-weight:700;white-space:nowrap;text-align:left;}
    .subj{font-weight:700;display:block;} .teach{color:#7a8194;font-size:10.5px;}
    .small{color:var(--mut);font-size:11px;}
    .grid-wrap{overflow-x:auto;}
    select{border:1px solid var(--line);border-radius:8px;padding:8px 11px;font:inherit;}
    .pill{display:inline-block;border-radius:6px;padding:1px 6px;font-size:11px;font-weight:600;}
    .note{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:8px;padding:10px 13px;font-size:12.5px;margin-bottom:14px;}
    .free{color:#c4cbd8;}
    .tabs a{display:inline-block;text-decoration:none;color:var(--mut);border:1px solid var(--line);background:#fff;border-radius:999px;padding:8px 16px;font-weight:700;font-size:13px;margin-right:8px;}
</style></head>
<body>
<header>
    <h1>NXT School ERP — Whole-School Timetable &amp; Teacher Allocation</h1>
    <p>Classes 6–10 generated in ONE run on the real engine. A teacher who teaches across classes is allocated per section, balanced, and never double-booked. · <a href="index.php">← coordinator studio</a> · <a href="class.php">single class →</a> · <a href="logs.php">AI logs →</a></p>
</header>
<main>
    <form class="card" method="get">
        <h2>Prompt the whole school — plain English</h2>
        <textarea name="rules" style="width:100%;min-height:60px;border:1px solid var(--line);border-radius:8px;padding:9px 11px;font:inherit"><?= $h($rules) ?></textarea>
        <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button type="submit" style="background:var(--brand);color:#fff;border:none;border-radius:8px;padding:10px 18px;font:600 13px/1 inherit;cursor:pointer">⚡ Generate</button>
            <span class="small">Rules apply across every class — e.g. "Mr. Bose only teaches mornings", "PE in the afternoon", "no Saturday classes", "school hours 8:30 to 3:30".</span>
        </div>
        <div class="chips" style="margin-top:10px">
            <?php if ($intakeSource === 'gemini'): ?><span class="chip" style="background:#ede9fe;border-color:#c4b5fd;color:#6d28d9">🤖 parsed by Gemini · <?= $h($intakeDetail) ?></span>
            <?php elseif ($intakeSource === 'keyword'): ?><span class="chip">⚙︎ keyword parser<?= $intakeDetail ? ' (' . $h($intakeDetail) . ')' : '' ?></span><?php endif; ?>
            <?php if (!empty($appliedTimings)): ?><span class="chip" style="background:#e0f2fe;border-color:#7dd3fc;color:#0369a1">🕗 school hours <?= $h($appliedTimings) ?></span><?php endif; ?>
            <?php foreach (($constraints['subjects'] ?? []) as $r): ?><span class="chip">rule: <?= $h($r['name_like'] ?? '') ?><?= isset($r['per_week']) ? " {$r['per_week']}/wk" : '' ?><?= isset($r['max_per_day']) ? " ≤{$r['max_per_day']}/day" : '' ?><?= !empty($r['after_lunch_only']) ? ' PM-only' : '' ?></span><?php endforeach; ?>
            <?php foreach (($constraints['teachers'] ?? []) as $r): ?><span class="chip">rule: <?= $h($r['name_like'] ?? '') ?><?= !empty($r['morning_only']) ? ' mornings-only' : '' ?><?= isset($r['max_per_day']) ? " ≤{$r['max_per_day']}/day" : '' ?></span><?php endforeach; ?>
            <?php if (!empty($constraints['days'])): ?><span class="chip">days: <?= count($constraints['days']) ?>/wk</span><?php endif; ?>
        </div>
    </form>
    <div class="card">
        <div class="chips">
            <span class="chip good"><?= count($input['sections']) ?> class-sections (6–10)</span>
            <span class="chip good"><?= (int)$stats['placed'] ?>/<?= (int)$stats['required'] ?> periods placed</span>
            <span class="chip good"><?= (int)$stats['clashes'] ?> teacher clashes</span>
            <span class="chip"><?= count($input['teachers']) ?> teachers</span>
            <span class="chip"><?= count($mathTeachers) ?> teachers share Mathematics across classes</span>
        </div>
        <div class="note">🔒 <strong>Cross-class guarantee:</strong> the whole school is solved together, so a teacher placed in one class can never be placed in another at the same time. Where a subject would collide across classes, an <strong>alternative teacher</strong> is assigned automatically — exactly the permutation a coordinator does by hand.</div>
    </div>

    <!-- TEACHER ALLOCATION MATRIX -->
    <div class="card">
        <h2>🗂 Coordinator · teacher allocation — who teaches each subject in each class-section</h2>
        <div class="grid-wrap">
        <table>
            <thead><tr><th class="rowhead">Subject \ Section</th>
                <?php foreach ($input['sections'] as $sec): ?><th><?= $h($sec['name']) ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php
            // union of subject ids, ordered by name
            $subjIds = array_keys($subjName);
            usort($subjIds, fn($a, $b) => strcmp($subjName[$a], $subjName[$b]));
            foreach ($subjIds as $subjId):
                // distinct teachers for this subject across the school
                $distinct = [];
                foreach (($allocation[$subjId] ?? []) as $tid) $distinct[$tid] = true;
            ?>
                <tr>
                    <td class="rowhead"><?= $h($subjName[$subjId]) ?>
                        <?php if (count($distinct) > 1): ?><span class="small">· <?= count($distinct) ?> teachers</span><?php endif; ?></td>
                    <?php foreach ($input['sections'] as $sec):
                        $tid = $allocation[$subjId][$sec['id']] ?? null; ?>
                        <td style="<?= $tid ? 'background:' . $toneOf($tid) : '' ?>">
                            <?php if ($tid): ?><a style="text-decoration:none;color:#1b2b4b" href="?teacher=<?= $tid ?>"><?= $h($teacherName[$tid]) ?></a><?php else: ?><span class="free">—</span><?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="small" style="margin-top:8px">Each colour is a teacher. Notice Mathematics is shared across several teachers (e.g. Kishore / Vanaja / Rao) so parallel classes never need the same person. Click a name to see that teacher's week.</p>
    </div>

    <!-- TEACHER WEEK (proof of no double-booking) -->
    <div class="card">
        <h2>👩‍🏫 Teacher · week across all classes
            <span style="font-weight:400;color:var(--mut)">— proof of "one place at a time"</span></h2>
        <form method="get" style="margin-bottom:12px">
            <input type="hidden" name="rules" value="<?= $h($rules) ?>">
            <select name="teacher" onchange="this.form.submit()">
                <?php foreach ($teacherName as $tid => $tn): ?>
                    <option value="<?= $tid ?>" <?= $tid === $selTeacher ? 'selected' : '' ?>><?= $h($tn) ?> (<?= (int)($teacherLoad[$tid] ?? 0) ?>/wk)</option>
                <?php endforeach; ?>
            </select>
            <span class="chip good" style="margin-left:8px">✓ <?= $selClash === 0 ? 'never double-booked' : $selClash . ' CLASHES' ?></span>
        </form>
        <div class="grid-wrap">
        <table>
            <thead><tr><th class="rowhead">Day</th><?php foreach ($periodNos as $p): ?><th>P<?= $p ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($days as $d): ?>
                <tr><td class="rowhead"><?= $dayNames[$d] ?? $d ?></td>
                    <?php foreach ($periodNos as $p):
                        $secNm = $teacherWeek[$selTeacher][$d][$p] ?? null; ?>
                        <td style="<?= $secNm ? 'background:' . $toneOf($selTeacher) : '' ?>">
                            <?php if ($secNm): ?><strong><?= $h($secNm) ?></strong><?php else: ?><span class="free">free</span><?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="small" style="margin-top:8px">Every cell holds at most one class — <?= $h($teacherName[$selTeacher]) ?> is in exactly one place each period, even while teaching across multiple classes.</p>
    </div>

    <!-- SUBSTITUTE FINDER (whole-school) -->
    <div class="card">
        <h2>🔄 Substitute finder <span style="font-weight:400;color:var(--mut)">— teacher absent? cover every affected class from free teachers school-wide</span></h2>
        <form method="get" style="margin-bottom:12px">
            <input type="hidden" name="teacher" value="<?= $selTeacher ?>">
            <input type="hidden" name="sid" value="<?= $selSection ?>">
            <input type="hidden" name="rules" value="<?= $h($rules) ?>">
            Absent:
            <select name="sub_teacher">
                <option value="0">— select teacher —</option>
                <?php foreach ($teacherName as $tid => $tn): ?>
                    <option value="<?= $tid ?>" <?= $tid === $subTeacher ? 'selected' : '' ?>><?= $h($tn) ?></option>
                <?php endforeach; ?>
            </select>
            on
            <select name="sub_day">
                <option value="0">— day —</option>
                <?php foreach ($days as $d): ?>
                    <option value="<?= $d ?>" <?= $d === $subDay ? 'selected' : '' ?>><?= $dayNames[$d] ?? $d ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" style="background:var(--brand);color:#fff;border:none;border-radius:7px;padding:7px 14px;font:600 12.5px/1 inherit;cursor:pointer">Find cover</button>
        </form>
        <?php if ($subTeacher && $subDay): ?>
            <?php if ($subResult === []): ?>
                <p class="small"><?= $h($teacherName[$subTeacher] ?? '') ?> has no periods on <?= $dayNames[$subDay] ?? $subDay ?> — nothing to cover.</p>
            <?php else: ?>
                <p class="small"><strong><?= $h($teacherName[$subTeacher]) ?></strong> is absent on <strong><?= $dayNames[$subDay] ?? $subDay ?></strong> — <?= count($subResult) ?> period(s) need cover across the school:</p>
                <div class="grid-wrap"><table>
                    <thead><tr><th class="rowhead">Period · Class · Subject</th><th>Ranked cover (free → same-subject → lightest load)</th></tr></thead>
                    <tbody>
                    <?php foreach ($subResult as $r): $ap = $r['period']; ?>
                        <tr>
                            <td class="rowhead">P<?= (int)$ap['period'] ?> · <?= $h($ap['section_name']) ?> · <?= $h($subjName[$ap['subject_id']] ?? '') ?></td>
                            <td style="text-align:left">
                                <?php if ($r['cands'] === []): ?><span class="small" style="color:#b91c1c">No free teacher this slot — coordinator alert.</span>
                                <?php else: foreach ($r['cands'] as $i => $c): ?>
                                    <span class="pill" style="background:<?= $i === 0 ? '#e8f9f0' : '#f1f5f9' ?>;color:<?= $i === 0 ? 'var(--good)' : 'var(--mut)' ?>;margin:0 4px 4px 0;<?= $i === 0 ? 'border:1px solid #b3e6cd' : '' ?>">
                                        <?= $i === 0 ? '✓ ' : '' ?><?= $h($c['name']) ?><?= $c['same'] ? ' · same subject' : '' ?> · <?= (int)$c['load'] ?>/wk</span>
                                <?php endforeach; endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <p class="small" style="margin-top:8px">Top pick (✓) = free that exact period, already teaches the subject, lightest weekly load. In production this writes a one-click <code>temporary_assign_teacher</code> row + notifies the teacher (approval-first).</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="small">Pick an absent teacher and a day. Try <strong>Mr. Kishore</strong> — every Maths class he covers across 6–10 gets ranked cover from other Maths teachers who are free that period.</p>
        <?php endif; ?>
    </div>

    <!-- ONE CLASS-SECTION TIMETABLE -->
    <div class="card">
        <h2>🗂 Coordinator · section timetable</h2>
        <form method="get" style="margin-bottom:12px">
            <input type="hidden" name="teacher" value="<?= $selTeacher ?>">
            <input type="hidden" name="rules" value="<?= $h($rules) ?>">
            <select name="sid" onchange="this.form.submit()">
                <?php foreach ($input['sections'] as $sec): ?>
                    <option value="<?= $sec['id'] ?>" <?= (int)$sec['id'] === $selSection ? 'selected' : '' ?>>Class <?= $h($sec['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <div class="grid-wrap">
        <table>
            <thead><tr><th class="rowhead">Day</th>
                <?php foreach ($input['layout'] as $col): if ($col['kind'] === 'period'): ?>
                    <th>P<?= (int)$col['no'] ?><br><span style="font-weight:400;font-size:10px"><?= $h($col['time_from']) ?></span></th>
                <?php else: ?><th style="background:#16408f"><?= $h($col['label'] ?? ucfirst($col['kind'])) ?></th><?php endif; endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($days as $d): ?>
                <tr><td class="rowhead"><?= $dayNames[$d] ?? $d ?></td>
                    <?php foreach ($input['layout'] as $col):
                        if ($col['kind'] === 'period'):
                            $sl = $bySecDayP[$selSection][$d][(int)$col['no']] ?? null; ?>
                            <td style="<?= $sl ? 'background:' . $toneOf($sl['teacher_id']) : '' ?>">
                                <?php if ($sl): ?><span class="subj"><?= $h($subjName[$sl['subject_id']] ?? '') ?></span><span class="small"><?= $h($teacherName[$sl['teacher_id']] ?? '') ?></span><?php else: ?><span class="free">—</span><?php endif; ?>
                            </td>
                        <?php else: ?><td style="background:#f2f4f7;color:#667;font-size:10px"><?= $h(substr($col['label'] ?? ucfirst($col['kind']), 0, 5)) ?></td><?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</main>
</body></html>
