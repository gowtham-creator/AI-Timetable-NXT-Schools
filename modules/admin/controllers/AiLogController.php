<?php

namespace app\modules\admin\controllers;

use app\models\User;
use app\modules\admin\models\TimetableGenerationRun;
use Yii;
use yii\db\Query;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * AI Logs — read-only audit-trail viewer over the shared AI audit layer.
 *
 *   ai_invocations  → every LLM call (tool, model, prompt-hash, request/
 *                     response payload, tokens, latency, status)
 *   ai_proposals    → actions awaiting human approval
 *   timetable_generation_runs → one row per generation (draft → published)
 *
 * Read-only by design (AI-AUTOMATIONS §C.1): this controller never writes —
 * it surfaces exactly what the AI saw and produced so any disputed schedule
 * or message is traceable to the prompt + model version that created it.
 */
class AiLogController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class'   => VerbFilter::className(),
                'actions' => [], // all actions are read-only GETs
            ],
            'access' => [
                'class' => \yii\filters\AccessControl::className(),
                'rules' => [
                    [
                        'allow'   => true,
                        'actions' => ['index', 'view', 'run'],
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

    /** Audit dashboard — KPIs, filters, invocation list, recent runs. */
    public function actionIndex()
    {
        $campusId = $this->campusId();

        $tool   = trim((string)Yii::$app->request->get('tool', ''));
        $status = trim((string)Yii::$app->request->get('status', ''));
        $from   = trim((string)Yii::$app->request->get('from', ''));
        $to     = trim((string)Yii::$app->request->get('to', ''));

        $where = ['campus_id' => $campusId];
        $filter = static function (Query $q) use ($where, $tool, $status, $from, $to): Query {
            $q->where($where);
            if ($tool !== '')   { $q->andWhere(['tool_name' => $tool]); }
            if ($status !== '') { $q->andWhere(['status' => $status]); }
            if ($from !== '')   { $q->andWhere(['>=', 'created_on', $from . ' 00:00:00']); }
            if ($to !== '')     { $q->andWhere(['<=', 'created_on', $to . ' 23:59:59']); }
            return $q;
        };

        // KPIs (aggregate, filtered).
        $agg = $filter((new Query())->from('ai_invocations'))
            ->select([
                'calls'    => 'COUNT(*)',
                'ok'       => "SUM(CASE WHEN status='success' THEN 1 ELSE 0 END)",
                'tokens_in'  => 'COALESCE(SUM(tokens_in),0)',
                'tokens_out' => 'COALESCE(SUM(tokens_out),0)',
                'avg_ms'   => 'COALESCE(ROUND(AVG(latency_ms)),0)',
            ])->one(Yii::$app->db) ?: [];

        // Invocation list (most recent first).
        $rows = $filter((new Query())->from('ai_invocations'))
            ->select(['id', 'tool_name', 'model', 'status', 'tokens_in', 'tokens_out',
                'latency_ms', 'prompt_hash', 'created_on', 'error_message'])
            ->orderBy(['id' => SORT_DESC])->limit(100)->all(Yii::$app->db);

        // Distinct tool names for the filter dropdown.
        $tools = (new Query())->select('tool_name')->distinct()->from('ai_invocations')
            ->where($where)->orderBy(['tool_name' => SORT_ASC])->column(Yii::$app->db);

        // Recent generation runs + invocation→run map.
        $runs = TimetableGenerationRun::find()
            ->where(['campus_id' => $campusId])
            ->orderBy(['id' => SORT_DESC])->limit(15)->all();
        $invToRun = [];
        foreach ($runs as $r) {
            if ($r->ai_invocation_id) { $invToRun[(int)$r->ai_invocation_id] = (int)$r->id; }
        }

        return $this->render('index', [
            'agg' => $agg, 'rows' => $rows, 'tools' => $tools, 'runs' => $runs,
            'invToRun' => $invToRun,
            'filters' => ['tool' => $tool, 'status' => $status, 'from' => $from, 'to' => $to],
        ]);
    }

    /** One invocation — full request/response payload + linked proposals. */
    public function actionView($id)
    {
        $campusId = $this->campusId();
        $inv = (new Query())->from('ai_invocations')
            ->where(['id' => (int)$id, 'campus_id' => $campusId])->one(Yii::$app->db);
        if ($inv === false) {
            throw new NotFoundHttpException('Invocation not found.');
        }
        $proposals = (new Query())->from('ai_proposals')
            ->where(['invocation_id' => (int)$id])->orderBy(['id' => SORT_DESC])->all(Yii::$app->db);
        $run = TimetableGenerationRun::find()
            ->where(['campus_id' => $campusId, 'ai_invocation_id' => (int)$id])->one();

        return $this->render('view', ['inv' => $inv, 'proposals' => $proposals, 'run' => $run]);
    }

    /** A generation run — full audit chain (run + linked invocation + proposals). */
    public function actionRun($id)
    {
        $campusId = $this->campusId();
        $run = TimetableGenerationRun::find()
            ->where(['id' => (int)$id, 'campus_id' => $campusId])->one();
        if ($run === null) {
            throw new NotFoundHttpException('Run not found.');
        }
        $inv = null;
        if ($run->ai_invocation_id) {
            $inv = (new Query())->from('ai_invocations')
                ->where(['id' => (int)$run->ai_invocation_id])->one(Yii::$app->db);
        }
        $slotCount = $run->getSlots()->count();

        return $this->render('run', ['run' => $run, 'inv' => $inv, 'slotCount' => (int)$slotCount]);
    }

    private function campusId(): int
    {
        return (int)User::getCampusesByUser(Yii::$app->user->identity->id);
    }
}
