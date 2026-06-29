<?php

namespace app\components\ai\timetable;

use Yii;
use yii\db\Query;

/**
 * TimetableDataLoader — assembles solver input from live school data.
 *
 * Sources:
 *   sections  → class_sections (campus + class scope)
 *   subjects  → subject_groups_class_sections → subject_group_subjects → subjects
 *   teachers  → teacher_details, with per-subject competence inferred from
 *               historical subject_timetable rows (who has taught what here)
 *
 * Weekly quotas / max-per-day / after-lunch flags are seeded from
 * SolverFixtures::profileSubject() and can be overridden by the constraints
 * JSON produced by ConstraintIntake (plain-English rules).
 */
class TimetableDataLoader
{
    /**
     * @param int   $campusId
     * @param int   $classId        student_class.id
     * @param int   $academicYearId
     * @param int[] $sectionIds     empty = every active section of the class
     * @return array{input:array,maps:array,warnings:array}
     */
    public function load(int $campusId, int $classId, int $academicYearId, array $sectionIds = []): array
    {
        $warnings = [];

        // ── Sections (dynamic; a small school may have none) ─────────────────
        $sectionQuery = (new Query())
            ->select(['id', 'section_name'])
            ->from('class_sections')
            ->where(['campus_id' => $campusId, 'student_class_id' => $classId, 'status' => 1]);
        if ($sectionIds !== []) {
            $sectionQuery->andWhere(['id' => $sectionIds]);
        }
        $sectionRows = $sectionQuery->orderBy(['section_name' => SORT_ASC])->all();

        $sections = [];
        foreach ($sectionRows as $r) {
            $sections[] = ['id' => (int)$r['id'], 'name' => (string)$r['section_name'], 'synthetic' => false];
        }
        if ($sections === []) {
            // Small school: this class has no sections — treat the WHOLE CLASS as
            // one timetable unit. A negative sentinel id keeps it distinct from any
            // real class_sections row; publish() falls back to run->class_id.
            $warnings[] = 'No sections defined for this class — treating the whole class as one timetable unit.';
            $sections   = [['id' => -$classId, 'name' => '(Whole class)', 'synthetic' => true]];
            $secIdList  = [];
        } else {
            $secIdList = array_column($sections, 'id');
        }

        // ── Subjects per section (different sections may use different groups) ─
        $rawBySection = []; // secId => [ subjectId => ['subject_id','sgs_id','subject_name'] ]
        if ($secIdList !== []) {
            $rows = (new Query())
                ->select(['cs' => 'sgcs.class_sections_id', 'sgs_id' => 'sgs.id',
                    'subject_id' => 's.id', 's.subject_name'])
                ->from(['sgcs' => 'subject_groups_class_sections'])
                ->innerJoin(['sg' => 'subject_groups'], 'sg.id = sgcs.subject_group_id')
                ->innerJoin(['sgs' => 'subject_group_subjects'], 'sgs.subject_group_id = sg.id')
                ->innerJoin(['s' => 'subjects'], 's.id = sgs.subject_id')
                ->where(['sgcs.class_sections_id' => $secIdList])
                ->andWhere(['sgcs.status' => 1, 'sg.status' => 1, 'sgs.status' => 1, 's.status' => 1])
                ->all();
            foreach ($rows as $r) {
                $rawBySection[(int)$r['cs']][(int)$r['subject_id']] = [
                    'subject_id'   => (int)$r['subject_id'],
                    'sgs_id'       => $r['sgs_id'] !== null ? (int)$r['sgs_id'] : null,
                    'subject_name' => (string)$r['subject_name'],
                ];
            }
        }

        // Campus-wide fallback subject list (synthetic class, or a section with no
        // subject-group linkage). Lazily loaded once.
        $campusSubjects   = null;
        $campusSubjectList = function () use (&$campusSubjects, $campusId): array {
            if ($campusSubjects === null) {
                $campusSubjects = [];
                foreach ((new Query())->select(['subject_id' => 's.id', 's.subject_name'])
                             ->from(['s' => 'subjects'])
                             ->where(['s.campus_id' => $campusId, 's.status' => 1])->all() as $r) {
                    $campusSubjects[(int)$r['subject_id']] = [
                        'subject_id'   => (int)$r['subject_id'],
                        'sgs_id'       => null,
                        'subject_name' => (string)$r['subject_name'],
                    ];
                }
            }
            return $campusSubjects;
        };

        // ── Teacher pool + competence/dominant from timetable history ────────
        $teacherRows = (new Query())->select(['id', 'name'])->from('teacher_details')
            ->where(['campus_id' => $campusId])->all();
        if ($teacherRows === []) {
            throw new \RuntimeException('No teachers found for this campus — add teacher profiles first.');
        }
        $allTeacherIds = array_map(static fn($t) => (int)$t['id'], $teacherRows);
        $teacherIdSet  = array_flip($allTeacherIds);

        $historyRows = [];
        try {
            $q = (new Query())
                ->select(['subject_id', 'teacher_details_id', 'section_id', 'cnt' => 'COUNT(*)'])
                ->from('subject_timetable')
                ->where(['campus_id' => $campusId, 'status' => 1])
                ->andWhere(['not', ['subject_id' => null]]);
            if ($academicYearId > 0) {
                // Scope competence + last-owner seed to the selected year's actual
                // schedule. Legacy rows with no year fall through to the pool fallback.
                $q->andWhere(['academic_year_id' => $academicYearId]);
            }
            $historyRows = $q->groupBy(['subject_id', 'teacher_details_id', 'section_id'])->all();
        } catch (\Throwable $e) {
            $warnings[] = 'Could not read teaching history (' . $e->getMessage() . ') — pooling all teachers per subject.';
        }
        $competence      = []; // [subjectId]        => tid[]  (campus-wide)
        $competenceBySec = []; // [secId][subjectId] => tid[]  (this section only)
        $dominant        = []; // [secId][subjectId] => ['tid','cnt']  (last term's owner)
        foreach ($historyRows as $h) {
            $sid   = (int)$h['subject_id'];
            $tid   = (int)$h['teacher_details_id'];
            $secId = (int)$h['section_id'];
            $cnt   = (int)$h['cnt'];
            $competence[$sid][] = $tid;
            $competenceBySec[$secId][$sid][] = $tid;
            if (!isset($dominant[$secId][$sid]) || $cnt > $dominant[$secId][$sid]['cnt']) {
                $dominant[$secId][$sid] = ['tid' => $tid, 'cnt' => $cnt];
            }
        }

        // Viable teacher pool for a (section, subject): prefer this section's
        // history, then campus-wide history, then the whole pool (the solver
        // balances load and guarantees no double-booking).
        $poolFor = function (int $secId, int $sid) use ($competenceBySec, $competence, $allTeacherIds): array {
            $bySec = array_values(array_unique(array_intersect($competenceBySec[$secId][$sid] ?? [], $allTeacherIds)));
            if ($bySec !== []) {
                return $bySec;
            }
            $campus = array_values(array_unique(array_intersect($competence[$sid] ?? [], $allTeacherIds)));
            return $campus !== [] ? $campus : $allTeacherIds;
        };

        // ── Build each section's subject list + a deduped top-level union ─────
        $unionSubjects = [];
        foreach ($sections as &$section) {
            $secId   = (int)$section['id'];
            $rawList = $rawBySection[$secId] ?? [];
            if ($rawList === []) {
                $rawList = $campusSubjectList(); // synthetic class / no group linkage
            }
            $secSubjects = [];
            foreach ($rawList as $raw) {
                $sid     = (int)$raw['subject_id'];
                $name    = (string)$raw['subject_name'];
                $profile = SolverFixtures::profileSubject($name);
                $secSubjects[] = [
                    'id'               => $sid,
                    'sgs_id'           => $raw['sgs_id'],
                    'name'             => $name,
                    'per_week'         => $profile['per_week'],
                    'max_per_day'      => $profile['max_per_day'],
                    'after_lunch_only' => $profile['after_lunch_only'],
                    'teacher_ids'      => $poolFor($secId, $sid),
                ];
                if (!isset($unionSubjects[$sid])) {
                    $pool = array_values(array_unique(array_intersect($competence[$sid] ?? [], $allTeacherIds)));
                    $unionSubjects[$sid] = [
                        'id'               => $sid,
                        'sgs_id'           => $raw['sgs_id'],
                        'name'             => $name,
                        'per_week'         => $profile['per_week'],
                        'max_per_day'      => $profile['max_per_day'],
                        'after_lunch_only' => $profile['after_lunch_only'],
                        'teacher_ids'      => $pool !== [] ? $pool : $allTeacherIds,
                    ];
                }
            }
            $section['subjects'] = $secSubjects;
        }
        unset($section);

        if ($unionSubjects === []) {
            throw new \RuntimeException('No active subjects found for this campus — create subjects first.');
        }
        // Warn once if NO real section had a linked subject group (all fell back).
        $anyLinked = false;
        foreach ($secIdList as $sid2) {
            if (!empty($rawBySection[$sid2])) {
                $anyLinked = true;
                break;
            }
        }
        if ($secIdList !== [] && !$anyLinked) {
            $warnings[] = 'No subject group is linked to these sections — using all campus subjects. '
                . 'Link a subject group for tighter results.';
        }
        $subjects = array_values($unionSubjects);

        $teachers = [];
        foreach ($teacherRows as $t) {
            $teachers[] = [
                'id'           => (int)$t['id'],
                'name'         => (string)$t['name'],
                'morning_only' => false,
                // Real workload sheets run 42-44 periods/week (~8/day) —
                // override per teacher via plain-English rules when lower.
                'max_per_day'  => 8,
                'max_per_week' => 44,
                'unavailable'  => [],
            ];
        }

        // Teacher consistency seed: keep last term's (section, subject) owner when
        // they're still on this campus. Real sections only — a synthetic whole-class
        // unit has no section history, so the solver assigns fresh (correct).
        $teacherMap = [];
        foreach ($sections as $section) {
            $secId = (int)$section['id'];
            if ($secId < 0) {
                continue;
            }
            foreach ($section['subjects'] as $sub) {
                $sid = (int)$sub['id'];
                $d   = $dominant[$secId][$sid] ?? null;
                if ($d !== null && isset($teacherIdSet[$d['tid']])) {
                    $teacherMap[$secId][$sid] = $d['tid'];
                }
            }
        }

        $input = [
            'days'        => SolverFixtures::defaultDays(),
            'layout'      => SolverFixtures::defaultLayout(),
            'sections'    => $sections,
            'subjects'    => $subjects,
            'teachers'    => $teachers,
            'teacher_map' => $teacherMap,
        ];

        $maps = [
            'section_names' => array_column($sections, 'name', 'id'),
            'subject_names' => array_column($subjects, 'name', 'id'),
            'teacher_names' => array_column($teachers, 'name', 'id'),
        ];

        return ['input' => $input, 'maps' => $maps, 'warnings' => $warnings];
    }

    /**
     * Intake-screen data: Class -> Sections -> Teachers -> Subject(s) each teaches.
     * Derived FROM load() so the display matches exactly what generate() will solve.
     * Returns the ALLOCATION shape (DATA CONTRACT 1.6); a teacher can appear with
     * more than one subject. Display-only — no solve, no writes.
     *
     * @return array{ok:bool,class_id:int,has_sections:bool,sections:array,warnings:array}
     */
    public function loadClassAllocation(int $campusId, int $classId, int $academicYearId, array $sectionIds = []): array
    {
        try {
            $loaded = $this->load($campusId, $classId, $academicYearId, $sectionIds);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(),
                'class_id' => $classId, 'has_sections' => false, 'sections' => [], 'warnings' => []];
        }

        $input      = $loaded['input'];
        $names      = $loaded['maps']['teacher_names'];
        $teacherMap = $input['teacher_map'] ?? [];
        $nameOf     = static fn($tid) => $names[(int)$tid] ?? ('#' . (int)$tid);

        $hasSections = true;
        $outSections = [];
        foreach ($input['sections'] as $sec) {
            $secId = (int)$sec['id'];
            if (!empty($sec['synthetic'])) {
                $hasSections = false;
            }

            $subjectsOut = [];
            $byTeacher   = []; // tid => ['id','name','subjects'=>[sid=>['id','name']]]
            foreach (($sec['subjects'] ?? []) as $sub) {
                $sid       = (int)$sub['id'];
                $ownerTid  = $teacherMap[$secId][$sid] ?? null;
                $teacherIds = array_map('intval', $sub['teacher_ids'] ?? []);

                $subjectsOut[] = [
                    'subject_id'    => $sid,
                    'subject_name'  => $sub['name'],
                    'teacher_id'    => $ownerTid !== null ? (int)$ownerTid : null,
                    'teacher_name'  => $ownerTid !== null ? $nameOf($ownerTid) : null,
                    'teacher_ids'   => $teacherIds,
                    'teacher_names' => array_map($nameOf, $teacherIds),
                ];

                // teacher-centric: pinned owner if known, else every viable teacher.
                foreach (($ownerTid !== null ? [(int)$ownerTid] : $teacherIds) as $tid) {
                    if (!isset($byTeacher[$tid])) {
                        $byTeacher[$tid] = ['id' => $tid, 'name' => $nameOf($tid), 'subjects' => []];
                    }
                    $byTeacher[$tid]['subjects'][$sid] = ['id' => $sid, 'name' => $sub['name']];
                }
            }

            $teachersOut = [];
            foreach ($byTeacher as $t) {
                $teachersOut[] = ['id' => $t['id'], 'name' => $t['name'], 'subjects' => array_values($t['subjects'])];
            }

            $outSections[] = [
                'id'        => $secId,
                'name'      => (string)$sec['name'],
                'synthetic' => !empty($sec['synthetic']),
                'subjects'  => $subjectsOut,
                'teachers'  => $teachersOut,
            ];
        }

        return [
            'ok'           => true,
            'class_id'     => $classId,
            'has_sections' => $hasSections,
            'sections'     => $outSections,
            'warnings'     => $loaded['warnings'],
        ];
    }

    /**
     * WHOLE-SCHOOL load: every active class of the campus as one flat section
     * list, each section carrying its OWN per-class subjects + teacher pools,
     * so the solver can run all classes together and prevent cross-class
     * teacher double-booking (the coordinator's "Kishore can't be in 6A and 7A
     * at once" requirement). Teachers are one campus-wide pool.
     *
     * Reuses load() per class (each class scopes its own subject group), then
     * merges. A class that can't be loaded (no sections/subjects) is skipped
     * with a warning rather than failing the whole school.
     *
     * @return array{input:array,maps:array,warnings:array}
     */
    public function loadSchool(int $campusId, int $academicYearId, array $opts = []): array
    {
        $classes = (new Query())
            ->select(['id', 'title'])
            ->from('student_class')
            ->where(['campus_id' => $campusId, 'status' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        if ($classes === []) {
            throw new \RuntimeException('No active classes found for this campus.');
        }

        $allSections = [];
        $teacherMap  = [];
        $warnings    = [];
        $maps        = ['section_names' => [], 'subject_names' => [], 'teacher_names' => []];
        $teachers    = null;
        $layout      = SolverFixtures::defaultLayout();
        $days        = SolverFixtures::defaultDays();

        foreach ($classes as $cls) {
            try {
                $one = $this->load($campusId, (int)$cls['id'], $academicYearId);
            } catch (\Throwable $e) {
                $warnings[] = 'Class ' . $cls['title'] . ' skipped: ' . $e->getMessage();
                continue;
            }
            $teachers = $one['input']['teachers']; // campus-wide, identical each class
            $maps['teacher_names'] = $one['maps']['teacher_names'];
            $classTitle = (string)$cls['title'];

            foreach ($one['input']['sections'] as $sec) {
                $sec['class'] = $classTitle;
                $sec['name']  = $classTitle . $sec['name'];              // "6" + "A" → "6A"
                // load() now sets per-section subjects; keep them, fall back to the
                // class union only if a section somehow has none.
                if (empty($sec['subjects'])) {
                    $sec['subjects'] = $one['input']['subjects'];
                }
                $allSections[] = $sec;
                $maps['section_names'][$sec['id']] = $sec['name'];
            }
            foreach (($one['input']['teacher_map'] ?? []) as $secId => $bySub) {
                $teacherMap[$secId] = $bySub;                            // section ids globally unique
            }
            $maps['subject_names'] += $one['maps']['subject_names'];
            $warnings = array_merge($warnings, $one['warnings']);
        }

        if ($allSections === []) {
            throw new \RuntimeException('No class could be loaded. ' . implode(' ', $warnings));
        }

        $input = [
            'days'        => $days,
            'layout'      => $layout,
            'sections'    => $allSections,
            'subjects'    => $allSections[0]['subjects'], // fallback only; every section overrides
            'teachers'    => $teachers,
            'teacher_map' => $teacherMap,
        ];
        return ['input' => $input, 'maps' => $maps, 'warnings' => $warnings];
    }

    /**
     * Overlay a constraints array (from ConstraintIntake or saved JSON) onto
     * solver input. Unknown keys are ignored; matching is by id or
     * case-insensitive name substring.
     */
    public function applyConstraints(array $input, array $constraints): array
    {
        // "Start fresh" switch: drop last term's (section, subject) teacher
        // ownership and let the solver re-assign from scratch.
        if (isset($constraints['keep_existing_teachers']) && !$constraints['keep_existing_teachers']) {
            $input['teacher_map'] = [];
        }

        if (isset($constraints['days']) && is_array($constraints['days']) && $constraints['days'] !== []) {
            $days = array_values(array_unique(array_map('intval', $constraints['days'])));
            $days = array_values(array_filter($days, static fn($d) => $d >= 1 && $d <= 7));
            if ($days !== []) {
                sort($days);
                $input['days'] = $days;
            }
        }

        if (isset($constraints['layout']) && is_array($constraints['layout']) && $constraints['layout'] !== []) {
            $clean = [];
            foreach ($constraints['layout'] as $col) {
                if (!isset($col['kind'], $col['time_from'], $col['time_to'])) {
                    continue;
                }
                $kind = (string)$col['kind'];
                if (!in_array($kind, ['period', 'assembly', 'break', 'lunch', 'activity'], true)) {
                    continue;
                }
                $entry = [
                    'kind'      => $kind,
                    'time_from' => (string)$col['time_from'],
                    'time_to'   => (string)$col['time_to'],
                ];
                if ($kind === 'period') {
                    $entry['no'] = (int)($col['no'] ?? 0);
                } else {
                    $entry['label'] = (string)($col['label'] ?? ucfirst($kind));
                }
                $clean[] = $entry;
            }
            if ($clean !== []) {
                $input['layout'] = $clean;
            }
        }

        foreach (($constraints['subjects'] ?? []) as $rule) {
            // Overlay the top-level union...
            foreach ($input['subjects'] as &$sub) {
                if ($this->matches($rule, $sub, 'subject_id')) {
                    $this->applySubjectRule($sub, $rule);
                }
            }
            unset($sub);
            // ...and every section's own subject list (per-section overrides take effect).
            foreach ($input['sections'] as &$section) {
                foreach (($section['subjects'] ?? []) as &$secSub) {
                    if ($this->matches($rule, $secSub, 'subject_id')) {
                        $this->applySubjectRule($secSub, $rule);
                    }
                }
                unset($secSub);
            }
            unset($section);
        }

        foreach (($constraints['teachers'] ?? []) as $rule) {
            foreach ($input['teachers'] as &$t) {
                if (!$this->matches($rule, $t, 'teacher_id')) {
                    continue;
                }
                if (isset($rule['morning_only'])) {
                    $t['morning_only'] = (bool)$rule['morning_only'];
                }
                if (isset($rule['max_per_day'])) {
                    $t['max_per_day'] = max(1, min(8, (int)$rule['max_per_day']));
                }
                if (isset($rule['max_per_week'])) {
                    $t['max_per_week'] = max(1, min(48, (int)$rule['max_per_week']));
                }
                if (isset($rule['unavailable']) && is_array($rule['unavailable'])) {
                    foreach ($rule['unavailable'] as $u) {
                        if (isset($u['day'], $u['period'])) {
                            $t['unavailable'][] = ['day' => (int)$u['day'], 'period' => (int)$u['period']];
                        }
                    }
                }
            }
            unset($t);
        }

        return $input;
    }

    /** Overlay one subject rule onto a subject row (top-level or per-section). */
    private function applySubjectRule(array &$sub, array $rule): void
    {
        if (isset($rule['per_week'])) {
            $sub['per_week'] = max(0, min(15, (int)$rule['per_week']));
        }
        if (isset($rule['max_per_day'])) {
            $sub['max_per_day'] = max(1, min(4, (int)$rule['max_per_day']));
        }
        if (isset($rule['after_lunch_only'])) {
            $sub['after_lunch_only'] = (bool)$rule['after_lunch_only'];
        }
        if (isset($rule['teacher_ids']) && is_array($rule['teacher_ids']) && $rule['teacher_ids'] !== []) {
            $sub['teacher_ids'] = array_map('intval', $rule['teacher_ids']);
        }
    }

    /** Match a constraint rule to a subject/teacher row by id or name substring. */
    private function matches(array $rule, array $row, string $idKey): bool
    {
        if (isset($rule[$idKey])) {
            return (int)$rule[$idKey] === (int)$row['id'];
        }
        if (isset($rule['id'])) {
            return (int)$rule['id'] === (int)$row['id'];
        }
        if (isset($rule['name_like']) && $rule['name_like'] !== '') {
            return stripos($row['name'], (string)$rule['name_like']) !== false;
        }
        return false;
    }
}
