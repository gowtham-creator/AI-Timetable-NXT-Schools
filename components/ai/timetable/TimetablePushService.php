<?php

namespace app\components\ai\timetable;

use app\components\FirebaseNotification;
use Yii;
use yii\db\Query;

/**
 * TimetablePushService — pushes timetable events to the parent & teacher
 * mobile apps through the ERP's existing notification stack
 * (components/FirebaseNotification.php → fcm_notifications + FCM).
 *
 * The apps already READ published timetables via their existing endpoints
 * (teacher/time-table, parent/student-class-time-table) the moment
 * publish() writes subject_timetable — this service just tells users to look.
 *
 * Notification payload type is 'timetable' — the apps' notification screens
 * switch on notificationType and deep-link to their timetable page.
 *
 * Every send is best-effort: a push failure must never roll back a publish.
 * Disable globally with: $params['ai.timetable.push'] = false.
 */
class TimetablePushService
{
    public const TYPE = 'timetable';

    /** "Your weekly timetable was updated" — to every affected teacher + parent. */
    public function notifyPublished(int $campusId, int $classId, array $sectionIds, int $runId): array
    {
        if (!$this->enabled()) {
            return ['teachers' => 0, 'parents' => 0, 'skipped' => true];
        }

        $sent = ['teachers' => 0, 'parents' => 0, 'skipped' => false];

        // Teachers on the newly published grid.
        $teacherUserIds = (new Query())->select(['td.user_id'])->distinct()
            ->from(['st' => 'subject_timetable'])
            ->innerJoin(['td' => 'teacher_details'], 'td.id = st.teacher_details_id')
            ->where([
                'st.campus_id' => $campusId, 'st.class_id' => $classId,
                'st.section_id' => $sectionIds, 'st.status' => 1,
            ])
            ->andWhere(['not', ['td.user_id' => null]])
            ->column();
        foreach ($teacherUserIds as $uid) {
            $sent['teachers'] += $this->send(
                (int)$uid,
                'Timetable updated',
                'Your weekly class timetable has been updated. Open the app to see your new schedule.',
                $runId
            );
        }

        // Parents of every active student in the affected sections.
        $parentUserIds = (new Query())->select(['pd.user_id'])->distinct()
            ->from(['sd' => 'student_details'])
            ->innerJoin(['pd' => 'parent_details'], 'pd.id = sd.parent_id')
            ->where([
                'sd.campus_id' => $campusId, 'sd.student_class_id' => $classId,
                'sd.section_id' => $sectionIds, 'sd.status' => 1,
            ])
            ->andWhere(['not', ['pd.user_id' => null]])
            ->column();
        foreach ($parentUserIds as $uid) {
            $sent['parents'] += $this->send(
                (int)$uid,
                'Class timetable updated',
                'Your child\'s class timetable has been updated. Open the app to see the new schedule.',
                $runId
            );
        }

        Yii::info("Timetable publish push: {$sent['teachers']} teachers, {$sent['parents']} parents (run #{$runId})", 'timetable');
        return $sent;
    }

    /** Substitution applied — tell the covering teacher (and the absent one). */
    public function notifySubstitution(array $period, int $substituteTeacherId, string $date): void
    {
        if (!$this->enabled()) {
            return;
        }
        $label = trim(($period['subject_name'] ?? 'a period') . ', '
            . ($period['class_name'] ?? '') . ' ' . ($period['section_name'] ?? ''))
            . " ({$period['time_from']}–{$period['time_to']})";

        $subUserId = $this->teacherUserId($substituteTeacherId);
        if ($subUserId) {
            $this->send($subUserId, 'Substitution assigned',
                "You are covering {$label} on {$date}.", (int)$period['id']);
        }
        $absentUserId = $this->teacherUserId((int)$period['teacher_details_id']);
        if ($absentUserId) {
            $this->send($absentUserId, 'Your class is covered',
                "Your {$label} on {$date} will be covered by a colleague.", (int)$period['id']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    private function send(int $userId, string $title, string $body, int $refId): int
    {
        try {
            $notifier = Yii::$app->get('notification', false) ?: new FirebaseNotification();
            // Empty $type stores an in-app fcm_notifications row as well as pushing.
            $notifier->UserNotification('', $userId, $title, $body, '', self::TYPE, (string)$refId);
            return 1;
        } catch (\Throwable $e) {
            Yii::warning("Timetable push to user {$userId} failed: " . $e->getMessage(), 'timetable');
            return 0;
        }
    }

    private function teacherUserId(int $teacherDetailsId): ?int
    {
        $uid = (new Query())->select(['user_id'])->from('teacher_details')
            ->where(['id' => $teacherDetailsId])->scalar();
        return $uid ? (int)$uid : null;
    }

    private function enabled(): bool
    {
        return (bool)(Yii::$app->params['ai.timetable.push'] ?? true);
    }
}
