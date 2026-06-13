<?php

namespace app\commands;

use app\components\ai\timetable\SolverFixtures;
use app\components\ai\timetable\TimetableSolver;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * AI Timetable console tools.
 *
 *   php yii timetable/solver-test                      — offline engine verification (no DB)
 *   php yii timetable/generate --campus=1 --class=5 \
 *       --year=3 [--sections=11,12] [--rules="..."]    — build a draft run
 *   php yii timetable/publish --run=42                 — publish a draft run
 */
class TimetableController extends Controller
{
    public $campus;
    public $class;
    public $year;
    public $sections = '';
    public $rules = '';
    public $run;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'generate' => ['campus', 'class', 'year', 'sections', 'rules'],
            'publish'  => ['run'],
            default    => [],
        });
    }

    /**
     * Offline verification of the solver engine. No database required.
     * Exits non-zero on any failed invariant — safe for CI.
     */
    public function actionSolverTest(): int
    {
        $input  = SolverFixtures::demoInput();
        $result = TimetableSolver::solve($input, 20260611);

        $stats = $result['stats'];
        $slots = $result['slots'];
        $pass  = true;
        $check = function (string $label, bool $ok) use (&$pass) {
            $this->stdout(($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n");
            if (!$ok) {
                $pass = false;
            }
        };

        $this->stdout("AI Timetable — solver verification\n");
        $this->stdout("===================================\n");
        $this->stdout("required={$stats['required']} placed={$stats['placed']} fill={$stats['fill_pct']}% "
            . "clashes={$stats['clashes']} seed={$stats['seed']}\n\n");

        // 1. Everything placed, nothing clashing.
        $check('all required periods placed', $stats['unplaced_count'] === 0);
        $check('zero teacher/section clashes', $stats['clashes'] === 0);
        $check('solver reports ok', $result['ok'] === true);

        // 2. Quotas exact per section × subject.
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
            }
        }
        $check('weekly quota exact for every section × subject', $quotaOk);

        // 3. After-lunch subjects (PT) never in the morning.
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

        // 4. Morning-only teachers never teach after lunch.
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

        // 5. Subject max_per_day respected.
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

        // 6. Teacher weekly cap respected.
        $loadOk = true;
        foreach ($result['teacher_load'] as $tid => $n) {
            if ($n > 30) {
                $loadOk = false;
            }
        }
        $check('teacher weekly load within cap', $loadOk);

        // 7. Re-validate from scratch (defence in depth).
        $check('independent validate() finds no clashes', TimetableSolver::validate($slots) === []);

        $this->stdout("\nTeacher load: ");
        $parts = [];
        foreach ($result['teacher_load'] as $tid => $n) {
            $parts[] = "T{$tid}={$n}";
        }
        $this->stdout(implode(' ', $parts) . "\n");

        $this->stdout($pass ? "\nALL CHECKS PASSED\n" : "\nFAILURES DETECTED\n");
        return $pass ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Generate a draft timetable run for a class (all sections by default).
     */
    public function actionGenerate(): int
    {
        if (!$this->campus || !$this->class || !$this->year) {
            $this->stderr("Required: --campus=ID --class=ID --year=ACADEMIC_YEAR_ID [--sections=ID,ID] [--rules=\"...\"]\n");
            return ExitCode::USAGE;
        }
        /** @var \app\components\ai\TimetableComposer $composer */
        $composer = \Yii::createObject(\app\components\ai\TimetableComposer::class);
        $sectionIds = $this->sections !== ''
            ? array_map('intval', explode(',', $this->sections))
            : [];

        $run = $composer->generate(
            (int)$this->campus,
            (int)$this->class,
            (int)$this->year,
            $sectionIds,
            (string)$this->rules,
            null
        );

        $this->stdout("Run #{$run['run_id']} status={$run['status']} "
            . "placed={$run['stats']['placed']}/{$run['stats']['required']} "
            . "clashes={$run['stats']['clashes']}\n");
        if (!empty($run['narrative'])) {
            $this->stdout("\n" . $run['narrative'] . "\n");
        }
        return $run['status'] === 'failed' ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Publish a draft run into subject_timetable (approval step).
     */
    public function actionPublish(): int
    {
        if (!$this->run) {
            $this->stderr("Required: --run=RUN_ID\n");
            return ExitCode::USAGE;
        }
        /** @var \app\components\ai\TimetableComposer $composer */
        $composer = \Yii::createObject(\app\components\ai\TimetableComposer::class);
        $out = $composer->publish((int)$this->run, null);
        $this->stdout("Run #{$this->run}: {$out['message']} (inserted={$out['inserted']}, archived={$out['archived']})\n");
        return $out['ok'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
