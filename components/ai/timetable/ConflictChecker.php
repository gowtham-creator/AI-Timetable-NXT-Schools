<?php

namespace app\components\ai\timetable;

use yii\db\Query;

/**
 * ConflictChecker — pre-flight validation for a single subject_timetable row.
 *
 * Pure SQL/PHP (no LLM). Detects, before save:
 *   - teacher double-booking (same campus, same day, overlapping time)
 *   - class-section double-booking
 *   - room double-booking
 *
 * Wired into SubjectTimetable::beforeSave(), turning the historical
 * "timetable error report" inbox into prevention.
 */
class ConflictChecker
{
    /**
     * @param \yii\db\ActiveRecord $model a SubjectTimetable being saved
     * @return string[] human-readable conflicts ([] = clean)
     */
    public static function check($model): array
    {
        $conflicts = [];

        $from = self::minutes((string)$model->time_from);
        $to   = self::minutes((string)$model->time_to);
        if ($from === null || $to === null) {
            return []; // unparsable times — let normal validation handle it
        }
        if ($to <= $from) {
            return ['End time must be after start time.'];
        }

        $base = (new Query())
            ->select(['id', 'time_from', 'time_to', 'teacher_details_id', 'class_id', 'section_id', 'room_id'])
            ->from('subject_timetable')
            ->where([
                'campus_id'        => $model->campus_id,
                'academic_year_id' => $model->academic_year_id,
                'day_id'           => $model->day_id,
                'status'           => 1,
            ]);
        if (!$model->isNewRecord) {
            $base->andWhere(['<>', 'id', $model->id]);
        }

        // One fetch for the day; compare in PHP (rows per campus-day are few).
        $rows = $base->all();
        foreach ($rows as $r) {
            $rFrom = self::minutes((string)$r['time_from']);
            $rTo   = self::minutes((string)$r['time_to']);
            if ($rFrom === null || $rTo === null) {
                continue;
            }
            if ($from >= $rTo || $to <= $rFrom) {
                continue; // no overlap
            }
            if ((int)$r['teacher_details_id'] === (int)$model->teacher_details_id) {
                $conflicts[] = "Teacher is already booked {$r['time_from']}–{$r['time_to']} that day (row #{$r['id']}).";
            }
            if ((int)$r['class_id'] === (int)$model->class_id
                && (int)$r['section_id'] === (int)$model->section_id) {
                $conflicts[] = "This section already has a period {$r['time_from']}–{$r['time_to']} that day (row #{$r['id']}).";
            }
            if ((int)$model->room_id > 0 && (int)$r['room_id'] === (int)$model->room_id) {
                $conflicts[] = "Room is already occupied {$r['time_from']}–{$r['time_to']} that day (row #{$r['id']}).";
            }
        }

        return array_values(array_unique($conflicts));
    }

    /** 'HH:MM' / 'HH:MM:SS' / 'H:MM AM' → minutes since midnight, null if unparsable. */
    public static function minutes(string $time): ?int
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(am|pm)?$/i', $time, $m)) {
            $h = (int)$m[1];
            $i = (int)$m[2];
            if (isset($m[3])) {
                $ampm = strtolower($m[3]);
                if ($ampm === 'pm' && $h < 12) {
                    $h += 12;
                }
                if ($ampm === 'am' && $h === 12) {
                    $h = 0;
                }
            }
            if ($h > 23 || $i > 59) {
                return null;
            }
            return $h * 60 + $i;
        }
        $ts = strtotime($time);
        return $ts === false ? null : (int)date('G', $ts) * 60 + (int)date('i', $ts);
    }
}
