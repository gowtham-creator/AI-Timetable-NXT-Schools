<?php

namespace app\modules\admin\models;

use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\Expression;

/**
 * AI timetable generation run (draft → published lifecycle).
 *
 * @property integer $id
 * @property integer $campus_id
 * @property integer $class_id
 * @property integer $academic_year_id
 * @property string  $section_ids       JSON array of class_sections.id
 * @property string  $rules_text
 * @property string  $constraints_json
 * @property string  $stats_json
 * @property string  $narrative
 * @property integer $ai_invocation_id
 * @property string  $status
 * @property string  $published_on
 * @property integer $published_by
 * @property string  $created_on
 * @property string  $updated_on
 * @property integer $create_user_id
 * @property integer $update_user_id
 */
class TimetableGenerationRun extends ActiveRecord
{
    const STATUS_DRAFT     = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_DISCARDED = 'discarded';
    const STATUS_FAILED    = 'failed';

    public static function tableName()
    {
        return 'timetable_generation_runs';
    }

    public function behaviors()
    {
        return [
            'timestamp' => [
                'class'              => TimestampBehavior::className(),
                'createdAtAttribute' => 'created_on',
                'updatedAtAttribute' => 'updated_on',
                'value'              => new Expression('NOW()'),
            ],
            'blameable' => [
                'class'              => BlameableBehavior::className(),
                'createdByAttribute' => 'create_user_id',
                'updatedByAttribute' => 'update_user_id',
            ],
        ];
    }

    public function rules()
    {
        return [
            [['campus_id', 'class_id', 'academic_year_id'], 'required'],
            [['campus_id', 'class_id', 'academic_year_id', 'ai_invocation_id', 'published_by'], 'integer'],
            [['section_ids', 'rules_text', 'constraints_json', 'stats_json', 'narrative'], 'safe'],
            [['status'], 'string', 'max' => 16],
            [['status'], 'in', 'range' => [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_DISCARDED, self::STATUS_FAILED]],
            [['published_on'], 'safe'],
        ];
    }

    public function getSlots()
    {
        return $this->hasMany(TimetableGenerationSlot::className(), ['run_id' => 'id']);
    }

    public function getCampus()
    {
        return $this->hasOne(Campus::className(), ['id' => 'campus_id']);
    }

    public function getStudentClass()
    {
        return $this->hasOne(StudentClass::className(), ['id' => 'class_id']);
    }

    /** @return int[] */
    public function sectionIdList(): array
    {
        $ids = json_decode((string)$this->section_ids, true);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    public function statsArray(): array
    {
        $stats = json_decode((string)$this->stats_json, true);
        return is_array($stats) ? $stats : [];
    }

    public function constraintsArray(): array
    {
        $c = json_decode((string)$this->constraints_json, true);
        return is_array($c) ? $c : [];
    }
}
