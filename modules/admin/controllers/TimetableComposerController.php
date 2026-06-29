<?php

namespace app\modules\admin\controllers;

use app\components\ai\TimetableComposer;
use app\components\ai\timetable\SubstituteFinder;
use app\models\User;
use app\modules\admin\models\AcademicYears;
use app\modules\admin\models\ClassSections;
use app\modules\admin\models\StudentClass;
use app\modules\admin\models\TeacherDetails;
use app\modules\admin\models\TimetableGenerationRun;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * AI Timetable Studio — generate, review and publish whole-school timetables,
 * and assign substitutes for absent teachers.
 *
 * The AI proposes; the coordinator approves. Nothing reaches the live
 * subject_timetable until "Publish" is clicked.
 */
class TimetableComposerController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'sections'         => ['get'],
                    'allocation'       => ['get'],
                    'generate'         => ['post'],
                    'publish'          => ['post'],
                    'discard'          => ['post'],
                    'apply-substitute' => ['post'],
                ],
            ],
            'access' => [
                'class' => \yii\filters\AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => [
                            'index', 'sections', 'allocation', 'generate', 'run',
                            'publish', 'discard', 'substitutes', 'apply-substitute',
                        ],
                        'matchCallback' => function () {
                            return User::isAdmin() || User::isInstituteAdmin()
                                || User::isCampusAdmin() || User::isCampusSubAdmin();
                        },
                    ],
                    ['allow' => false],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        $campusId = $this->campusId();

        $classes = StudentClass::find()
            ->where(['campus_id' => $campusId, 'status' => 1])
            ->orderBy(['title' => SORT_ASC])->all();
        $years = AcademicYears::find()
            ->where(['campus_id' => $campusId, 'status' => 1])
            ->orderBy(['id' => SORT_DESC])->all();
        $teachers = TeacherDetails::find()
            ->where(['campus_id' => $campusId])
            ->orderBy(['name' => SORT_ASC])->all();
        $runs = TimetableGenerationRun::find()
            ->where(['campus_id' => $campusId])
            ->orderBy(['id' => SORT_DESC])->limit(10)->all();

        return $this->render('index', [
            'classes'  => $classes,
            'years'    => $years,
            'teachers' => $teachers,
            'runs'     => $runs,
        ]);
    }

    /** Sections of a class (AJAX). */
    public function actionSections($class_id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $rows = ClassSections::find()
            ->select(['id', 'section_name'])
            ->where(['campus_id' => $this->campusId(), 'student_class_id' => (int)$class_id, 'status' => 1])
            ->orderBy(['section_name' => SORT_ASC])
            ->asArray()->all();
        if ($rows === []) {
            // Small school: this class has no sections — offer a single whole-class unit.
            return ['sections' => [['id' => -(int)$class_id, 'section_name' => 'Whole class (no sections)']],
                    'synthetic' => true];
        }
        return ['sections' => $rows, 'synthetic' => false];
    }

    /**
     * Teachers + the subject(s) each teaches, for a class's section(s) (AJAX GET).
     * Drives the intake step: Class -> Sections -> Teachers/Subjects -> Generate.
     */
    public function actionAllocation($class_id, $section_ids = '')
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $classId = (int)$class_id;
        // Campus ownership — works for no-section classes too (validates the class,
        // not class_sections, which a small school may not have).
        if (!StudentClass::find()->where(['id' => $classId, 'campus_id' => $this->campusId(), 'status' => 1])->exists()) {
            return ['ok' => false, 'message' => 'Class not found.', 'sections' => []];
        }
        $raw     = is_array($section_ids) ? $section_ids : array_filter(explode(',', (string)$section_ids));
        // Strip the negative synthetic sentinel — load() rebuilds the whole-class unit.
        $secIds  = array_values(array_filter(array_map('intval', $raw), static fn($i) => $i > 0));
        $yearId  = (int)Yii::$app->request->get('academic_year_id', 0);
        try {
            return (new \app\components\ai\timetable\TimetableDataLoader())
                ->loadClassAllocation($this->campusId(), $classId, $yearId, $secIds);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'sections' => []];
        }
    }

    /** Run the AI generation pipeline (AJAX). */
    public function actionGenerate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $post = Yii::$app->request->post();

        $classId = (int)($post['class_id'] ?? 0);
        $yearId  = (int)($post['academic_year_id'] ?? 0);
        if ($classId <= 0 || $yearId <= 0) {
            return ['ok' => false, 'message' => 'Pick a class and academic year first.'];
        }
        if (!StudentClass::find()->where(['id' => $classId, 'campus_id' => $this->campusId(), 'status' => 1])->exists()) {
            return ['ok' => false, 'message' => 'Class not found.'];
        }
        // Keep only real section ids; the negative whole-class sentinel means
        // "no specific sections" → solve the whole class in one pass.
        $sectionIds = array_values(array_filter(
            array_map('intval', (array)($post['section_ids'] ?? [])),
            static fn($i) => $i > 0
        ));
        $rules      = trim((string)($post['rules'] ?? ''));

        try {
            /** @var TimetableComposer $composer */
            $composer = Yii::createObject(TimetableComposer::class);
            $userId   = (int)Yii::$app->user->id;
            $out = $sectionIds === []
                ? $composer->generateClass($this->campusId(), $classId, $yearId, $rules, $userId)
                : $composer->generate($this->campusId(), $classId, $yearId, $sectionIds, $rules, $userId);
            return ['ok' => ($out['status'] ?? '') !== TimetableGenerationRun::STATUS_FAILED] + $out;
        } catch (\Throwable $e) {
            Yii::error('Timetable generate failed: ' . $e->getMessage(), 'ai');
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Render a run's grid (AJAX HTML partial). */
    public function actionRun($id)
    {
        /** @var TimetableComposer $composer */
        $composer = Yii::createObject(TimetableComposer::class);
        $display = $composer->loadRunForDisplay((int)$id);
        if ($display === null || (int)$display['run']->campus_id !== $this->campusId()) {
            throw new NotFoundHttpException('Run not found.');
        }
        return $this->renderPartial('_grid', $display);
    }

    public function actionPublish()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $runId = (int)Yii::$app->request->post('run_id');
        $run = TimetableGenerationRun::findOne($runId);
        if ($run === null || (int)$run->campus_id !== $this->campusId()) {
            return ['ok' => false, 'message' => 'Run not found.'];
        }
        /** @var TimetableComposer $composer */
        $composer = Yii::createObject(TimetableComposer::class);
        return $composer->publish($runId, (int)Yii::$app->user->id);
    }

    public function actionDiscard()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $runId = (int)Yii::$app->request->post('run_id');
        $run = TimetableGenerationRun::findOne($runId);
        if ($run === null || (int)$run->campus_id !== $this->campusId()) {
            return ['ok' => false, 'message' => 'Run not found.'];
        }
        /** @var TimetableComposer $composer */
        $composer = Yii::createObject(TimetableComposer::class);
        return ['ok' => $composer->discard($runId, (int)Yii::$app->user->id)];
    }

    /** Affected periods + ranked candidates for an absent teacher (AJAX). */
    public function actionSubstitutes($teacher_id, $date)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $teacherId = (int)$teacher_id;
        $date = date('Y-m-d', strtotime($date));

        $teacher = TeacherDetails::findOne($teacherId);
        if ($teacher === null || (int)$teacher->campus_id !== $this->campusId()) {
            return ['ok' => false, 'message' => 'Teacher not found.'];
        }

        $finder  = new SubstituteFinder();
        $periods = $finder->affectedPeriods($teacherId, $date);
        $out = [];
        foreach ($periods as $p) {
            $out[] = [
                'period'     => $p,
                'candidates' => $finder->candidates($p, $date),
            ];
        }
        return ['ok' => true, 'teacher' => $teacher->name, 'date' => $date, 'periods' => $out];
    }

    public function actionApplySubstitute()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $post = Yii::$app->request->post();
        $timetableRowId = (int)($post['timetable_id'] ?? 0);
        $substituteId   = (int)($post['substitute_id'] ?? 0);
        $teacherId      = (int)($post['teacher_id'] ?? 0);
        $date           = date('Y-m-d', strtotime((string)($post['date'] ?? '')));

        $finder = new SubstituteFinder();
        $period = null;
        foreach ($finder->affectedPeriods($teacherId, $date) as $p) {
            if ((int)$p['id'] === $timetableRowId) {
                $period = $p;
                break;
            }
        }
        if ($period === null || (int)$period['campus_id'] !== $this->campusId()) {
            return ['ok' => false, 'message' => 'Period not found.'];
        }
        try {
            $id = $finder->apply($period, $substituteId, $date, (int)Yii::$app->user->id);
            return ['ok' => true, 'id' => $id, 'message' => 'Substitute assigned & recorded.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function campusId(): int
    {
        return (int)User::getCampusesByUser(Yii::$app->user->identity->id);
    }
}
