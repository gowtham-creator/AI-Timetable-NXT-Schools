<?php

namespace app\modules\admin\models;

use yii\db\ActiveRecord;

/**
 * One generated cell of a draft timetable (academic period or structural band).
 *
 * @property integer $id
 * @property integer $run_id
 * @property integer $section_id
 * @property integer $day_id                    1=Monday … 7=Sunday
 * @property integer $period                    NULL for structural rows
 * @property string  $kind                      academic|assembly|break|lunch|activity
 * @property integer $subject_id
 * @property integer $subject_group_subject_id
 * @property integer $teacher_details_id
 * @property string  $label
 * @property string  $time_from
 * @property string  $time_to
 */
class TimetableGenerationSlot extends ActiveRecord
{
    const KIND_ACADEMIC = 'academic';
    const KIND_ASSEMBLY = 'assembly';
    const KIND_BREAK    = 'break';
    const KIND_LUNCH    = 'lunch';
    const KIND_ACTIVITY = 'activity';

    public static function tableName()
    {
        return 'timetable_generation_slots';
    }

    public function rules()
    {
        return [
            [['run_id', 'section_id', 'day_id', 'time_from', 'time_to'], 'required'],
            [['run_id', 'section_id', 'day_id', 'period', 'subject_id', 'subject_group_subject_id', 'teacher_details_id'], 'integer'],
            [['kind'], 'string', 'max' => 12],
            [['label'], 'string', 'max' => 64],
            [['time_from', 'time_to'], 'string', 'max' => 8],
        ];
    }

    public function getRun()
    {
        return $this->hasOne(TimetableGenerationRun::className(), ['id' => 'run_id']);
    }

    public function getSubject()
    {
        return $this->hasOne(Subjects::className(), ['id' => 'subject_id']);
    }

    public function getTeacherDetails()
    {
        return $this->hasOne(TeacherDetails::className(), ['id' => 'teacher_details_id']);
    }

    public function getSection()
    {
        return $this->hasOne(ClassSections::className(), ['id' => 'section_id']);
    }
}
