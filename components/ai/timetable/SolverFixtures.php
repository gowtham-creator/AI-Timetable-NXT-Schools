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
}
