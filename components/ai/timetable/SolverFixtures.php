<?php

namespace app\components\ai\timetable;

/**
 * SolverFixtures — default day layout, subject profiling heuristics and an
 * offline test fixture for TimetableSolver. Framework-free.
 */
class SolverFixtures
{
    /**
     * The standard Indian school day, as specified for the product:
     * assembly every morning, snack break ~10am, lunch ~12pm, sports every
     * evening ~3pm. Seven academic periods, Mon–Fri (Sat optional via days).
     */
    public static function defaultLayout(): array
    {
        return [
            ['kind' => 'assembly', 'label' => 'Morning Assembly', 'time_from' => '08:00', 'time_to' => '08:20'],
            ['kind' => 'period',   'no' => 1,                     'time_from' => '08:20', 'time_to' => '09:05'],
            ['kind' => 'period',   'no' => 2,                     'time_from' => '09:05', 'time_to' => '09:50'],
            ['kind' => 'break',    'label' => 'Snack Break',      'time_from' => '09:50', 'time_to' => '10:10'],
            ['kind' => 'period',   'no' => 3,                     'time_from' => '10:10', 'time_to' => '10:55'],
            ['kind' => 'period',   'no' => 4,                     'time_from' => '10:55', 'time_to' => '11:40'],
            ['kind' => 'period',   'no' => 5,                     'time_from' => '11:40', 'time_to' => '12:25'],
            ['kind' => 'lunch',    'label' => 'Lunch Break',      'time_from' => '12:25', 'time_to' => '13:05'],
            ['kind' => 'period',   'no' => 6,                     'time_from' => '13:05', 'time_to' => '13:50'],
            ['kind' => 'period',   'no' => 7,                     'time_from' => '13:50', 'time_to' => '14:35'],
            ['kind' => 'activity', 'label' => 'Sports & Games',   'time_from' => '14:45', 'time_to' => '15:45'],
        ];
    }

    /** Mon–Fri in subject_timetable day ids. */
    public static function defaultDays(): array
    {
        return [1, 2, 3, 4, 5];
    }

    /**
     * Heuristic weekly profile for a subject by its name. Used by the
     * DataLoader to seed sensible defaults that the admin (or the LLM
     * intake) can then override per school.
     *
     * @return array{per_week:int,max_per_day:int,after_lunch_only:bool}
     */
    public static function profileSubject(string $name): array
    {
        $n = strtolower($name);

        $is = function (array $words) use ($n): bool {
            foreach ($words as $w) {
                if (strpos($n, $w) !== false) {
                    return true;
                }
            }
            return false;
        };

        // Foundation/coaching subjects first — "IIT Maths" must not match "math".
        // (Real techno-school allocation sheets: IIT M 10-11/wk, IIT PH/Ch 8/wk.)
        if ($is(['iit', 'foundation', 'olympiad', 'jee', 'neet', 'techno'])) {
            return ['per_week' => 8, 'max_per_day' => 2, 'after_lunch_only' => false];
        }
        if ($is(['reasoning', 'reas', 'aptitude', 'mental ability'])) {
            return ['per_week' => 3, 'max_per_day' => 1, 'after_lunch_only' => false];
        }
        if ($is(['lab', 'practical'])) {
            return ['per_week' => 1, 'max_per_day' => 1, 'after_lunch_only' => false];
        }
        if ($is(['atl', 'tinker', 'robotic', 'stem'])) {
            return ['per_week' => 1, 'max_per_day' => 1, 'after_lunch_only' => false];
        }
        if ($is(['math'])) {
            return ['per_week' => 8, 'max_per_day' => 2, 'after_lunch_only' => false];
        }
        if ($is(['english'])) {
            return ['per_week' => 7, 'max_per_day' => 2, 'after_lunch_only' => false];
        }
        if ($is(['evs'])) {
            return ['per_week' => 5, 'max_per_day' => 2, 'after_lunch_only' => false];
        }
        if ($is(['science', 'physics', 'chemistry', 'biology'])) {
            return ['per_week' => 7, 'max_per_day' => 2, 'after_lunch_only' => false];
        }
        if ($is(['social', 'history', 'geography', 'civics'])) {
            return ['per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false];
        }
        if ($is(['hindi', 'telugu', 'tamil', 'kannada', 'urdu', 'sanskrit', 'french', 'language'])) {
            return ['per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false];
        }
        if ($is(['computer', 'ict', 'coding'])) {
            return ['per_week' => 2, 'max_per_day' => 1, 'after_lunch_only' => false];
        }
        if ($is(['yoga'])) {
            return ['per_week' => 1, 'max_per_day' => 1, 'after_lunch_only' => false];
        }
        if ($is(['pt', 'p.t', 'pet', 'physical', 'games', 'sport'])) {
            // PT twice a week, always in the post-lunch half of the day.
            return ['per_week' => 2, 'max_per_day' => 1, 'after_lunch_only' => true];
        }
        if ($is(['karate', 'kar', 'martial', 'skating', 'swim'])) {
            return ['per_week' => 1, 'max_per_day' => 1, 'after_lunch_only' => true];
        }
        if ($is(['library', 'reading'])) {
            return ['per_week' => 1, 'max_per_day' => 1, 'after_lunch_only' => false];
        }
        if ($is(['art', 'craft', 'a&c', 'drawing', 'music', 'dance'])) {
            return ['per_week' => 1, 'max_per_day' => 1, 'after_lunch_only' => false];
        }
        if ($is(['moral', 'value', 'gk', 'general knowledge'])) {
            return ['per_week' => 1, 'max_per_day' => 1, 'after_lunch_only' => false];
        }
        // Unknown subject: modest default.
        return ['per_week' => 3, 'max_per_day' => 1, 'after_lunch_only' => false];
    }

    /**
     * Offline fixture mirroring the verified demo: 3 sections, 10 subjects
     * (34 periods/section/week), 10 teachers, one morning-only teacher.
     * Used by `php yii timetable/solver-test` — no database required.
     */
    public static function demoInput(): array
    {
        $subjects = [
            ['id' => 1,  'sgs_id' => 101, 'name' => 'Mathematics',    'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [1, 2]],
            ['id' => 2,  'sgs_id' => 102, 'name' => 'English',        'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [3, 4]],
            ['id' => 3,  'sgs_id' => 103, 'name' => 'Science',        'per_week' => 5, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [5, 6]],
            ['id' => 4,  'sgs_id' => 104, 'name' => 'Social Studies', 'per_week' => 4, 'max_per_day' => 1, 'after_lunch_only' => false, 'teacher_ids' => [7]],
            ['id' => 5,  'sgs_id' => 105, 'name' => 'Hindi',          'per_week' => 4, 'max_per_day' => 1, 'after_lunch_only' => false, 'teacher_ids' => [8]],
            ['id' => 6,  'sgs_id' => 106, 'name' => 'Telugu',         'per_week' => 3, 'max_per_day' => 1, 'after_lunch_only' => false, 'teacher_ids' => [9]],
            ['id' => 7,  'sgs_id' => 107, 'name' => 'Computer',       'per_week' => 2, 'max_per_day' => 1, 'after_lunch_only' => false, 'teacher_ids' => [10]],
            ['id' => 8,  'sgs_id' => 108, 'name' => 'PT / Games',     'per_week' => 2, 'max_per_day' => 1, 'after_lunch_only' => true,  'teacher_ids' => [6, 10]],
            ['id' => 9,  'sgs_id' => 109, 'name' => 'Library',        'per_week' => 1, 'max_per_day' => 1, 'after_lunch_only' => false, 'teacher_ids' => [4, 9]],
            ['id' => 10, 'sgs_id' => 110, 'name' => 'Art & Craft',    'per_week' => 1, 'max_per_day' => 1, 'after_lunch_only' => false, 'teacher_ids' => [8, 3]],
        ];

        $teachers = [
            ['id' => 1,  'name' => 'Mr. Rao',      'morning_only' => true,  'max_per_day' => 6, 'max_per_week' => 30, 'unavailable' => []],
            ['id' => 2,  'name' => 'Ms. Lakshmi',  'morning_only' => false, 'max_per_day' => 6, 'max_per_week' => 30, 'unavailable' => []],
            ['id' => 3,  'name' => 'Mrs. D\'Souza', 'morning_only' => false, 'max_per_day' => 6, 'max_per_week' => 30, 'unavailable' => []],
            ['id' => 4,  'name' => 'Mr. Iqbal',    'morning_only' => false, 'max_per_day' => 6, 'max_per_week' => 30, 'unavailable' => []],
            ['id' => 5,  'name' => 'Dr. Menon',    'morning_only' => false, 'max_per_day' => 6, 'max_per_week' => 30, 'unavailable' => []],
            ['id' => 6,  'name' => 'Mr. Khan',     'morning_only' => false, 'max_per_day' => 6, 'max_per_week' => 30, 'unavailable' => []],
            ['id' => 7,  'name' => 'Mrs. Reddy',   'morning_only' => false, 'max_per_day' => 6, 'max_per_week' => 30, 'unavailable' => []],
            ['id' => 8,  'name' => 'Ms. Sharma',   'morning_only' => false, 'max_per_day' => 6, 'max_per_week' => 30, 'unavailable' => []],
            ['id' => 9,  'name' => 'Mr. Prasad',   'morning_only' => false, 'max_per_day' => 6, 'max_per_week' => 30, 'unavailable' => []],
            ['id' => 10, 'name' => 'Ms. Joseph',   'morning_only' => false, 'max_per_day' => 6, 'max_per_week' => 30, 'unavailable' => []],
        ];

        return [
            'days'     => self::defaultDays(),
            'layout'   => self::defaultLayout(),
            'sections' => [
                ['id' => 11, 'name' => 'A'],
                ['id' => 12, 'name' => 'B'],
                ['id' => 13, 'name' => 'C'],
            ],
            'subjects' => $subjects,
            'teachers' => $teachers,
        ];
    }

    /**
     * Fixture modelled on a REAL school's period-allocation & teacher-wise
     * workload sheets (General stream, class 6, sections E/R/S/D/O):
     *
     *   - 6 working days × 9 academic periods = 54 cells/section/week
     *   - quotas: TEL 7, HIN 6, ENG 8, MATHS 10, SCI 8, SOC 7, COM 2,
     *             ATL 1, PET 2, LIB 1, KARATE 1, YOGA 1  (Σ = 54, full grid)
     *   - subject-specialist teachers, workload cap 44/week (~8/day)
     *   - teacher consistency: one teacher owns a (section × subject) all week
     */
    public static function realSchoolInput(): array
    {
        $layout = [
            ['kind' => 'assembly', 'label' => 'Morning Assembly', 'time_from' => '08:00', 'time_to' => '08:15'],
            ['kind' => 'period',   'no' => 1,                     'time_from' => '08:15', 'time_to' => '09:00'],
            ['kind' => 'period',   'no' => 2,                     'time_from' => '09:00', 'time_to' => '09:45'],
            ['kind' => 'period',   'no' => 3,                     'time_from' => '09:45', 'time_to' => '10:30'],
            ['kind' => 'break',    'label' => 'Snack Break',      'time_from' => '10:30', 'time_to' => '10:50'],
            ['kind' => 'period',   'no' => 4,                     'time_from' => '10:50', 'time_to' => '11:35'],
            ['kind' => 'period',   'no' => 5,                     'time_from' => '11:35', 'time_to' => '12:20'],
            ['kind' => 'lunch',    'label' => 'Lunch Break',      'time_from' => '12:20', 'time_to' => '13:00'],
            ['kind' => 'period',   'no' => 6,                     'time_from' => '13:00', 'time_to' => '13:45'],
            ['kind' => 'period',   'no' => 7,                     'time_from' => '13:45', 'time_to' => '14:30'],
            ['kind' => 'period',   'no' => 8,                     'time_from' => '14:30', 'time_to' => '15:15'],
            ['kind' => 'period',   'no' => 9,                     'time_from' => '15:15', 'time_to' => '16:00'],
            ['kind' => 'activity', 'label' => 'Games / Sports',   'time_from' => '16:00', 'time_to' => '16:45'],
        ];

        $subjects = [
            ['id' => 1,  'sgs_id' => 201, 'name' => 'Telugu',      'per_week' => 7,  'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [1, 2]],
            ['id' => 2,  'sgs_id' => 202, 'name' => 'Hindi',       'per_week' => 6,  'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [3, 4]],
            ['id' => 3,  'sgs_id' => 203, 'name' => 'English',     'per_week' => 8,  'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [5, 6]],
            ['id' => 4,  'sgs_id' => 204, 'name' => 'Mathematics', 'per_week' => 10, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [7, 8]],
            ['id' => 5,  'sgs_id' => 205, 'name' => 'Science',     'per_week' => 8,  'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [9, 10]],
            ['id' => 6,  'sgs_id' => 206, 'name' => 'Social',      'per_week' => 7,  'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [11, 12]],
            ['id' => 7,  'sgs_id' => 207, 'name' => 'Computer',    'per_week' => 2,  'max_per_day' => 1, 'after_lunch_only' => false, 'teacher_ids' => [13]],
            ['id' => 8,  'sgs_id' => 208, 'name' => 'ATL',         'per_week' => 1,  'max_per_day' => 1, 'after_lunch_only' => false, 'teacher_ids' => [14]],
            ['id' => 9,  'sgs_id' => 209, 'name' => 'PET',         'per_week' => 2,  'max_per_day' => 1, 'after_lunch_only' => true,  'teacher_ids' => [15]],
            ['id' => 10, 'sgs_id' => 210, 'name' => 'Library',     'per_week' => 1,  'max_per_day' => 1, 'after_lunch_only' => false, 'teacher_ids' => [16]],
            ['id' => 11, 'sgs_id' => 211, 'name' => 'Karate',      'per_week' => 1,  'max_per_day' => 1, 'after_lunch_only' => true,  'teacher_ids' => [15]],
            ['id' => 12, 'sgs_id' => 212, 'name' => 'Yoga',        'per_week' => 1,  'max_per_day' => 1, 'after_lunch_only' => false, 'teacher_ids' => [16]],
        ];

        $mk = static fn(int $id, string $name) => [
            'id' => $id, 'name' => $name, 'morning_only' => false,
            'max_per_day' => 8, 'max_per_week' => 44, 'unavailable' => [],
        ];
        $teachers = [
            $mk(1,  'Rajitha (Telugu)'),       $mk(2,  'Santhoshi (Telugu)'),
            $mk(3,  'Sridevi (Hindi)'),        $mk(4,  'Mallishwari (Hindi)'),
            $mk(5,  'Anthony (English)'),      $mk(6,  'Surekha (English)'),
            $mk(7,  'Aruna (Maths)'),          $mk(8,  'Vanaja (Maths)'),
            $mk(9,  'Sameena (Science)'),      $mk(10, 'Kalyani (Science)'),
            $mk(11, 'Bhuvaneshwari (Social)'), $mk(12, 'Suvarnalatha (Social)'),
            $mk(13, 'Joseph (Computer)'),      $mk(14, 'Naveen (ATL)'),
            $mk(15, 'Babu (PET)'),             $mk(16, 'Rama (Library/Yoga)'),
        ];

        return [
            'days'     => [1, 2, 3, 4, 5, 6],
            'layout'   => $layout,
            'sections' => [
                ['id' => 61, 'name' => '6E'],
                ['id' => 62, 'name' => '6R'],
                ['id' => 63, 'name' => '6S'],
                ['id' => 64, 'name' => '6D'],
                ['id' => 65, 'name' => '6O'],
            ],
            'subjects' => $subjects,
            'teachers' => $teachers,
        ];
    }

    /**
     * WHOLE-SCHOOL fixture: classes 6–10, two sections each (10 class-sections),
     * solved in ONE run so cross-class teacher conflicts are prevented.
     *
     * Heterogeneous by stream:
     *   middle (6–8): integrated Science
     *   high   (9–10): Physics / Chemistry / Biology split
     * Shared teacher pools across classes (e.g. Mr. Kishore teaches Maths for
     * every class) — the solver gives each section a consistent owner, balances
     * load, and never double-books a teacher across classes.
     */
    public static function wholeSchoolInput(): array
    {
        // School-wide teacher roster (id => name). Pools referenced by subjects.
        $T = static fn(int $id, string $name) => [
            'id' => $id, 'name' => $name, 'morning_only' => false,
            'max_per_day' => 8, 'max_per_week' => 44, 'unavailable' => [],
        ];
        $teachers = [
            $T(1, 'Mr. Kishore'), $T(2, 'Ms. Vanaja'), $T(3, 'Mr. Rao'),          // Maths
            $T(4, 'Mr. Anthony'), $T(5, 'Ms. Surekha'), $T(6, 'Mr. Iqbal'),       // English
            $T(7, 'Dr. Menon'), $T(8, 'Ms. Kalyani'),                             // Science (middle)
            $T(9, 'Mr. Bose'), $T(10, 'Ms. Curie'), $T(11, 'Mr. Darwin'),         // Phy / Chem / Bio (high)
            $T(12, 'Mrs. Reddy'), $T(13, 'Mr. Naidu'),                            // Social
            $T(14, 'Ms. Rajitha'), $T(15, 'Ms. Santhoshi'),                       // Telugu
            $T(16, 'Mrs. Sridevi'), $T(17, 'Ms. Mallishwari'),                    // Hindi
            $T(18, 'Mr. Joseph'),                                                 // Computer
            $T(19, 'Mr. Babu'), $T(22, 'Mr. Khan'),                               // PE (2 — afternoon capacity)
            $T(20, 'Mrs. Rama'),                                                  // Library
            $T(21, 'Ms. Sharma'),                                                 // Art
        ];

        $sub = static fn(int $id, string $name, int $pw, int $mpd, bool $pm, array $tids) =>
            ['id' => $id, 'sgs_id' => 100 + $id, 'name' => $name, 'per_week' => $pw,
             'max_per_day' => $mpd, 'after_lunch_only' => $pm, 'teacher_ids' => $tids];

        // Middle stream (classes 6–8): 42/wk of 48 slots.
        $middle = [
            $sub(1, 'Mathematics', 7, 2, false, [1, 2, 3]),
            $sub(2, 'English',     7, 2, false, [4, 5, 6]),
            $sub(3, 'Science',     7, 2, false, [7, 8]),
            $sub(4, 'Social',      5, 1, false, [12, 13]),
            $sub(5, 'Telugu',      5, 1, false, [14, 15]),
            $sub(6, 'Hindi',       5, 1, false, [16, 17]),
            $sub(7, 'Computer',    2, 1, false, [18]),
            $sub(8, 'PE',          2, 1, true,  [19, 22]),
            $sub(9, 'Library',     1, 1, false, [20]),
            $sub(10, 'Art',        1, 1, false, [21]),
        ];
        // High stream (classes 9–10): 44/wk of 48 slots; split sciences.
        $high = [
            $sub(1, 'Mathematics', 7, 2, false, [1, 2, 3]),
            $sub(2, 'English',     6, 2, false, [4, 5, 6]),
            $sub(11, 'Physics',    5, 2, false, [9]),
            $sub(12, 'Chemistry',  5, 2, false, [10]),
            $sub(13, 'Biology',    4, 1, false, [11]),
            $sub(4, 'Social',      5, 1, false, [12, 13]),
            $sub(5, 'Telugu',      4, 1, false, [14, 15]),
            $sub(6, 'Hindi',       4, 1, false, [16, 17]),
            $sub(7, 'Computer',    2, 1, false, [18]),
            $sub(8, 'PE',          2, 1, true,  [19, 22]),
        ];

        $sections = [];
        foreach (['6', '7', '8', '9', '10'] as $cls) {
            $isHigh = (int)$cls >= 9;
            foreach (['A', 'B'] as $sx) {
                $sections[] = [
                    'id'       => (int)($cls . ($sx === 'A' ? '01' : '02')),
                    'name'     => $cls . $sx,
                    'class'    => $cls,
                    'subjects' => $isHigh ? $high : $middle,   // per-class subject + teacher allocation
                ];
            }
        }

        return [
            'days'     => [1, 2, 3, 4, 5, 6],
            'layout'   => self::wholeSchoolLayout(),
            'sections' => $sections,
            'subjects' => $middle, // default fallback (unused — every section overrides)
            'teachers' => $teachers,
        ];
    }

    // ── Single-class fixtures (intake flow + distinct-per-section proofs) ──────

    private static function clk(int $min): string
    {
        return sprintf('%02d:%02d', intdiv($min, 60) % 24, $min % 60);
    }

    /** Compact teaching grid: assembly + N periods, lunch after the middle period. */
    private static function gridLayout(int $nPeriods): array
    {
        $rows = [['kind' => 'assembly', 'label' => 'Assembly', 'time_from' => '08:00', 'time_to' => '08:15']];
        $lunchAfter = (int)floor($nPeriods / 2);
        $clock = 8 * 60 + 15;
        for ($p = 1; $p <= $nPeriods; $p++) {
            $from = self::clk($clock);
            $clock += 45;
            $rows[] = ['kind' => 'period', 'no' => $p, 'time_from' => $from, 'time_to' => self::clk($clock)];
            if ($p === $lunchAfter) {
                $f = self::clk($clock);
                $clock += 40;
                $rows[] = ['kind' => 'lunch', 'label' => 'Lunch', 'time_from' => $f, 'time_to' => self::clk($clock)];
            }
        }
        return $rows;
    }

    private static function teacher(int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'morning_only' => false,
            'max_per_day' => 8, 'max_per_week' => 44, 'unavailable' => []];
    }

    /**
     * CLONE-RISK: one class, two sections (7A/7B) with IDENTICAL subjects but
     * fully-DISJOINT teacher pools per section — so no shared teacher forces the
     * grids apart. With a fixed seed and NO cross-section diversity term the two
     * grids could come out byte-identical. Proves the W_SECTION_DIVERSITY fix
     * (test `each_section_grid_is_distinct`). 5 subjects × 6/wk = 30 = full grid.
     */
    public static function cloneRiskInput(): array
    {
        $teachers = [];
        for ($i = 1; $i <= 20; $i++) {
            $teachers[] = self::teacher($i, 'T' . $i);
        }
        $names = ['Mathematics', 'English', 'Science', 'Social', 'Hindi'];
        $mkSubs = static function (array $pools) use ($names): array {
            $out = [];
            foreach ($names as $k => $nm) {
                $out[] = ['id' => $k + 1, 'sgs_id' => 900 + $k, 'name' => $nm,
                    'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false,
                    'teacher_ids' => $pools[$k]];
            }
            return $out;
        };
        $aPools = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10]];
        $bPools = [[11, 12], [13, 14], [15, 16], [17, 18], [19, 20]];
        return [
            'days'     => [1, 2, 3, 4, 5],
            'layout'   => self::gridLayout(6),
            'sections' => [
                ['id' => 71, 'name' => '7A', 'subjects' => $mkSubs($aPools)],
                ['id' => 72, 'name' => '7B', 'subjects' => $mkSubs($bPools)],
            ],
            'subjects' => $mkSubs($aPools), // union fallback
            'teachers' => $teachers,
        ];
    }

    /**
     * TRIPLE-SECTION shared teacher: one class, sections 8A/8B/8C, Mathematics
     * owned by a SINGLE shared teacher (id 1) across all three — the within-class
     * "Mr. Kishore" case. Filler subjects have 3-teacher pools so each section
     * gets its own teacher for them. Proves the shared Maths teacher is placed at
     * different (day,period) slots in every section (no double-booking).
     */
    public static function tripleSectionInput(): array
    {
        $teachers = [];
        for ($i = 1; $i <= 13; $i++) {
            $teachers[] = self::teacher($i, 'T' . $i);
        }
        $subjects = [
            ['id' => 1, 'sgs_id' => 801, 'name' => 'Mathematics', 'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [1]],
            ['id' => 2, 'sgs_id' => 802, 'name' => 'English',     'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [2, 3, 4]],
            ['id' => 3, 'sgs_id' => 803, 'name' => 'Science',     'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [5, 6, 7]],
            ['id' => 4, 'sgs_id' => 804, 'name' => 'Social',      'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [8, 9, 10]],
            ['id' => 5, 'sgs_id' => 805, 'name' => 'Hindi',       'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [11, 12, 13]],
        ];
        return [
            'days'     => [1, 2, 3, 4, 5],
            'layout'   => self::gridLayout(6),
            'sections' => [
                ['id' => 81, 'name' => '8A'],
                ['id' => 82, 'name' => '8B'],
                ['id' => 83, 'name' => '8C'],
            ],
            'subjects' => $subjects,
            'teachers' => $teachers,
        ];
    }

    /**
     * NO-SECTION small school: a class with no class_sections rows is treated as
     * ONE synthetic whole-class unit (negative id). Proves the monolithic-class
     * path solves to a single valid timetable. 5 subjects × 6/wk = 30 = full grid.
     */
    public static function noSectionInput(): array
    {
        $teachers = [];
        for ($i = 1; $i <= 8; $i++) {
            $teachers[] = self::teacher($i, 'T' . $i);
        }
        $subjects = [
            ['id' => 1, 'sgs_id' => 701, 'name' => 'Mathematics', 'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [1, 2]],
            ['id' => 2, 'sgs_id' => 702, 'name' => 'English',     'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [3, 4]],
            ['id' => 3, 'sgs_id' => 703, 'name' => 'Science',     'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [5, 6]],
            ['id' => 4, 'sgs_id' => 704, 'name' => 'Social',      'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [7]],
            ['id' => 5, 'sgs_id' => 705, 'name' => 'Hindi',       'per_week' => 6, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [8]],
        ];
        return [
            'days'     => [1, 2, 3, 4, 5],
            'layout'   => self::gridLayout(6),
            'sections' => [
                ['id' => -9, 'name' => '(Whole class)', 'synthetic' => true, 'subjects' => $subjects],
            ],
            'subjects' => $subjects,
            'teachers' => $teachers,
        ];
    }

    /**
     * MINIMAL universal school: the smallest valid universe the loader can emit —
     * 1 class, 1 section, 2 subjects, 2 teachers, 3 days, 2 periods/day. Proves
     * the engine accepts any minimal data-contract-conformant input.
     */
    public static function minimalSchoolInput(): array
    {
        $subjects = [
            ['id' => 1, 'sgs_id' => 601, 'name' => 'Mathematics', 'per_week' => 3, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [1]],
            ['id' => 2, 'sgs_id' => 602, 'name' => 'English',     'per_week' => 3, 'max_per_day' => 2, 'after_lunch_only' => false, 'teacher_ids' => [2]],
        ];
        return [
            'days'     => [1, 2, 3],
            'layout'   => self::gridLayout(2),
            'sections' => [
                ['id' => 1, 'name' => 'A', 'subjects' => $subjects],
            ],
            'subjects' => $subjects,
            'teachers' => [self::teacher(1, 'T1'), self::teacher(2, 'T2')],
        ];
    }

    /** 8 academic periods, lunch after P5 (afternoon = P6–P8). */
    private static function wholeSchoolLayout(): array
    {
        return [
            ['kind' => 'assembly', 'label' => 'Assembly',   'time_from' => '08:30', 'time_to' => '08:45'],
            ['kind' => 'period', 'no' => 1, 'time_from' => '08:45', 'time_to' => '09:30'],
            ['kind' => 'period', 'no' => 2, 'time_from' => '09:30', 'time_to' => '10:15'],
            ['kind' => 'period', 'no' => 3, 'time_from' => '10:15', 'time_to' => '11:00'],
            ['kind' => 'break', 'label' => 'Snack', 'time_from' => '11:00', 'time_to' => '11:15'],
            ['kind' => 'period', 'no' => 4, 'time_from' => '11:15', 'time_to' => '12:00'],
            ['kind' => 'period', 'no' => 5, 'time_from' => '12:00', 'time_to' => '12:45'],
            ['kind' => 'lunch', 'label' => 'Lunch', 'time_from' => '12:45', 'time_to' => '13:25'],
            ['kind' => 'period', 'no' => 6, 'time_from' => '13:25', 'time_to' => '14:10'],
            ['kind' => 'period', 'no' => 7, 'time_from' => '14:10', 'time_to' => '14:55'],
            ['kind' => 'period', 'no' => 8, 'time_from' => '14:55', 'time_to' => '15:40'],
        ];
    }
}
