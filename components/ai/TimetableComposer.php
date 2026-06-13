<?php

namespace app\components\ai;

use app\components\ai\timetable\ConstraintIntake;
use app\components\ai\timetable\SolverFixtures;
use app\components\ai\timetable\TimetableDataLoader;
use app\components\ai\timetable\TimetableSolver;
use app\modules\admin\models\SubjectTimetable;
use app\modules\admin\models\TimetableGenerationRun;
use Yii;
use yii\base\Component;
use yii\db\Query;

/**
 * TimetableComposer — orchestrates the AI timetable pipeline:
 *
 *   plain-English rules ──ConstraintIntake──▶ constraints JSON
 *   live school data    ──TimetableDataLoader──▶ solver input
 *   TimetableSolver     ──▶ clash-free week ──▶ draft run + slots (DB)
 *   coordinator reviews ──publish()──▶ subject_timetable (approval-first)
 *
 * Nothing touches the live timetable until publish() — the AI only ever
 * proposes; a human applies.
 */
class TimetableComposer extends Component
{
    /**
     * Generate a draft timetable run.
     *
     * @param int      $campusId
     * @param int      $classId
     * @param int      $academicYearId
     * @param int[]    $sectionIds  empty = all active sections of the class
     * @param string   $rulesText   plain-English constraints (may be '')
     * @param int|null $userId      acting user (null from console)
     */
    public function generate(int $campusId, int $classId, int $academicYearId,
                             array $sectionIds, string $rulesText, ?int $userId): array
    {
        $loader = new TimetableDataLoader();
        $loaded = $loader->load($campusId, $classId, $academicYearId, $sectionIds);

        $context = [
            'user_id'   => $userId,
            'campus_id' => $campusId,
        ];

        $intake      = new ConstraintIntake();
        $constraints = $intake->parse($rulesText, $loaded['maps'], $context);
        $input       = $loader->applyConstraints($loaded['input'], $constraints);

        // Pre-flight feasibility: catch over-subscribed grids / under-staffed
        // subjects BEFORE solving, so the coordinator gets a precise reason and
        // fix rather than a list of unplaced periods.
        $feasibility = \app\components\ai\timetable\FeasibilityAnalyzer::analyze($input);

        $result = TimetableSolver::solve($input);

        // Teacher consistency is seeded from last term's owners. If those
        // inherited assignments make the week unsolvable, retry once with a
        // clean slate and keep the better outcome.
        if (!$result['ok'] && !empty($input['teacher_map'])) {
            $freshInput = $input;
            $freshInput['teacher_map'] = [];
            $fresh = TimetableSolver::solve($freshInput);
            if ($fresh['stats']['unplaced_count'] < $result['stats']['unplaced_count']) {
                $result = $fresh;
                $loaded['warnings'][] = 'Last term\'s teacher-section assignments could not all be kept — '
                    . 'the AI re-assigned teachers to make the week solvable.';
            }
        }

        $run = new TimetableGenerationRun();
        $run->campus_id        = $campusId;
        $run->class_id         = $classId;
        $run->academic_year_id = $academicYearId;
        $run->section_ids      = json_encode(array_column($input['sections'], 'id'));
        $run->rules_text       = $rulesText;
        $run->constraints_json = json_encode([
            'constraints' => $constraints,
            'layout'      => $input['layout'],
            'days'        => $input['days'],
        ], JSON_UNESCAPED_UNICODE);
        $run->stats_json       = json_encode($result['stats']
            + ['warnings' => $loaded['warnings'], 'feasibility' => $feasibility], JSON_UNESCAPED_UNICODE);
        $run->ai_invocation_id = $constraints['_invocation_id'] ?? null;
        $run->status           = $result['ok'] ? TimetableGenerationRun::STATUS_DRAFT : TimetableGenerationRun::STATUS_FAILED;

        $tx = Yii::$app->db->beginTransaction();
        try {
            if (!$run->save()) {
                throw new \RuntimeException('Could not save run: ' . json_encode($run->getErrors()));
            }

            $rows = [];
            foreach ($result['slots'] as $s) {
                $rows[] = [
                    $run->id, $s['section_id'], $s['day'], $s['period'], 'academic',
                    $s['subject_id'], $s['sgs_id'], $s['teacher_id'], null,
                    $s['time_from'], $s['time_to'],
                ];
            }
            foreach ($result['structural'] as $s) {
                $rows[] = [
                    $run->id, $s['section_id'], $s['day'], null, $s['kind'],
                    null, null, null, $s['label'],
                    $s['time_from'], $s['time_to'],
                ];
            }
            if ($rows !== []) {
                Yii::$app->db->createCommand()->batchInsert(
                    'timetable_generation_slots',
                    ['run_id', 'section_id', 'day_id', 'period', 'kind',
                     'subject_id', 'subject_group_subject_id', 'teacher_details_id', 'label',
                     'time_from', 'time_to'],
                    $rows
                )->execute();
            }
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        $narrative = $this->narrate($result, $loaded['maps'], $context, $feasibility);
        if ($narrative !== null) {
            $run->narrative = $narrative;
            $run->save(false, ['narrative', 'updated_on', 'update_user_id']);
        }

        return [
            'run_id'      => (int)$run->id,
            'status'      => $run->status,
            'stats'       => $result['stats'],
            'warnings'    => $loaded['warnings'],
            'source'      => $constraints['_source'] ?? 'none',
            'narrative'   => $narrative,
            'feasibility' => $feasibility,
        ];
    }

    /**
     * Publish a draft run into subject_timetable (the human approval step).
     * Existing active rows for the run's scope are soft-deleted, then the
     * generated academic slots are inserted — all in one transaction.
     */
    public function publish(int $runId, ?int $userId): array
    {
        $run = TimetableGenerationRun::findOne($runId);
        if ($run === null) {
            return ['ok' => false, 'message' => 'Run not found', 'inserted' => 0, 'archived' => 0];
        }
        if ($run->status !== TimetableGenerationRun::STATUS_DRAFT) {
            return ['ok' => false, 'message' => "Run is '{$run->status}' — only drafts can be published", 'inserted' => 0, 'archived' => 0];
        }

        $slots = (new Query())
            ->from('timetable_generation_slots')
            ->where(['run_id' => $runId, 'kind' => 'academic'])
            ->all();
        if ($slots === []) {
            return ['ok' => false, 'message' => 'Run has no academic slots', 'inserted' => 0, 'archived' => 0];
        }

        // Defence in depth: re-validate clash-freedom before touching live data.
        $engineSlots = array_map(static fn($s) => [
            'section_id' => (int)$s['section_id'],
            'day'        => (int)$s['day_id'],
            'period'     => (int)$s['period'],
            'teacher_id' => (int)$s['teacher_details_id'],
        ], $slots);
        $clashes = TimetableSolver::validate($engineSlots);
        if ($clashes !== []) {
            return ['ok' => false, 'message' => 'Validation failed: ' . implode('; ', array_slice($clashes, 0, 3)), 'inserted' => 0, 'archived' => 0];
        }

        $sectionIds = $run->sectionIdList();
        $roomId     = $this->resolveRoomId($run->campus_id, $run->class_id, $sectionIds);
        $now        = date('Y-m-d H:i:s');

        $tx = Yii::$app->db->beginTransaction();
        try {
            // Archive (soft-delete) the current timetable for this scope.
            $archived = Yii::$app->db->createCommand()->update(
                'subject_timetable',
                ['status' => SubjectTimetable::STATUS_DELETE, 'updated_on' => $now, 'update_user_id' => $userId],
                [
                    'campus_id'        => $run->campus_id,
                    'class_id'         => $run->class_id,
                    'academic_year_id' => $run->academic_year_id,
                    'section_id'       => $sectionIds,
                    'status'           => SubjectTimetable::STATUS_ACTIVE,
                ]
            )->execute();

            // Insert the generated week. Bulk insert mirrors the manual flow's
            // columns; the whole run was validated atomically above, so the
            // per-row conflict guard is bypassed.
            //
            // day_id: live rows store day NAMES ('Monday') and the mobile APIs
            // filter with date('l') — write whatever format this campus's data
            // already uses (DayId auto-detects; defaults to names).
            $dayFormat = \app\components\ai\timetable\DayId::detectFormat($run->campus_id);
            $rows = [];
            foreach ($slots as $s) {
                $rows[] = [
                    $run->campus_id,
                    \app\components\ai\timetable\DayId::forCampus((int)$s['day_id'], $dayFormat),
                    $run->class_id,
                    (int)$s['section_id'],
                    $s['subject_group_subject_id'] !== null ? (int)$s['subject_group_subject_id'] : 0,
                    (int)$s['subject_id'],
                    (int)$s['teacher_details_id'],
                    $s['time_from'],
                    $s['time_to'],
                    $s['time_from'],            // start_time mirrors time_from (manual-flow parity)
                    $s['time_to'],              // end_time mirrors time_to
                    (int)$s['period'],
                    $roomId,
                    $run->academic_year_id,
                    SubjectTimetable::STATUS_ACTIVE,
                    $now, $now, $userId, $userId,
                ];
            }
            $inserted = Yii::$app->db->createCommand()->batchInsert(
                'subject_timetable',
                ['campus_id', 'day_id', 'class_id', 'section_id',
                 'subject_group_subject_id', 'subject_id', 'teacher_details_id',
                 'time_from', 'time_to', 'start_time', 'end_time', 'period',
                 'room_id', 'academic_year_id', 'status',
                 'created_on', 'updated_on', 'create_user_id', 'update_user_id'],
                $rows
            )->execute();

            $run->status       = TimetableGenerationRun::STATUS_PUBLISHED;
            $run->published_on = $now;
            $run->published_by = $userId;
            $run->save(false, ['status', 'published_on', 'published_by', 'updated_on', 'update_user_id']);

            $tx->commit();

            // Best-effort: tell affected teachers & parents on the mobile apps.
            // The apps read the published rows via their existing endpoints;
            // this is just the "look now" nudge. Never fails the publish.
            $push = (new \app\components\ai\timetable\TimetablePushService())
                ->notifyPublished((int)$run->campus_id, (int)$run->class_id, $sectionIds, $runId);

            return ['ok' => true, 'message' => 'Published', 'inserted' => $inserted, 'archived' => $archived,
                    'notified' => $push];
        } catch (\Throwable $e) {
            $tx->rollBack();
            Yii::error('Timetable publish failed: ' . $e->getMessage(), 'ai');
            return ['ok' => false, 'message' => 'Publish failed: ' . $e->getMessage(), 'inserted' => 0, 'archived' => 0];
        }
    }

    /** Mark a draft as discarded (kept for audit). */
    public function discard(int $runId, ?int $userId): bool
    {
        $run = TimetableGenerationRun::findOne($runId);
        if ($run === null || $run->status !== TimetableGenerationRun::STATUS_DRAFT) {
            return false;
        }
        $run->status = TimetableGenerationRun::STATUS_DISCARDED;
        return $run->save(false, ['status', 'updated_on', 'update_user_id']);
    }

    /**
     * Everything the studio view needs to render a run: the run row, its
     * layout, slots grouped per section, and display-name maps.
     */
    public function loadRunForDisplay(int $runId): ?array
    {
        $run = TimetableGenerationRun::findOne($runId);
        if ($run === null) {
            return null;
        }
        $slots = (new Query())
            ->from('timetable_generation_slots')
            ->where(['run_id' => $runId])
            ->orderBy(['section_id' => SORT_ASC, 'day_id' => SORT_ASC, 'period' => SORT_ASC])
            ->all();

        $cfg    = json_decode((string)$run->constraints_json, true) ?: [];
        $layout = $cfg['layout'] ?? SolverFixtures::defaultLayout();
        $days   = $cfg['days'] ?? SolverFixtures::defaultDays();

        $subjectIds = array_values(array_unique(array_filter(array_column($slots, 'subject_id'))));
        $teacherIds = array_values(array_unique(array_filter(array_column($slots, 'teacher_details_id'))));
        $sectionIds = array_values(array_unique(array_column($slots, 'section_id')));

        $subjectNames = $subjectIds === [] ? [] : (new Query())->select(['subject_name'])->from('subjects')
            ->where(['id' => $subjectIds])->indexBy('id')->column();
        $teacherNames = $teacherIds === [] ? [] : (new Query())->select(['name'])->from('teacher_details')
            ->where(['id' => $teacherIds])->indexBy('id')->column();
        $sectionNames = $sectionIds === [] ? [] : (new Query())->select(['section_name'])->from('class_sections')
            ->where(['id' => $sectionIds])->indexBy('id')->column();

        // Group: [section_id][day_id][period] => slot, structural by [day][time_from]
        $bySection = [];
        foreach ($slots as $s) {
            $sid = (int)$s['section_id'];
            if ($s['kind'] === 'academic') {
                $bySection[$sid]['academic'][(int)$s['day_id']][(int)$s['period']] = $s;
            } else {
                $bySection[$sid]['structural'][(int)$s['day_id']][$s['time_from']] = $s;
            }
        }

        return [
            'run'           => $run,
            'layout'        => $layout,
            'days'          => $days,
            'by_section'    => $bySection,
            'section_names' => $sectionNames,
            'subject_names' => $subjectNames,
            'teacher_names' => $teacherNames,
            'stats'         => $run->statsArray(),
        ];
    }

    /** Best-effort LLM narration of the result (Claude or Gemini, whichever is configured). */
    private function narrate(array $result, array $maps, array $context, array $feasibility = []): ?string
    {
        $llm = LlmRouter::resolve();
        if ($llm === null) {
            return $this->fallbackNarrative($result, $feasibility);
        }
        try {
            $template = file_get_contents(__DIR__ . '/prompts/timetable_narrate.txt');
            $loadByName = [];
            foreach ($result['teacher_load'] as $tid => $n) {
                $loadByName[$maps['teacher_names'][$tid] ?? ('Teacher #' . $tid)] = $n;
            }
            $data = [
                'stats'        => array_diff_key($result['stats'], ['clash_list' => 1]),
                'teacher_load' => $loadByName,
                'sections'     => array_values($maps['section_names']),
                // Give the LLM the precise infeasibility diagnosis so its
                // explanation names the real cause + fix, not just "unplaced".
                'feasibility'  => $feasibility['ok'] ?? true ? null : $feasibility['blockers'] ?? null,
            ];
            $system = strtr($template, ['{{DATA}}' => json_encode($data, JSON_UNESCAPED_UNICODE)]);

            $invId = AuditLogger::start('timetable_narrate', $context['user_id'] ?? null, null,
                $context['campus_id'] ?? null, $data, $llm['kind'] . ':' . $llm['model']);
            $out = LlmRouter::chat($llm, [['role' => 'user', 'content' => 'Explain this generated timetable.']], $system);
            AuditLogger::finish($invId, ['text' => $out['text']],
                $out['tokens_in'], $out['tokens_out'], $out['latency_ms'], 'success');
            return trim($out['text']) !== '' ? trim($out['text']) : $this->fallbackNarrative($result, $feasibility);
        } catch (\Throwable $e) {
            Yii::warning('Timetable narration failed: ' . $e->getMessage(), 'ai');
            return $this->fallbackNarrative($result, $feasibility);
        }
    }

    /** Deterministic narration when the LLM is unavailable. */
    private function fallbackNarrative(array $result, array $feasibility = []): string
    {
        $s = $result['stats'];
        $msg = "Generated {$s['placed']} of {$s['required']} periods ({$s['fill_pct']}% of the week) with {$s['clashes']} clashes.";
        if ($s['unplaced_count'] > 0) {
            // Prefer the precise feasibility diagnosis over a list of unplaced periods.
            if (!empty($feasibility) && empty($feasibility['ok'])) {
                $msg .= ' ' . \app\components\ai\timetable\FeasibilityAnalyzer::headline($feasibility);
            } else {
                $missing = array_slice($s['unplaced'], 0, 3);
                $parts = array_map(static fn($u) => $u['subject'] . ' (section ' . $u['section_id'] . ')', $missing);
                $msg .= ' Could not place: ' . implode(', ', $parts)
                    . '. Add teacher capacity for these subjects or reduce their weekly periods, then regenerate.';
            }
        } else {
            $msg .= ' Every subject met its weekly quota; teacher workloads are balanced across the week.';
        }
        return $msg;
    }

    /** Pick a sensible room id: most-used for the scope, else first campus room, else 0. */
    private function resolveRoomId(int $campusId, int $classId, array $sectionIds): int
    {
        try {
            $room = (new Query())->select(['room_id', 'cnt' => 'COUNT(*)'])
                ->from('subject_timetable')
                ->where(['campus_id' => $campusId, 'class_id' => $classId])
                ->andWhere(['section_id' => $sectionIds])
                ->andWhere(['not', ['room_id' => null]])
                ->groupBy(['room_id'])->orderBy(['cnt' => SORT_DESC])->limit(1)->one();
            if ($room && (int)$room['room_id'] > 0) {
                return (int)$room['room_id'];
            }
            $first = (new Query())->select(['id'])->from('class_rooms')
                ->where(['campus_id' => $campusId])->limit(1)->scalar();
            return $first ? (int)$first : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
