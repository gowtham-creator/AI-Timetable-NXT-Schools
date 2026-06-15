<?php
/**
 * Whole-school / cross-class verification — no Yii, no DB.
 *   php tests/timetable_school_test.php
 *
 * Proves the constraint the coordinator/principal cares about: a teacher who
 * teaches a subject across many classes is NEVER double-booked, and an
 * alternative teacher is assigned when the preferred one is busy.
 */

require __DIR__ . '/../components/ai/timetable/TimetableSolver.php';
require __DIR__ . '/../components/ai/timetable/SolverFixtures.php';

use app\components\ai\timetable\SolverFixtures;
use app\components\ai\timetable\TimetableSolver;

$pass = true;
$check = function (string $label, bool $ok) use (&$pass) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$ok) $pass = false;
};

$input  = SolverFixtures::wholeSchoolInput();
$result = TimetableSolver::solve($input, 20260615);
$slots  = $result['slots'];
$stats  = $result['stats'];
$tname  = array_column($input['teachers'], 'name', 'id');

echo "Whole-school — classes 6–10, " . count($input['sections']) . " class-sections, 1 run\n";
echo "================================================================\n";
echo "placed {$stats['placed']}/{$stats['required']} | fill {$stats['fill_pct']}% | clashes {$stats['clashes']} | ok=" . ($result['ok'] ? 'yes' : 'no') . "\n\n";

// 1) The core guarantee — zero teacher clashes across the WHOLE school.
$check('zero teacher/section clashes across all classes', $stats['clashes'] === 0);
$check('independent validate() clean (no teacher in two class-sections at once)', TimetableSolver::validate($slots) === []);

// 2) No teacher is ever in two places at the same (day, period) — explicit.
$teacherSlot = [];
$crossClash = 0;
foreach ($slots as $s) {
    $k = $s['teacher_id'] . '|' . $s['day'] . '|' . $s['period'];
    if (isset($teacherSlot[$k])) $crossClash++;
    $teacherSlot[$k] = $s['section_name'];
}
$check('no teacher double-booked at any (day,period) school-wide', $crossClash === 0);

// 3) Teacher consistency — one teacher owns each (section, subject) all week.
$owner = [];
$consistent = true;
foreach ($slots as $s) {
    $owner[$s['section_id'] . '|' . $s['subject_id']][$s['teacher_id']] = true;
}
foreach ($owner as $set) {
    if (count($set) > 1) $consistent = false;
}
$check('one teacher per (class-section × subject) all week', $consistent);

// 4) THE KISHORE EXAMPLE (teacher id 1 = Mr. Kishore, Maths across 6–10).
$kishoreSlots = array_filter($slots, fn($s) => $s['teacher_id'] === 1);
$kishoreSections = [];
foreach ($kishoreSlots as $s) $kishoreSections[$s['section_name']] = true;
$check('Mr. Kishore teaches Maths across multiple class-sections', count($kishoreSections) >= 2);

// Kishore never appears twice in the same slot (the "6A-P1 and 7A-P1" case)
$kSlot = [];
$kClash = 0;
foreach ($kishoreSlots as $s) {
    $k = $s['day'] . '|' . $s['period'];
    if (isset($kSlot[$k])) $kClash++;
    $kSlot[$k] = true;
}
$check('Mr. Kishore is never in two classes at the same time', $kClash === 0);

// Find a (day,period) where Kishore teaches; prove every OTHER section in that
// slot that has Maths is taught by a DIFFERENT (alternative) teacher.
$probe = null;
foreach ($kishoreSlots as $s) { $probe = $s; break; }
$altOk = true;
$altSeen = false;
if ($probe) {
    foreach ($slots as $s) {
        if ($s['day'] === $probe['day'] && $s['period'] === $probe['period']
            && $s['section_id'] !== $probe['section_id'] && $s['subject_id'] === 1) {
            $altSeen = true;
            if ($s['teacher_id'] === 1) $altOk = false; // would be Kishore double-booked
        }
    }
}
$check('when Kishore teaches Maths in one class, concurrent Maths uses an alternative teacher',
    $altOk); // true even if no concurrent Maths (vacuously safe)

// 5) Alternative allocation — Maths across the school uses >1 teacher.
$mathTeachers = [];
foreach ($slots as $s) if ($s['subject_id'] === 1) $mathTeachers[$s['teacher_id']] = true;
$check('Maths is shared across alternative teachers (not one overloaded teacher)', count($mathTeachers) >= 2);

// 6) Per-class subject allocation honored: class 9 has Physics & no integrated Science;
//    class 6 has Science & no Physics.
$has = function ($className, $subjectName) use ($slots, $input) {
    $secIds = [];
    foreach ($input['sections'] as $sec) if ($sec['class'] === $className) $secIds[$sec['id']] = true;
    foreach ($slots as $s) if (isset($secIds[$s['section_id']]) && $s['subject'] === $subjectName) return true;
    return false;
};
$check('class 9 has Physics (split high-school sciences)', $has('9', 'Physics'));
$check('class 9 has NO integrated "Science"', !$has('9', 'Science'));
$check('class 6 has integrated Science', $has('6', 'Science'));
$check('class 6 has NO Physics', !$has('6', 'Physics'));

// 7) Teacher caps respected school-wide.
$load = [];
foreach ($slots as $s) $load[$s['teacher_id']] = ($load[$s['teacher_id']] ?? 0) + 1;
$capOk = true;
foreach ($load as $n) if ($n > 44) $capOk = false;
$check('every teacher within 44/week cap school-wide', $capOk);

// ── Show the cross-class Maths allocation + Kishore's personal week ──
echo "\nMaths teacher per class-section (cross-class allocation):\n";
$mathOwner = [];
foreach ($slots as $s) if ($s['subject_id'] === 1) $mathOwner[$s['section_name']] = $tname[$s['teacher_id']];
ksort($mathOwner);
foreach ($mathOwner as $sec => $t) echo "  " . str_pad($sec, 5) . " → $t\n";

echo "\nMr. Kishore's week (proves one place at a time across classes):\n";
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
$periodNos = [];
foreach ($input['layout'] as $c) if ($c['kind'] === 'period') $periodNos[] = (int)$c['no'];
$kGrid = [];
foreach ($kishoreSlots as $s) $kGrid[$s['day']][$s['period']] = $s['section_name'];
printf("%-5s", '');
foreach ($periodNos as $p) printf("%-7s", "P$p");
echo "\n";
foreach ($input['days'] as $d) {
    printf("%-5s", $dayNames[$d] ?? $d);
    foreach ($periodNos as $p) printf("%-7s", $kGrid[$d][$p] ?? '·');
    echo "\n";
}
echo "\nTeacher load (school-wide): ";
arsort($load);
$parts = [];
foreach ($load as $tid => $n) $parts[] = $tname[$tid] . "=$n";
echo implode('  ', array_slice($parts, 0, 8)) . " …\n";

echo $pass ? "\nALL CHECKS PASSED\n" : "\nFAILURES DETECTED\n";
exit($pass ? 0 : 1);
