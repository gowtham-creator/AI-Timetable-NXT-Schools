<?php
/**
 * Standalone verification for the AI timetable solver — no Yii bootstrap, no DB.
 *
 *   php tests/timetable_solver_test.php
 *
 * Mirrors `php yii timetable/solver-test` for environments where the full app
 * bootstrap is unavailable (e.g. dev machines on newer PHP than production).
 * Exits non-zero on any failed invariant.
 */

require __DIR__ . '/../components/ai/timetable/TimetableSolver.php';
require __DIR__ . '/../components/ai/timetable/SolverFixtures.php';
require __DIR__ . '/../components/ai/timetable/FeasibilityAnalyzer.php';

use app\components\ai\timetable\FeasibilityAnalyzer;
use app\components\ai\timetable\SolverFixtures;
use app\components\ai\timetable\TimetableSolver;

$input  = SolverFixtures::demoInput();
$result = TimetableSolver::solve($input, 20260611);

$stats = $result['stats'];
$slots = $result['slots'];
$pass  = true;
$check = function (string $label, bool $ok) use (&$pass) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$ok) {
        $pass = false;
    }
};

echo "AI Timetable — solver verification (standalone)\n";
echo "===============================================\n";
echo "required={$stats['required']} placed={$stats['placed']} fill={$stats['fill_pct']}% "
    . "clashes={$stats['clashes']} seed={$stats['seed']}\n\n";

$check('all required periods placed', $stats['unplaced_count'] === 0);
$check('zero teacher/section clashes', $stats['clashes'] === 0);
$check('solver reports ok', $result['ok'] === true);

// Quotas exact per section × subject.
$want = $got = [];
foreach ($input['subjects'] as $s) {
    foreach ($input['sections'] as $sec) {
        $want[$sec['id'] . '|' . $s['id']] = (int)$s['per_week'];
    }
}
foreach ($slots as $s) {
    $k = $s['section_id'] . '|' . $s['subject_id'];
    $got[$k] = ($got[$k] ?? 0) + 1;
}
$quotaOk = true;
foreach ($want as $k => $n) {
    if (($got[$k] ?? 0) !== $n) {
        $quotaOk = false;
        echo "        quota mismatch {$k}: want {$n} got " . ($got[$k] ?? 0) . "\n";
    }
}
$check('weekly quota exact for every section × subject', $quotaOk);

// Morning periods = before the lunch column.
$lunchSeen = false;
$morningPeriods = [];
foreach ($input['layout'] as $col) {
    if ($col['kind'] === 'lunch') {
        $lunchSeen = true;
    }
    if ($col['kind'] === 'period' && !$lunchSeen) {
        $morningPeriods[(int)$col['no']] = true;
    }
}

// After-lunch-only subjects stay after lunch.
$afterLunchIds = [];
foreach ($input['subjects'] as $s) {
    if (!empty($s['after_lunch_only'])) {
        $afterLunchIds[$s['id']] = $s['name'];
    }
}
$ptOk = true;
foreach ($slots as $s) {
    if (isset($afterLunchIds[$s['subject_id']]) && isset($morningPeriods[$s['period']])) {
        $ptOk = false;
    }
}
$check('after-lunch-only subjects stay after lunch', $ptOk);

// Morning-only teachers never after lunch.
$morningOnlyIds = [];
foreach ($input['teachers'] as $t) {
    if (!empty($t['morning_only'])) {
        $morningOnlyIds[$t['id']] = $t['name'];
    }
}
$moOk = true;
foreach ($slots as $s) {
    if (isset($morningOnlyIds[$s['teacher_id']]) && !isset($morningPeriods[$s['period']])) {
        $moOk = false;
    }
}
$check('morning-only teachers never placed after lunch', $moOk);

// Subject max-per-day.
$maxPerDay = [];
foreach ($input['subjects'] as $s) {
    $maxPerDay[$s['id']] = (int)($s['max_per_day'] ?? 2);
}
$dayCount = [];
foreach ($slots as $s) {
    $k = $s['section_id'] . '|' . $s['subject_id'] . '|' . $s['day'];
    $dayCount[$k] = ($dayCount[$k] ?? 0) + 1;
}
$mpdOk = true;
foreach ($dayCount as $k => $n) {
    $sid = (int)explode('|', $k)[1];
    if ($n > $maxPerDay[$sid]) {
        $mpdOk = false;
    }
}
$check('subject max-per-day respected', $mpdOk);

// Teacher weekly cap.
$loadOk = true;
foreach ($result['teacher_load'] as $tid => $n) {
    if ($n > 30) {
        $loadOk = false;
    }
}
$check('teacher weekly load within cap', $loadOk);

$check('independent validate() finds no clashes', TimetableSolver::validate($slots) === []);

// Teacher consistency: one teacher owns each (section, subject) all week —
// like a real teacher-wise workload sheet.
$owners = [];
foreach ($slots as $s) {
    $owners[$s['section_id'] . '|' . $s['subject_id']][$s['teacher_id']] = true;
}
$consistent = true;
foreach ($owners as $byTeacher) {
    if (count($byTeacher) > 1) {
        $consistent = false;
    }
}
$check('teacher consistency: one teacher per section × subject', $consistent);

// Anti-column sanity: no subject occupies the same period on ALL five days.
$colCount = [];
foreach ($slots as $s) {
    $k = $s['section_id'] . '|' . $s['subject_id'] . '|' . $s['period'];
    $colCount[$k] = ($colCount[$k] ?? 0) + 1;
}
$columnar = 0;
foreach ($colCount as $n) {
    if ($n >= count($input['days'])) {
        $columnar++;
    }
}
$check('no fully-columnar subject (same period every day)', $columnar === 0);

echo "\nTeacher load: ";
$parts = [];
foreach ($result['teacher_load'] as $tid => $n) {
    $parts[] = "T{$tid}={$n}";
}
echo implode(' ', $parts) . "\n";

// Print one section's week as a human-readable sanity grid.
$secId = $input['sections'][0]['id'];
$names = [];
foreach ($input['subjects'] as $s) {
    $names[$s['id']] = $s['name'];
}
echo "\nSection {$input['sections'][0]['name']} preview:\n";
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
$grid = [];
foreach ($slots as $s) {
    if ($s['section_id'] === $secId) {
        $grid[$s['day']][$s['period']] = $names[$s['subject_id']];
    }
}
$periodNos = [];
foreach ($input['layout'] as $col) {
    if ($col['kind'] === 'period') {
        $periodNos[] = (int)$col['no'];
    }
}
printf("%-5s", '');
foreach ($periodNos as $p) {
    printf("%-16s", "P{$p}");
}
echo "\n";
foreach ($input['days'] as $d) {
    printf("%-5s", $dayNames[$d] ?? $d);
    foreach ($periodNos as $p) {
        printf("%-16s", $grid[$d][$p] ?? '—');
    }
    echo "\n";
}

// ─── Phase 2: plain-English rules → fallback intake → constraints → solver ───
require __DIR__ . '/../components/ai/timetable/ConstraintIntake.php';
require __DIR__ . '/../components/ai/timetable/TimetableDataLoader.php';

use app\components\ai\timetable\ConstraintIntake;
use app\components\ai\timetable\TimetableDataLoader;

echo "\nIntake chain (no API key — deterministic fallback):\n";

$maps = [
    'subject_names' => array_column($input['subjects'], 'name', 'id'),
    'teacher_names' => array_column($input['teachers'], 'name', 'id'),
];
$rules = 'Classes run Monday to Saturday. No more than 2 mathematics a day. '
    . 'PT twice a week in the afternoon. Library once a week. Mr. Rao only teaches mornings.';

$intake      = new ConstraintIntake();
$constraints = $intake->fallbackParse($rules, $maps);

$check('fallback intake detected Saturday working', ($constraints['days'] ?? []) === [1, 2, 3, 4, 5, 6]);

$bySubj = [];
foreach (($constraints['subjects'] ?? []) as $r) {
    $bySubj[$r['name_like']] = $r;
}
$check('fallback intake parsed "max 2 mathematics a day"', ($bySubj['mathematics']['max_per_day'] ?? null) === 2);
$check('fallback intake: day-cap sentence does NOT change weekly quota',
    !isset($bySubj['mathematics']['per_week']));
$check('fallback intake parsed "PT twice a week … afternoon"',
    (($bySubj['pt']['per_week'] ?? null) === 2) && !empty($bySubj['pt']['after_lunch_only']));
$check('fallback intake parsed "library once a week"', ($bySubj['library']['per_week'] ?? null) === 1);

$byTeach = [];
foreach (($constraints['teachers'] ?? []) as $r) {
    $byTeach[$r['name_like']] = $r;
}
$check('fallback intake parsed "Mr. Rao only teaches mornings"', !empty($byTeach['rao']['morning_only']));

// Overlay onto solver input and re-solve with the 6-day week.
$loader  = new TimetableDataLoader();
$input2  = $loader->applyConstraints($input, $constraints);
$result2 = TimetableSolver::solve($input2, 20260612);

$check('constrained solve: 6-day week applied', count($input2['days']) === 6);
$check('constrained solve: all placed, zero clashes',
    $result2['ok'] && $result2['stats']['clashes'] === 0);

$raoId = null;
foreach ($input2['teachers'] as $t) {
    if (stripos($t['name'], 'rao') !== false) {
        $raoId = $t['id'];
    }
}
$raoOk = true;
foreach ($result2['slots'] as $s) {
    if ($s['teacher_id'] === $raoId && !isset($morningPeriods[$s['period']])) {
        $raoOk = false;
    }
}
$check('constrained solve: Rao only in morning periods', $raoOk);

// ─── Phase 2c: LLM null-field sanitisation (Gemini emits explicit nulls) ───
// Live Gemini returns {"per_week": null, "max_per_day": 2} for fields the
// coordinator didn't mention. sanitize() must DROP nulls — never (int)null=0.
$ref = new ReflectionMethod(ConstraintIntake::class, 'sanitize');
$ref->setAccessible(true);
$geminiLike = [
    'days'     => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    'subjects' => [
        ['name_like' => 'Mathematics', 'per_week' => null, 'max_per_day' => 2, 'after_lunch_only' => false],
        ['name_like' => 'PET', 'per_week' => 2, 'max_per_day' => null, 'after_lunch_only' => true],
    ],
    'teachers' => [
        ['name_like' => 'Rao', 'morning_only' => true, 'max_per_day' => null],
        ['name_like' => 'Vanaja', 'morning_only' => false, 'max_per_day' => 6],
    ],
];
$clean = $ref->invoke($intake, $geminiLike);
$maths = null;
$pet = null;
foreach ($clean['subjects'] ?? [] as $s) {
    if ($s['name_like'] === 'Mathematics') $maths = $s;
    if ($s['name_like'] === 'PET') $pet = $s;
}
$check('sanitize: null per_week dropped, NOT zeroed', $maths !== null && !array_key_exists('per_week', $maths));
$check('sanitize: real max_per_day kept', $maths['max_per_day'] === 2);
$check('sanitize: PET per_week=2 + after_lunch kept', $pet['per_week'] === 2 && $pet['after_lunch_only'] === true);
$rao = null;
$vanaja = null;
foreach ($clean['teachers'] ?? [] as $t) {
    if ($t['name_like'] === 'Rao') $rao = $t;
    if ($t['name_like'] === 'Vanaja') $vanaja = $t;
}
$check('sanitize: morning_only kept, null max_per_day dropped',
    $rao['morning_only'] === true && !array_key_exists('max_per_day', $rao));
$check('sanitize: 6-day week + Vanaja cap survive', count($clean['days']) === 6 && $vanaja['max_per_day'] === 6);

// Overlaying the sanitised constraints must NOT collapse Maths to 0 periods.
$nullSafeInput = $loader->applyConstraints(SolverFixtures::realSchoolInput(), $clean);
$mathsReq = 0;
foreach ($nullSafeInput['subjects'] as $s) {
    if (stripos($s['name'], 'math') !== false) $mathsReq = $s['per_week'];
}
$check('null-safe overlay: Maths keeps its weekly quota (not 0)', $mathsReq >= 8);

// ─── Phase 3: REAL SCHOOL — General 6th, sections E/R/S/D/O, 6 days × 9 periods ───
// Modelled on an actual school's period-allocation & teacher-workload sheets:
// quotas sum to exactly 54/section (full grid), specialist teachers, cap 44/wk.
echo "\nReal-school fixture (5 sections × 54 periods, 6-day week):\n";

$rs       = SolverFixtures::realSchoolInput();
$rsResult = TimetableSolver::solve($rs, 20260613);
$rsStats  = $rsResult['stats'];
$rsSlots  = $rsResult['slots'];

echo "required={$rsStats['required']} placed={$rsStats['placed']} fill={$rsStats['fill_pct']}% clashes={$rsStats['clashes']}\n";

$check('real school: all 270 periods placed', $rsStats['placed'] === 270 && $rsStats['unplaced_count'] === 0);
$check('real school: 100% grid fill (quotas = grid size)', $rsStats['fill_pct'] === 100);
$check('real school: zero clashes', $rsStats['clashes'] === 0);

// Quotas exact for every section × subject.
$rsWant = $rsGot = [];
foreach ($rs['subjects'] as $s) {
    foreach ($rs['sections'] as $sec) {
        $rsWant[$sec['id'] . '|' . $s['id']] = (int)$s['per_week'];
    }
}
foreach ($rsSlots as $s) {
    $k = $s['section_id'] . '|' . $s['subject_id'];
    $rsGot[$k] = ($rsGot[$k] ?? 0) + 1;
}
$rsQuotaOk = true;
foreach ($rsWant as $k => $n) {
    if (($rsGot[$k] ?? 0) !== $n) {
        $rsQuotaOk = false;
    }
}
$check('real school: weekly quotas exact (TEL 7, MS 10, ENG 8 …)', $rsQuotaOk);

// Teacher consistency — the workload-sheet property.
$rsOwners = [];
foreach ($rsSlots as $s) {
    $rsOwners[$s['section_id'] . '|' . $s['subject_id']][$s['teacher_id']] = true;
}
$rsConsistent = true;
foreach ($rsOwners as $byTeacher) {
    if (count($byTeacher) > 1) {
        $rsConsistent = false;
    }
}
$check('real school: one teacher owns each section × subject all week', $rsConsistent);

// Caps: ≤44/week, ≤8/day per teacher.
$rsWeekOk = true;
foreach ($rsResult['teacher_load'] as $n) {
    if ($n > 44) {
        $rsWeekOk = false;
    }
}
$rsDayCount = [];
foreach ($rsSlots as $s) {
    $k = $s['teacher_id'] . '|' . $s['day'];
    $rsDayCount[$k] = ($rsDayCount[$k] ?? 0) + 1;
}
$rsDayOk = true;
foreach ($rsDayCount as $n) {
    if ($n > 8) {
        $rsDayOk = false;
    }
}
$check('real school: teacher caps respected (≤44/week, ≤8/day)', $rsWeekOk && $rsDayOk);

// PET & Karate after lunch only (periods 6-9 in this layout).
$rsAfterOk = true;
foreach ($rsSlots as $s) {
    if (in_array($s['subject_id'], [9, 11], true) && $s['period'] < 6) {
        $rsAfterOk = false;
    }
}
$check('real school: PET & Karate only after lunch', $rsAfterOk);

$check('real school: independent validate() clean', TimetableSolver::validate($rsSlots) === []);

// Print the generated teacher-wise workload sheet — the artifact the school
// currently maintains by hand (compare with their paper sheet).
$rsTeacherNames = array_column($rs['teachers'], 'name', 'id');
$rsSubjectNames = array_column($rs['subjects'], 'name', 'id');
$rsSectionNames = array_column($rs['sections'], 'name', 'id');
echo "\nGenerated teacher-wise workload sheet:\n";
$sheet = [];
foreach ($rsSlots as $s) {
    $sheet[$s['teacher_id']][$s['section_id']] = ($sheet[$s['teacher_id']][$s['section_id']] ?? 0) + 1;
}
foreach ($sheet as $tid => $bySec) {
    $parts = [];
    ksort($bySec);
    foreach ($bySec as $secId => $n) {
        $parts[] = $rsSectionNames[$secId] . ' ' . $n;
    }
    printf("  %-26s %s  | total %d\n", $rsTeacherNames[$tid], implode(', ', $parts), $rsResult['teacher_load'][$tid]);
}

// And section 6E's week.
echo "\nSection 6E preview:\n";
$rsGrid = [];
foreach ($rsSlots as $s) {
    if ($s['section_id'] === 61) {
        $rsGrid[$s['day']][$s['period']] = $rsSubjectNames[$s['subject_id']];
    }
}
$rsPeriodNos = [];
foreach ($rs['layout'] as $col) {
    if ($col['kind'] === 'period') {
        $rsPeriodNos[] = (int)$col['no'];
    }
}
printf("%-5s", '');
foreach ($rsPeriodNos as $p) {
    printf("%-13s", "P{$p}");
}
echo "\n";
foreach ($rs['days'] as $d) {
    printf("%-5s", $dayNames[$d] ?? $d);
    foreach ($rsPeriodNos as $p) {
        printf("%-13s", isset($rsGrid[$d][$p]) ? substr($rsGrid[$d][$p], 0, 11) : '—');
    }
    echo "\n";
}

// ─── Phase 4: feasibility diagnosis (coordinator-friendly infeasibility) ───
echo "\nFeasibility analyzer:\n";

// 4a. Default real-school request fits exactly → feasible.
$feasOk = FeasibilityAnalyzer::analyze(SolverFixtures::realSchoolInput());
$check('feasibility: default real-school request is feasible', $feasOk['ok'] === true);

// 4b. PET 6/wk (the reported screenshot) → infeasible, with a clear reason.
$over = SolverFixtures::realSchoolInput();
foreach ($over['subjects'] as &$s) {
    if ($s['name'] === 'PET') $s['per_week'] = 6;
}
unset($s);
$feasBad = FeasibilityAnalyzer::analyze($over);
$check('feasibility: PET 6/wk flagged infeasible', $feasBad['ok'] === false);

$allText = '';
foreach ($feasBad['blockers'] as $b) {
    $allText .= ' ' . $b['message'] . ' ' . $b['fix'];
}
// Grid budget: 58 requested vs 54 slots.
$check('feasibility: explains the 58-vs-54 grid over-subscription',
    strpos($allText, '54') !== false && strpos($allText, '58') !== false);
// PET teacher/afternoon shortage named with a fix.
$check('feasibility: names PET shortage + offers a fix',
    stripos($allText, 'PET') !== false && (stripos($allText, 'reduce') !== false || stripos($allText, 'add another teacher') !== false));
// Headline is a single actionable sentence.
$check('feasibility: headline is non-empty + actionable',
    strlen(FeasibilityAnalyzer::headline($feasBad)) > 30);

echo "\n  headline: " . FeasibilityAnalyzer::headline($feasBad) . "\n";

// 4c. Sanity: the infeasible request still solves clash-free for what fits.
$overSolved = TimetableSolver::solve($over, 99);
$check('feasibility: infeasible request still yields 0 clashes for placed periods',
    $overSolved['stats']['clashes'] === 0);

echo $pass ? "\nALL CHECKS PASSED\n" : "\nFAILURES DETECTED\n";
exit($pass ? 0 : 1);
