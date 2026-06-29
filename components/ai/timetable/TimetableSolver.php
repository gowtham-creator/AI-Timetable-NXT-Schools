<?php

namespace app\components\ai\timetable;

/**
 * TimetableSolver — constraint-satisfaction engine for weekly school timetables.
 *
 * Framework-free (no Yii imports) so it can be unit-tested offline and reused
 * from console commands, web controllers, or queue workers.
 *
 * Algorithm: scored greedy placement with single-depth eviction backtracking,
 * multiple seeded attempts, best-scoring attempt wins. Ported from the engine
 * proven in the NXT demo (0 clashes, 100% sections, ~95% fill).
 *
 * HARD constraints (never violated):
 *   - a section cell (day × period) holds at most one subject
 *   - a teacher is in at most one section per (day × period) across the whole run
 *   - TEACHER CONSISTENCY: one teacher owns a (section × subject) for the whole
 *     week — exactly how real schools allocate (the "teacher-wise workload"
 *     sheet). Pre-assignments can be supplied via input['teacher_map'];
 *     otherwise the solver picks once per (section, subject) and locks it.
 *   - teacher availability: morning_only, explicit unavailable list
 *   - teacher max_per_day / max_per_week
 *   - subject max_per_day per section
 *   - after_lunch_only subjects (e.g. PT/Games) only after the lunch column
 *
 * SOFT preferences (scored, minimised):
 *   - anti-column: avoid the same subject sitting in the same period every day
 *   - day-spread: spread a subject's periods across different days
 *   - back-to-back: avoid the same subject in adjacent periods of one day
 *   - teacher load balance across the week
 *
 * Input shape — see SolverFixtures::demoInput() for a complete example:
 * [
 *   'days'        => [1,2,3,4,5,6],            // SubjectTimetable day ids (1=Mon)
 *   'layout'      => [ ['kind'=>'assembly','label'=>..,'time_from'=>'08:00','time_to'=>'08:20'],
 *                      ['kind'=>'period','no'=>1,'time_from'=>..,'time_to'=>..], ...,
 *                      ['kind'=>'break'|'lunch'|'activity', ...] ],
 *   'sections'    => [ ['id'=>12,'name'=>'6A','class'=>'6'], ... ],
 *   'subjects'    => [ ['id'=>5,'sgs_id'=>77,'name'=>'Mathematics','per_week'=>10,
 *                       'max_per_day'=>2,'after_lunch_only'=>false,'teacher_ids'=>[3,9]], ... ],
 *   'teachers'    => [ ['id'=>3,'name'=>'..','morning_only'=>false,'max_per_day'=>8,
 *                       'max_per_week'=>44,'unavailable'=>[['day'=>5,'period'=>2]]], ... ],
 *   'teacher_map' => [ 12 => [5 => 3] ],       // optional: section 12, subject 5 → teacher 3
 * ]
 *
 * WHOLE-SCHOOL / CROSS-CLASS: pass every class-section as one flat 'sections'
 * list and solve once. Each section may override the global 'subjects' with its
 * own per-class list (incl. that class's teacher pools) via section['subjects'].
 * Because 'teachers' is a single school-wide pool and teacher availability is
 * tracked globally per (day,period), a teacher placed in 6A-P1 can NEVER be
 * placed in 7A-P1 — the solver assigns an alternative teacher (or another slot)
 * automatically. Teacher consistency locks ONE teacher per (section,subject),
 * so a teacher may own a subject across several sections but is never
 * double-booked at the same time.
 */
class TimetableSolver
{
    public const ATTEMPTS = 5;

    /** Soft-score weights (higher = avoided harder). */
    public const W_ANTI_COLUMN  = 60;
    public const W_DAY_SPREAD   = 35;
    public const W_BACK_TO_BACK = 55;
    public const W_LOAD_BALANCE = 20;
    public const W_JITTER       = 3;
    /** Cross-section diversity: nudge sibling sections to fan a shared subject
     *  across different (day,period) cells so two sections never come out
     *  byte-identical. Small — only breaks otherwise-equal ties; never overrides
     *  anti-column / back-to-back / day-spread and never touches a HARD constraint. */
    public const W_SECTION_DIVERSITY = 8;

    /**
     * Solve a weekly timetable for all sections at once.
     *
     * @param array    $input    see class doc
     * @param int|null $seedBase fixed seed for reproducible output (tests)
     * @return array{ok:bool,slots:array,structural:array,stats:array,teacher_load:array,teacher_map:array}
     */
    public static function solve(array $input, ?int $seedBase = null): array
    {
        $days       = array_values($input['days']);
        $periods    = self::periodColumns($input['layout']);
        $sections   = array_values($input['sections']);
        $subjects   = array_values($input['subjects']);
        $teachers   = self::indexById($input['teachers']);
        $teacherMap = $input['teacher_map'] ?? [];

        $best = null;
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            // Last attempt is deterministic (jitter off) as a safety net.
            $seed   = $seedBase !== null ? $seedBase + $attempt : random_int(1, PHP_INT_MAX >> 1);
            $jitter = $attempt < self::ATTEMPTS - 1;
            $result = self::attempt($days, $periods, $sections, $subjects, $teachers, $teacherMap, $seed, $jitter);
            if ($best === null || $result['score'] > $best['score']) {
                $best = $result;
            }
            if ($best['stats']['unplaced_count'] === 0 && $attempt >= 1) {
                break; // perfect placement found; no need to burn attempts
            }
        }

        $structural = self::structuralRows($input['layout'], $sections, $days);
        $clashes    = self::validate($best['slots']);

        return [
            'ok'           => $best['stats']['unplaced_count'] === 0 && count($clashes) === 0,
            'slots'        => $best['slots'],
            'structural'   => $structural,
            'stats'        => $best['stats'] + ['clashes' => count($clashes), 'clash_list' => $clashes],
            'teacher_load' => $best['teacher_load'],
            'teacher_map'  => $best['teacher_map'],
        ];
    }

    /**
     * Re-validate a slot list from scratch. Returns clash descriptions ([] = clean).
     * Also enforces teacher consistency: a (section, subject) must be owned by
     * exactly one teacher across the week — like a real workload sheet.
     * Used by tests and as the publish-time safety net.
     */
    public static function validate(array $slots): array
    {
        $clashes = [];
        $cell = $busy = $owner = [];
        foreach ($slots as $s) {
            $ck = $s['section_id'] . '|' . $s['day'] . '|' . $s['period'];
            if (isset($cell[$ck])) {
                $clashes[] = "Section {$s['section_id']} double-filled day {$s['day']} period {$s['period']}";
            }
            $cell[$ck] = true;

            $tk = $s['teacher_id'] . '|' . $s['day'] . '|' . $s['period'];
            if (isset($busy[$tk])) {
                $clashes[] = "Teacher {$s['teacher_id']} double-booked day {$s['day']} period {$s['period']}";
            }
            $busy[$tk] = true;

            if (isset($s['subject_id'])) {
                $ok = $s['section_id'] . '|' . $s['subject_id'];
                if (isset($owner[$ok]) && $owner[$ok] !== $s['teacher_id']) {
                    $clashes[] = "Section {$s['section_id']} subject {$s['subject_id']} split between teachers {$owner[$ok]} and {$s['teacher_id']}";
                }
                $owner[$ok] = $s['teacher_id'];
            }
        }
        return $clashes;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** One seeded attempt. */
    private static function attempt(
        array $days,
        array $periods,
        array $sections,
        array $subjects,
        array $teachers,
        array $teacherMap,
        int $seed,
        bool $jitter
    ): array {
        mt_srand($seed);

        $periodNos   = array_keys($periods);
        $totalCells  = count($sections) * count($days) * count($periodNos);

        // State.
        $grid        = []; // [secId][day][period] => slot array
        $teacherBusy = []; // [tid][day][period]   => true
        $teacherDay  = []; // [tid][day]           => count
        $teacherWeek = []; // [tid]                => count
        $subjDay     = []; // [secId][subId][day]  => count
        $subjCol     = []; // [secId][subId][per]  => count (anti-column)
        $globalCol   = []; // [subId][day][per]    => count across ALL sections (cross-section diversity)
        $lock        = []; // [secId][subId]       => tid (teacher consistency)
        $lockCount   = []; // [secId][subId]       => placed periods under the lock

        foreach ($teacherMap as $secId => $bySubject) {
            foreach ((array)$bySubject as $subId => $tid) {
                $lock[(int)$secId][(int)$subId]      = (int)$tid;
                $lockCount[(int)$secId][(int)$subId] = PHP_INT_MAX; // external locks never expire
            }
        }

        // Demand: one work item per required period, hardest-first.
        // Each section may carry its OWN subjects[] (heterogeneous classes —
        // e.g. class 6 integrated Science vs class 9 Physics/Chem/Bio, with
        // per-class teacher pools); otherwise it uses the global $subjects.
        // Teachers are a single school-wide pool, so a teacher booked in one
        // class-section can never be placed in another at the same time.
        $items = [];
        foreach ($sections as $sec) {
            $secSubjects = (isset($sec['subjects']) && is_array($sec['subjects']) && $sec['subjects'] !== [])
                ? $sec['subjects'] : $subjects;
            foreach ($secSubjects as $sub) {
                for ($i = 0; $i < (int)$sub['per_week']; $i++) {
                    $items[] = ['sec' => $sec, 'sub' => $sub];
                }
            }
        }
        usort($items, function ($a, $b) use ($jitter) {
            $ta = self::tightness($a['sub']);
            $tb = self::tightness($b['sub']);
            if ($ta !== $tb) {
                return $tb <=> $ta;
            }
            return $jitter ? (mt_rand(0, 2) - 1) : 0;
        });

        $slots = $unplaced = [];

        foreach ($items as $item) {
            $placed = self::place($item['sec'], $item['sub'], $days, $periods, $teachers,
                $grid, $teacherBusy, $teacherDay, $teacherWeek, $subjDay, $subjCol, $globalCol, $lock, $lockCount, $slots, $jitter);

            if (!$placed) {
                // Single-depth eviction: move an existing slot elsewhere to free a cell.
                $placed = self::evictAndPlace($item['sec'], $item['sub'], $days, $periods, $teachers,
                    $grid, $teacherBusy, $teacherDay, $teacherWeek, $subjDay, $subjCol, $globalCol, $lock, $lockCount, $slots, $jitter);
            }
            if (!$placed) {
                $unplaced[] = ['section_id' => $item['sec']['id'], 'subject' => $item['sub']['name']];
            }
        }

        $softPenalty = self::totalSoftPenalty($slots);
        $stats = [
            'required'       => count($items),
            'placed'         => count($slots),
            'unplaced_count' => count($unplaced),
            'unplaced'       => $unplaced,
            'total_cells'    => $totalCells,
            'fill_pct'       => $totalCells > 0 ? (int)round(count($slots) / $totalCells * 100) : 0,
            'seed'           => $seed,
        ];

        $load = [];
        foreach ($teacherWeek as $tid => $n) {
            $load[$tid] = $n;
        }

        // Final (section → subject → teacher) ownership map — workload-sheet style.
        $finalMap = [];
        foreach ($lock as $secId => $bySub) {
            foreach ($bySub as $subId => $tid) {
                $count = $lockCount[$secId][$subId] ?? 0;
                if ($count > 0) {
                    $finalMap[$secId][$subId] = $tid;
                }
            }
        }

        return [
            'slots'        => $slots,
            'stats'        => $stats,
            'teacher_load' => $load,
            'teacher_map'  => $finalMap,
            'score'        => count($slots) * 1000 - $softPenalty,
        ];
    }

    /** Try to place one (section, subject) instance into its best-scoring cell. */
    private static function place(
        array $sec, array $sub, array $days, array $periods, array $teachers,
        array &$grid, array &$teacherBusy, array &$teacherDay, array &$teacherWeek,
        array &$subjDay, array &$subjCol, array &$globalCol, array &$lock, array &$lockCount, array &$slots, bool $jitter
    ): bool {
        $bestKey = null;
        $bestCost = PHP_INT_MAX;
        $bestTid = null;

        foreach ($days as $day) {
            foreach ($periods as $pNo => $p) {
                if (isset($grid[$sec['id']][$day][$pNo])) {
                    continue;
                }
                if (!empty($sub['after_lunch_only']) && $p['morning']) {
                    continue;
                }
                if ((int)($sub['max_per_day'] ?? 2) <= ($subjDay[$sec['id']][$sub['id']][$day] ?? 0)) {
                    continue;
                }
                $tid = self::freeTeacher($sec, $sub, $teachers, $day, $pNo, $p,
                    $teacherBusy, $teacherDay, $teacherWeek, $lock);
                if ($tid === null) {
                    continue;
                }

                $cost = 0;
                if (($subjCol[$sec['id']][$sub['id']][$pNo] ?? 0) > 0) {
                    $cost += self::W_ANTI_COLUMN * $subjCol[$sec['id']][$sub['id']][$pNo];
                }
                $cost += self::W_DAY_SPREAD * ($subjDay[$sec['id']][$sub['id']][$day] ?? 0);
                $prev = $grid[$sec['id']][$day][$pNo - 1]['subject_id'] ?? null;
                $next = $grid[$sec['id']][$day][$pNo + 1]['subject_id'] ?? null;
                if ($prev === $sub['id'] || $next === $sub['id']) {
                    $cost += self::W_BACK_TO_BACK;
                }
                $cost += self::W_LOAD_BALANCE * (int)(($teacherWeek[$tid] ?? 0) / 5);
                // Cross-section diversity: penalise reusing a (day,period) another
                // section already gave this subject, so sibling grids diverge.
                $cost += self::W_SECTION_DIVERSITY * ($globalCol[$sub['id']][$day][$pNo] ?? 0);
                if ($jitter) {
                    $cost += mt_rand(0, self::W_JITTER);
                }

                if ($cost < $bestCost) {
                    $bestCost = $cost;
                    $bestKey  = [$day, $pNo];
                    $bestTid  = $tid;
                }
            }
        }

        if ($bestKey === null) {
            return false;
        }
        [$day, $pNo] = $bestKey;
        self::commit($sec, $sub, $bestTid, $day, $pNo, $periods[$pNo],
            $grid, $teacherBusy, $teacherDay, $teacherWeek, $subjDay, $subjCol, $globalCol, $lock, $lockCount, $slots);
        return true;
    }

    /** Eviction backtracking: relocate one placed slot to free a viable cell. */
    private static function evictAndPlace(
        array $sec, array $sub, array $days, array $periods, array $teachers,
        array &$grid, array &$teacherBusy, array &$teacherDay, array &$teacherWeek,
        array &$subjDay, array &$subjCol, array &$globalCol, array &$lock, array &$lockCount, array &$slots, bool $jitter
    ): bool {
        $tries = 0;
        foreach ($days as $day) {
            foreach ($periods as $pNo => $p) {
                if (++$tries > 60) {
                    return false;
                }
                if (!empty($sub['after_lunch_only']) && $p['morning']) {
                    continue;
                }
                $victim = $grid[$sec['id']][$day][$pNo] ?? null;
                if ($victim === null || $victim['subject_id'] === $sub['id']) {
                    continue;
                }
                if ((int)($sub['max_per_day'] ?? 2) <= ($subjDay[$sec['id']][$sub['id']][$day] ?? 0)) {
                    continue;
                }
                // Would WE have a teacher here once the victim leaves?
                self::uncommit($victim, $grid, $teacherBusy, $teacherDay, $teacherWeek, $subjDay, $subjCol, $globalCol, $lock, $lockCount, $slots);
                $tid = self::freeTeacher($sec, $sub, $teachers, $day, $pNo, $p,
                    $teacherBusy, $teacherDay, $teacherWeek, $lock);
                if ($tid === null) {
                    self::recommit($victim, $grid, $teacherBusy, $teacherDay, $teacherWeek, $subjDay, $subjCol, $globalCol, $lock, $lockCount, $slots);
                    continue;
                }
                // Place us, then try to re-home the victim somewhere else.
                self::commit($sec, $sub, $tid, $day, $pNo, $p,
                    $grid, $teacherBusy, $teacherDay, $teacherWeek, $subjDay, $subjCol, $globalCol, $lock, $lockCount, $slots);

                $victimSub = $victim['_sub'];
                $rehomed = self::place($sec, $victimSub, $days, $periods, $teachers,
                    $grid, $teacherBusy, $teacherDay, $teacherWeek, $subjDay, $subjCol, $globalCol, $lock, $lockCount, $slots, $jitter);
                if ($rehomed) {
                    return true;
                }
                // Undo everything: remove us, restore victim.
                $ours = end($slots);
                self::uncommit($ours, $grid, $teacherBusy, $teacherDay, $teacherWeek, $subjDay, $subjCol, $globalCol, $lock, $lockCount, $slots);
                self::recommit($victim, $grid, $teacherBusy, $teacherDay, $teacherWeek, $subjDay, $subjCol, $globalCol, $lock, $lockCount, $slots);
            }
        }
        return false;
    }

    /**
     * Teacher for (section, subject) at (day, period).
     *
     * Teacher consistency: if the (section, subject) already has an owner —
     * supplied via input['teacher_map'] or locked at first placement — ONLY
     * that teacher is considered. Otherwise the least-loaded competent teacher
     * is chosen, and commit() locks them in for the rest of the week.
     */
    private static function freeTeacher(
        array $sec, array $sub, array $teachers, int $day, int $pNo, array $p,
        array $teacherBusy, array $teacherDay, array $teacherWeek, array $lock
    ): ?int {
        $lockedTid  = $lock[$sec['id']][$sub['id']] ?? null;
        $candidates = $lockedTid !== null ? [$lockedTid] : $sub['teacher_ids'];

        $bestTid = null;
        $bestLoad = PHP_INT_MAX;
        foreach ($candidates as $tid) {
            $t = $teachers[$tid] ?? null;
            if ($t === null) {
                continue;
            }
            if (isset($teacherBusy[$tid][$day][$pNo])) {
                continue;
            }
            if (!empty($t['morning_only']) && !$p['morning']) {
                continue;
            }
            if (($teacherDay[$tid][$day] ?? 0) >= (int)($t['max_per_day'] ?? 8)) {
                continue;
            }
            if (($teacherWeek[$tid] ?? 0) >= (int)($t['max_per_week'] ?? 44)) {
                continue;
            }
            foreach (($t['unavailable'] ?? []) as $u) {
                if ((int)$u['day'] === $day && (int)$u['period'] === $pNo) {
                    continue 2;
                }
            }
            // Prefer least-loaded competent teacher → balances load naturally.
            $load = $teacherWeek[$tid] ?? 0;
            if ($load < $bestLoad) {
                $bestLoad = $load;
                $bestTid = $tid;
            }
        }
        return $bestTid;
    }

    private static function commit(
        array $sec, array $sub, int $tid, int $day, int $pNo, array $p,
        array &$grid, array &$teacherBusy, array &$teacherDay, array &$teacherWeek,
        array &$subjDay, array &$subjCol, array &$globalCol, array &$lock, array &$lockCount, array &$slots
    ): void {
        $slot = [
            'section_id'   => $sec['id'],
            'section_name' => $sec['name'],
            'day'          => $day,
            'period'       => $pNo,
            'subject_id'   => $sub['id'],
            'sgs_id'       => $sub['sgs_id'] ?? null,
            'subject'      => $sub['name'],
            'teacher_id'   => $tid,
            'time_from'    => $p['time_from'],
            'time_to'      => $p['time_to'],
            '_sub'         => $sub, // kept for eviction re-homing; stripped by callers
        ];
        $grid[$sec['id']][$day][$pNo] = $slot;
        $teacherBusy[$tid][$day][$pNo] = true;
        $teacherDay[$tid][$day] = ($teacherDay[$tid][$day] ?? 0) + 1;
        $teacherWeek[$tid] = ($teacherWeek[$tid] ?? 0) + 1;
        $subjDay[$sec['id']][$sub['id']][$day] = ($subjDay[$sec['id']][$sub['id']][$day] ?? 0) + 1;
        $subjCol[$sec['id']][$sub['id']][$pNo] = ($subjCol[$sec['id']][$sub['id']][$pNo] ?? 0) + 1;
        $globalCol[$sub['id']][$day][$pNo] = ($globalCol[$sub['id']][$day][$pNo] ?? 0) + 1;

        // Teacher consistency: first placement owns the (section, subject).
        if (!isset($lock[$sec['id']][$sub['id']])) {
            $lock[$sec['id']][$sub['id']]      = $tid;
            $lockCount[$sec['id']][$sub['id']] = 0;
        }
        if ($lockCount[$sec['id']][$sub['id']] !== PHP_INT_MAX) {
            $lockCount[$sec['id']][$sub['id']]++;
        }

        $slots[] = $slot;
    }

    private static function uncommit(
        array $slot,
        array &$grid, array &$teacherBusy, array &$teacherDay, array &$teacherWeek,
        array &$subjDay, array &$subjCol, array &$globalCol, array &$lock, array &$lockCount, array &$slots
    ): void {
        unset($grid[$slot['section_id']][$slot['day']][$slot['period']]);
        unset($teacherBusy[$slot['teacher_id']][$slot['day']][$slot['period']]);
        $teacherDay[$slot['teacher_id']][$slot['day']]--;
        $teacherWeek[$slot['teacher_id']]--;
        $subjDay[$slot['section_id']][$slot['subject_id']][$slot['day']]--;
        $subjCol[$slot['section_id']][$slot['subject_id']][$slot['period']]--;
        if (isset($globalCol[$slot['subject_id']][$slot['day']][$slot['period']])) {
            $globalCol[$slot['subject_id']][$slot['day']][$slot['period']]--;
        }

        // Release the ownership lock when the last placed period is removed
        // (external teacher_map locks are permanent: count = PHP_INT_MAX).
        if (isset($lockCount[$slot['section_id']][$slot['subject_id']])
            && $lockCount[$slot['section_id']][$slot['subject_id']] !== PHP_INT_MAX) {
            $lockCount[$slot['section_id']][$slot['subject_id']]--;
            if ($lockCount[$slot['section_id']][$slot['subject_id']] <= 0) {
                unset($lockCount[$slot['section_id']][$slot['subject_id']]);
                unset($lock[$slot['section_id']][$slot['subject_id']]);
            }
        }

        foreach ($slots as $i => $s) {
            if ($s['section_id'] === $slot['section_id'] && $s['day'] === $slot['day'] && $s['period'] === $slot['period']) {
                array_splice($slots, $i, 1);
                break;
            }
        }
    }

    private static function recommit(
        array $slot,
        array &$grid, array &$teacherBusy, array &$teacherDay, array &$teacherWeek,
        array &$subjDay, array &$subjCol, array &$globalCol, array &$lock, array &$lockCount, array &$slots
    ): void {
        $grid[$slot['section_id']][$slot['day']][$slot['period']] = $slot;
        $teacherBusy[$slot['teacher_id']][$slot['day']][$slot['period']] = true;
        $teacherDay[$slot['teacher_id']][$slot['day']] = ($teacherDay[$slot['teacher_id']][$slot['day']] ?? 0) + 1;
        $teacherWeek[$slot['teacher_id']] = ($teacherWeek[$slot['teacher_id']] ?? 0) + 1;
        $subjDay[$slot['section_id']][$slot['subject_id']][$slot['day']] = ($subjDay[$slot['section_id']][$slot['subject_id']][$slot['day']] ?? 0) + 1;
        $subjCol[$slot['section_id']][$slot['subject_id']][$slot['period']] = ($subjCol[$slot['section_id']][$slot['subject_id']][$slot['period']] ?? 0) + 1;
        $globalCol[$slot['subject_id']][$slot['day']][$slot['period']] = ($globalCol[$slot['subject_id']][$slot['day']][$slot['period']] ?? 0) + 1;

        if (!isset($lock[$slot['section_id']][$slot['subject_id']])) {
            $lock[$slot['section_id']][$slot['subject_id']]      = $slot['teacher_id'];
            $lockCount[$slot['section_id']][$slot['subject_id']] = 0;
        }
        if ($lockCount[$slot['section_id']][$slot['subject_id']] !== PHP_INT_MAX) {
            $lockCount[$slot['section_id']][$slot['subject_id']]++;
        }

        $slots[] = $slot;
    }

    /** How constrained a subject is — harder ones get placed first. */
    private static function tightness(array $sub): int
    {
        $t = 0;
        if (!empty($sub['after_lunch_only'])) {
            $t += 100;
        }
        $t += max(0, 10 - count($sub['teacher_ids'])) * 10;
        $t += (int)$sub['per_week'];
        if ((int)($sub['max_per_day'] ?? 2) === 1) {
            $t += 20;
        }
        return $t;
    }

    /** Layout → period columns keyed by period no, with morning flag. */
    private static function periodColumns(array $layout): array
    {
        $periods = [];
        $afterLunch = false;
        foreach ($layout as $col) {
            if ($col['kind'] === 'lunch') {
                $afterLunch = true;
            }
            if ($col['kind'] === 'period') {
                $periods[(int)$col['no']] = [
                    'time_from' => $col['time_from'],
                    'time_to'   => $col['time_to'],
                    'morning'   => !$afterLunch,
                ];
            }
        }
        ksort($periods);
        return $periods;
    }

    /** Non-academic layout columns expanded per section × day (for display/draft). */
    private static function structuralRows(array $layout, array $sections, array $days): array
    {
        $rows = [];
        foreach ($layout as $col) {
            if ($col['kind'] === 'period') {
                continue;
            }
            foreach ($sections as $sec) {
                foreach ($days as $day) {
                    $rows[] = [
                        'section_id' => $sec['id'],
                        'day'        => $day,
                        'kind'       => $col['kind'],
                        'label'      => $col['label'] ?? ucfirst($col['kind']),
                        'time_from'  => $col['time_from'],
                        'time_to'    => $col['time_to'],
                    ];
                }
            }
        }
        return $rows;
    }

    private static function indexById(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    /** Sum soft penalties over a finished grid (for attempt scoring). */
    private static function totalSoftPenalty(array $slots): int
    {
        $pen = 0;
        $col = $dayCount = $cells = [];
        foreach ($slots as $s) {
            $col[$s['section_id']][$s['subject_id']][$s['period']] =
                ($col[$s['section_id']][$s['subject_id']][$s['period']] ?? 0) + 1;
            $dayCount[$s['section_id']][$s['subject_id']][$s['day']] =
                ($dayCount[$s['section_id']][$s['subject_id']][$s['day']] ?? 0) + 1;
            $cells[$s['section_id']][$s['day']][$s['period']] = $s['subject_id'];
        }
        foreach ($col as $bySub) {
            foreach ($bySub as $byPeriod) {
                foreach ($byPeriod as $n) {
                    if ($n > 1) {
                        $pen += self::W_ANTI_COLUMN * ($n - 1);
                    }
                }
            }
        }
        foreach ($dayCount as $bySub) {
            foreach ($bySub as $byDay) {
                foreach ($byDay as $n) {
                    if ($n > 1) {
                        $pen += self::W_DAY_SPREAD * ($n - 1);
                    }
                }
            }
        }
        foreach ($cells as $byDay) {
            foreach ($byDay as $periodsRow) {
                ksort($periodsRow);
                $prev = null;
                $prevNo = null;
                foreach ($periodsRow as $no => $sid) {
                    if ($prev !== null && $sid === $prev && $no === $prevNo + 1) {
                        $pen += self::W_BACK_TO_BACK;
                    }
                    $prev = $sid;
                    $prevNo = $no;
                }
            }
        }
        return $pen;
    }
}
