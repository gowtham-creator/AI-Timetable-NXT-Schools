<?php
/**
 * Single-class intake guarantees — no Yii, no DB.
 *
 *   php tests/timetable_class_test.php
 *
 * Proves the hard product requirements for one class with multiple sections:
 *  (a) no teacher double-booked across a class's sections
 *  (b) each section's timetable is DISTINCT (no identical copy)
 *  (c) a teacher teaching the same subject across 3 sections sits at different slots
 *  (d) weekly quotas met per section
 *  (e) the NO-sections small-school path produces one valid timetable
 *  (f) the engine accepts any minimal, contract-conformant universe
 * Exits non-zero on any failed invariant.
 */

require __DIR__ . '/../components/ai/timetable/TimetableSolver.php';
require __DIR__ . '/../components/ai/timetable/SolverFixtures.php';
require __DIR__ . '/../components/ai/timetable/FeasibilityAnalyzer.php';

use app\components\ai\timetable\SolverFixtures;
use app\components\ai\timetable\TimetableSolver;

$SEED = 20260629;
$pass = true;
$check = function (string $label, bool $ok) use (&$pass) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$ok) {
        $pass = false;
    }
};

/** {day:period => subject_id} signature for one section, order-independent. */
$signature = function (array $slots, int $sectionId): string {
    $cells = [];
    foreach ($slots as $s) {
        if ($s['section_id'] === $sectionId) {
            $cells[$s['day'] . ':' . $s['period']] = $s['subject_id'];
        }
    }
    ksort($cells);
    return json_encode($cells);
};

echo "AI Timetable — single-class intake guarantees\n";
echo "=============================================\n\n";

// ─── (a) no teacher double-booked across one class's sections ────────────────
echo "(a) no teacher double-booked within a class (realSchoolInput, 5 sections)\n";
$res = TimetableSolver::solve(SolverFixtures::realSchoolInput(), $SEED);
$busy = [];
$dup = false;
foreach ($res['slots'] as $s) {
    $k = $s['teacher_id'] . '|' . $s['day'] . '|' . $s['period'];
    if (isset($busy[$k])) {
        $dup = true;
    }
    $busy[$k] = true;
}
$check('no (teacher, day, period) used twice', !$dup);
$check('validate() reports zero clashes', TimetableSolver::validate($res['slots']) === []);
$check('solver reports ok', $res['ok'] === true);

// ─── (b) each section's grid is distinct (clone-risk fixture) ────────────────
echo "\n(b) distinct per-section grids (cloneRiskInput — disjoint pools, worst case)\n";
$res = TimetableSolver::solve(SolverFixtures::cloneRiskInput(), $SEED);
$sigA = $signature($res['slots'], 71);
$sigB = $signature($res['slots'], 72);
$check('7A and 7B grids are NOT identical', $sigA !== $sigB && $sigA !== '[]' && $sigB !== '[]');
$check('still zero clashes with the diversity term', TimetableSolver::validate($res['slots']) === []);

// ─── (c) shared teacher, same subject, 3 sections → distinct slots ──────────
echo "\n(c) shared Maths teacher across 8A/8B/8C sits at different slots\n";
$res = TimetableSolver::solve(SolverFixtures::tripleSectionInput(), $SEED);
$mathsTeacherCells = [];
$mathsOwners = [];
foreach ($res['slots'] as $s) {
    if ((int)$s['subject_id'] === 1) { // Mathematics
        $mathsOwners[$s['teacher_id']] = true;
        $mathsTeacherCells[] = $s['day'] . ':' . $s['period'];
    }
}
$check('Maths is owned by the single shared teacher in every section', count($mathsOwners) === 1);
$check('every Maths (day,period) cell is unique (no double-booking)',
    count($mathsTeacherCells) === count(array_unique($mathsTeacherCells)) && count($mathsTeacherCells) > 0);

// ─── (d) weekly quotas met per section ──────────────────────────────────────
echo "\n(d) weekly quota met per (section, subject) — realSchoolInput\n";
$input = SolverFixtures::realSchoolInput();
$res = TimetableSolver::solve($input, $SEED);
$placed = [];
foreach ($res['slots'] as $s) {
    $placed[$s['section_id']][$s['subject_id']] = ($placed[$s['section_id']][$s['subject_id']] ?? 0) + 1;
}
$quotaOk = true;
foreach ($input['sections'] as $sec) {
    foreach ($input['subjects'] as $sub) {
        if (($placed[$sec['id']][$sub['id']] ?? 0) !== (int)$sub['per_week']) {
            $quotaOk = false;
        }
    }
}
$check('every section gets exactly its per_week for every subject', $quotaOk);
$check('nothing unplaced', $res['stats']['unplaced_count'] === 0);

// ─── (e) no-section small school → one valid timetable ──────────────────────
echo "\n(e) no-sections small school produces one valid timetable\n";
$input = SolverFixtures::noSectionInput();
$res = TimetableSolver::solve($input, $SEED);
$secIds = [];
foreach ($res['slots'] as $s) {
    $secIds[$s['section_id']] = true;
}
$check('exactly one (synthetic whole-class) section in the output', count($secIds) === 1);
$check('synthetic section id is the negative sentinel', isset($secIds[-9]));
$check('solver reports ok + zero clashes', $res['ok'] === true && TimetableSolver::validate($res['slots']) === []);

// ─── (f) minimal universal school satisfies the data contract ───────────────
echo "\n(f) loader universality — minimal contract-conformant school\n";
$input = SolverFixtures::minimalSchoolInput();
$teacherIds = array_column($input['teachers'], 'id');
$contractOk = true;
foreach ($input['sections'] as $sec) {
    if (empty($sec['subjects'])) {
        $contractOk = false;
    }
    foreach (($sec['subjects'] ?? []) as $sub) {
        if (empty($sub['teacher_ids'])) {
            $contractOk = false;
        }
        foreach ($sub['teacher_ids'] as $tid) {
            if (!in_array($tid, $teacherIds, true)) {
                $contractOk = false;
            }
        }
    }
}
foreach (($input['teacher_map'] ?? []) as $bySub) {
    foreach ((array)$bySub as $tid) {
        if (!in_array((int)$tid, $teacherIds, true)) {
            $contractOk = false;
        }
    }
}
$res = TimetableSolver::solve($input, $SEED);
$check('input satisfies the DATA CONTRACT (sections→subjects→teacher_ids→teachers)', $contractOk);
$check('minimal school solves cleanly', $res['ok'] === true && $res['stats']['clashes'] === 0);

echo "\n" . ($pass ? "ALL CHECKS PASSED\n" : "SOME CHECKS FAILED\n");
exit($pass ? 0 : 1);
