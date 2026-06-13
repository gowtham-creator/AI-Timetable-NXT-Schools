<?php

namespace app\components\ai\timetable;

use app\modules\admin\models\TemporaryAssignTeacher;
use Yii;
use yii\db\Query;

/**
 * SubstituteFinder — when a teacher is absent on a date, finds their affected
 * periods and ranks substitute candidates:
 *
 *   1. free at that exact day/time (no regular period, no other substitution)
 *   2. has taught the same subject before (competence)
 *   3. lighter substitution load in the last 30 days (fairness)
 *
 * apply() writes approved assignments into temporary_assign_teacher — the
 * same table the dashboard's "temporary assigned teachers" widget reads.
 */
class SubstituteFinder
{
    /**
     * Periods the absent teacher was scheduled to take on a date.
     */
    public function affectedPeriods(int $teacherDetailsId, string $date): array
    {
        $dayId = (int)date('N', strtotime($date)); // 1=Mon … 7=Sun

        return (new Query())
            ->select([
                'st.id', 'st.campus_id', 'st.day_id', 'st.period', 'st.time_from', 'st.time_to',
                'st.class_id', 'st.section_id', 'st.subject_id', 'st.teacher_details_id',
                'subject_name' => 's.subject_name',
                'class_name'   => 'sc.title',
                'section_name' => 'cs.section_name',
            ])
            ->from(['st' => 'subject_timetable'])
            ->leftJoin(['s' => 'subjects'], 's.id = st.subject_id')
            ->leftJoin(['sc' => 'student_class'], 'sc.id = st.class_id')
            ->leftJoin(['cs' => 'class_sections'], 'cs.id = st.section_id')
            // day_id is stored as 'Monday' in live data (what the mobile APIs
            // filter on) but may be numeric in older rows — match both.
            ->where(['st.teacher_details_id' => $teacherDetailsId, 'st.status' => 1])
            ->andWhere(['st.day_id' => DayId::variants($dayId)])
            ->orderBy(['st.time_from' => SORT_ASC])
            ->all();
    }

    /**
     * Ranked substitute candidates for one affected period row.
     *
     * @param array $period one row from affectedPeriods()
     * @param string $date  'Y-m-d'
     * @return array[] [{teacher_details_id, name, score, free, same_subject, recent_load, reasons[]}]
     */
    public function candidates(array $period, string $date, int $limit = 5): array
    {
        $campusId = (int)$period['campus_id'];
        $dayId    = (string)$period['day_id'];
        $from     = ConflictChecker::minutes((string)$period['time_from']);
        $to       = ConflictChecker::minutes((string)$period['time_to']);

        $teachers = (new Query())->select(['id', 'name'])->from('teacher_details')
            ->where(['campus_id' => $campusId])
            ->andWhere(['<>', 'id', (int)$period['teacher_details_id']])
            ->all();
        if ($teachers === []) {
            return [];
        }

        // Regular commitments on that weekday (one query for the campus).
        $busyRows = (new Query())
            ->select(['teacher_details_id', 'time_from', 'time_to'])
            ->from('subject_timetable')
            ->where(['campus_id' => $campusId, 'status' => 1])
            ->andWhere(['day_id' => DayId::variants(DayId::toInt($dayId))])
            ->all();
        $busy = [];
        foreach ($busyRows as $b) {
            $busy[(int)$b['teacher_details_id']][] = [
                ConflictChecker::minutes((string)$b['time_from']),
                ConflictChecker::minutes((string)$b['time_to']),
            ];
        }

        // Substitutions already given for that date.
        $subbedRows = (new Query())
            ->select(['teacher_detail_id', 'time_from', 'time_to'])
            ->from('temporary_assign_teacher')
            ->where(['date' => $date, 'campus_id' => $campusId])
            ->all();
        foreach ($subbedRows as $b) {
            $busy[(int)$b['teacher_detail_id']][] = [
                ConflictChecker::minutes((string)$b['time_from']),
                ConflictChecker::minutes((string)$b['time_to']),
            ];
        }

        // Subject competence (who has ever taught this subject here).
        $competent = [];
        if (!empty($period['subject_id'])) {
            $competent = array_flip(array_map('intval', (new Query())
                ->select(['teacher_details_id'])->distinct()
                ->from('subject_timetable')
                ->where(['campus_id' => $campusId, 'subject_id' => (int)$period['subject_id'], 'status' => 1])
                ->column()));
        }

        // Substitution load, last 30 days (fairness).
        $loadRows = (new Query())
            ->select(['teacher_detail_id', 'cnt' => 'COUNT(*)'])
            ->from('temporary_assign_teacher')
            ->where(['campus_id' => $campusId])
            ->andWhere(['>=', 'date', date('Y-m-d', strtotime('-30 days', strtotime($date)))])
            ->groupBy(['teacher_detail_id'])
            ->all();
        $recentLoad = array_column($loadRows, 'cnt', 'teacher_detail_id');

        $ranked = [];
        foreach ($teachers as $t) {
            $tid  = (int)$t['id'];
            $free = true;
            foreach (($busy[$tid] ?? []) as [$bFrom, $bTo]) {
                if ($bFrom === null || $bTo === null) {
                    continue;
                }
                if (!($from >= $bTo || $to <= $bFrom)) {
                    $free = false;
                    break;
                }
            }
            if (!$free) {
                continue; // hard requirement: candidate must be free
            }

            $sameSubject = isset($competent[$tid]);
            $load        = (int)($recentLoad[$tid] ?? 0);
            $score       = ($sameSubject ? 100 : 0) - $load * 10;

            $reasons = ['Free during this period'];
            if ($sameSubject) {
                $reasons[] = 'Has taught ' . ($period['subject_name'] ?: 'this subject');
            }
            $reasons[] = $load === 0 ? 'No substitutions in the last 30 days'
                : "{$load} substitution(s) in the last 30 days";

            $ranked[] = [
                'teacher_details_id' => $tid,
                'name'               => (string)$t['name'],
                'score'              => $score,
                'same_subject'       => $sameSubject,
                'recent_load'        => $load,
                'reasons'            => $reasons,
            ];
        }

        usort($ranked, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($ranked, 0, $limit);
    }

    /**
     * Apply an approved substitution: one temporary_assign_teacher row per
     * affected period. Returns inserted row ids.
     *
     * @param array  $period       row from affectedPeriods()
     * @param int    $substituteId teacher_details.id stepping in
     * @param string $date         'Y-m-d'
     */
    public function apply(array $period, int $substituteId, string $date, ?int $userId): int
    {
        $model = new TemporaryAssignTeacher();
        $model->campus_id                  = (int)$period['campus_id'];
        $model->teacher_detail_id          = $substituteId;
        $model->replaced_teacher_detail_id = (int)$period['teacher_details_id'];
        $model->teacher_timetable_id       = (int)$period['id'];
        $model->date                       = $date;
        // period rows carry day_id as stored in live data ('Monday' or int) —
        // temporary_assign_teacher.day_id is numeric, so normalise.
        $model->day_id                     = DayId::toInt($period['day_id']);
        $model->period                     = (int)$period['period'];
        $model->time_from                  = (string)$period['time_from'];
        $model->time_to                    = (string)$period['time_to'];
        $model->class_id                   = (int)$period['class_id'];
        $model->section_id                 = (int)$period['section_id'];
        $model->subject_id                 = (int)$period['subject_id'];
        $model->status                     = 1;
        if (!$model->save(false)) {
            throw new \RuntimeException('Could not save substitution');
        }
        Yii::info("Substitute {$substituteId} assigned for timetable row {$period['id']} on {$date}", 'timetable');

        // Nudge both teachers on the mobile app (best-effort, never throws).
        (new TimetablePushService())->notifySubstitution($period, $substituteId, $date);

        return (int)$model->id;
    }
}
