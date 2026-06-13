<?php

namespace app\modules\admin\models;

use app\components\ai\timetable\ConflictChecker;
use Yii;
use \app\modules\admin\models\base\SubjectTimetable as BaseSubjectTimetable;

/**
 * This is the model class for table "subject_timetable".
 */
class SubjectTimetable extends BaseSubjectTimetable
{
    /**
     * Conflict pre-flight switch. Leave ON for normal form/import saves so a
     * teacher/section/room can never be double-booked. The AI bulk publish
     * (TimetableComposer::publish) validates the whole run atomically and
     * writes via batchInsert, so it does not pass through this guard.
     */
    public static $conflictGuard = true;

    /** @var string[] conflicts found by the last failed save (for UI display) */
    public $preflightConflicts = [];

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        if (!static::$conflictGuard) {
            return true;
        }
        if ((int)$this->status !== self::STATUS_ACTIVE) {
            return true; // archiving / deactivating is always allowed
        }

        // Only re-check when scheduling fields actually changed.
        $scheduleFields = ['campus_id', 'academic_year_id', 'day_id', 'class_id', 'section_id',
            'teacher_details_id', 'room_id', 'time_from', 'time_to', 'status'];
        if (!$insert && array_intersect(array_keys($this->getDirtyAttributes()), $scheduleFields) === []) {
            return true;
        }

        $this->preflightConflicts = ConflictChecker::check($this);
        if ($this->preflightConflicts !== []) {
            foreach ($this->preflightConflicts as $msg) {
                $this->addError('time_from', $msg);
            }
            Yii::info('Timetable conflict pre-flight blocked save: '
                . implode(' | ', $this->preflightConflicts), 'timetable');
            return false;
        }
        return true;
    }
}
