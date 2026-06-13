<?php

namespace app\components\ai\timetable;

use yii\db\Query;

/**
 * DayId — one tiny class to own a sneaky production fact:
 *
 * `subject_timetable.day_id` is a VARCHAR that holds day NAMES ('Monday') in
 * live data, and the mobile APIs filter with date('l') strings
 * (TeacherController::actionTimeTable, ParentController::actionStudentClassTimeTable).
 * The solver works in ints (1=Monday … 7=Sunday). Convert at the boundary —
 * exactly once, here — or published timetables silently never reach the apps.
 */
class DayId
{
    public const NAMES = [
        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
        5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
    ];

    public static function toName(int $day): string
    {
        return self::NAMES[$day] ?? 'Monday';
    }

    /** Accepts 'Monday', 'monday', '1', 1 — returns 1..7 (0 if unparsable). */
    public static function toInt($day): int
    {
        if (is_numeric($day)) {
            $n = (int)$day;
            return ($n >= 1 && $n <= 7) ? $n : 0;
        }
        $idx = array_search(ucfirst(strtolower(trim((string)$day))), self::NAMES, true);
        return $idx === false ? 0 : (int)$idx;
    }

    /** All representations of a day, for tolerant SQL IN() filters. */
    public static function variants(int $day): array
    {
        return [self::toName($day), (string)$day, $day];
    }

    /**
     * What format does THIS campus's live data use? Peeks one existing row;
     * defaults to 'name' (what the mobile APIs expect).
     */
    public static function detectFormat(int $campusId): string
    {
        try {
            $sample = (new Query())->select(['day_id'])
                ->from('subject_timetable')
                ->where(['campus_id' => $campusId, 'status' => 1])
                ->limit(1)->scalar();
            if ($sample !== false && $sample !== null && is_numeric($sample)) {
                return 'int';
            }
        } catch (\Throwable $e) {
            // fall through to default
        }
        return 'name';
    }

    /** Render a solver day (int) in the campus's live format. */
    public static function forCampus(int $day, string $format): string
    {
        return $format === 'int' ? (string)$day : self::toName($day);
    }
}
