# AI Timetable — Developer Integration Guide

Adds the **AI Timetable** feature (generate → review → publish + substitute finder)
to the NXT School ERP (Yii2). The AI only *proposes*; nothing touches the live
`subject_timetable` until a coordinator clicks **Publish**.

Verified before handoff: `php tests/timetable_solver_test.php` → **27/27 checks PASS**,
including a phase calibrated against a REAL school's period-allocation and
teacher-wise workload sheets: 5 sections × 54 periods on a 6-day week —
270/270 placed, 100% grid fill, 0 clashes, teacher caps 44/week honored.

**Teacher consistency (key behaviour):** one teacher owns each
(section × subject) for the entire week — exactly like the school's manual
workload sheet (e.g. *Aruna — Maths: 6E 10, 6S 10, 6D 10*). The solver
enforces this as a hard constraint:
- pre-assignments can be passed via `input['teacher_map']` (section → subject → teacher);
- the DataLoader seeds it automatically from each (section, subject)'s
  dominant teacher in `subject_timetable` history (continuity across terms);
- the constraints flag `{"keep_existing_teachers": false}` starts fresh;
- if inherited assignments make the week unsolvable, TimetableComposer
  auto-retries with a clean slate and records a warning on the run.
Teacher defaults match real workloads: **8 periods/day, 44/week** (override
per teacher via plain-English rules). Subject profiles cover techno-school
staples: IIT/foundation subjects, Reasoning, Sci/Mat Labs, ATL, Karate, Yoga.

---

## 1. What to copy (`new-files/` — drop in as-is, paths preserved)

| File | Purpose |
|---|---|
| `components/ai/timetable/TimetableSolver.php` | Constraint solver engine (framework-free PHP). Hard guarantees: no teacher/section/room double-booking, teacher daily/weekly caps, after-lunch-only subjects (PT), morning-only teachers, subject max-per-day. |
| `components/ai/timetable/SolverFixtures.php` | Default school-day layout (assembly 08:00, snack 09:50, lunch 12:25, sports 14:45), subject profiling heuristics, offline test fixture. |
| `components/ai/timetable/TimetableDataLoader.php` | Builds solver input from live data: `class_sections`, `subject_groups_class_sections` → `subject_group_subjects` → `subjects`, `teacher_details` + competence from `subject_timetable` history. Applies/clamps constraint overlays. |
| `components/ai/timetable/ConstraintIntake.php` | Plain-English rules → constraints JSON via Claude (`ai` component, audited, PII-redacted). **Deterministic keyword fallback** when no API key — feature works without AI configured. |
| `components/ai/timetable/ConflictChecker.php` | Pure-SQL pre-flight: teacher/section/room overlap detection for a `SubjectTimetable` row. |
| `components/ai/timetable/SubstituteFinder.php` | Absent teacher + date → affected periods + ranked candidates (free → same-subject → lightest 30-day cover load). `apply()` writes `temporary_assign_teacher` (dashboard-widget compatible, sets `replaced_teacher_detail_id`). |
| `components/ai/TimetableComposer.php` | Orchestrator: generate (→ draft run + slots, transactional), LLM narration with fallback, `publish()` (archive scope + bulk-insert new week, re-validates clash-freedom first), `discard()`, `loadRunForDisplay()`. |
| `components/ai/prompts/timetable_intake.txt` | Intake system prompt (rules → strict JSON). |
| `components/ai/prompts/timetable_narrate.txt` | Narration system prompt. |
| `migrations/m260611_050000_create_ai_timetable_tables.php` | Creates `timetable_generation_runs`, `timetable_generation_slots`; creates `ai_invocations`/`ai_proposals` **only if missing**. |
| `modules/admin/models/TimetableGenerationRun.php` | AR model, draft/published/discarded/failed lifecycle. |
| `modules/admin/models/TimetableGenerationSlot.php` | AR model, academic + structural (assembly/break/lunch/activity) slots. |
| `modules/admin/controllers/TimetableComposerController.php` | Studio endpoints: index, sections, generate, run (grid partial), publish, discard, substitutes, apply-substitute. Access mirrors `SubjectTimetableController` (Admin / InstituteAdmin / CampusAdmin / CampusSubAdmin). |
| `modules/admin/views/timetable-composer/index.php` | AI Timetable Studio page (pickers, rules textarea, stats chips, narrative, publish/discard, substitute panel, run history). |
| `modules/admin/views/timetable-composer/_grid.php` | Week-grid preview partial (per section; structural bands shaded). |
| `commands/TimetableController.php` | Console: `yii timetable/solver-test` (no DB), `yii timetable/generate`, `yii timetable/publish`. |
| `tests/timetable_solver_test.php` | Standalone verification, **no Yii bootstrap, no DB**: `php tests/timetable_solver_test.php`. |

## 2. Small edits to existing files (`modified-files/`)

1. **`modules/admin/models/SubjectTimetable.php`** — the override class was empty;
   the included copy adds the conflict pre-flight `beforeSave()` (blocks
   teacher/section/room double-booking on manual saves; only fires when
   scheduling fields change, so legacy rows stay editable; `SubjectTimetable::$conflictGuard = false`
   bypasses it for trusted bulk flows). If your production copy of this file is
   still empty, drop it in; otherwise merge the class body.

2. **`config/console.php`** — register the AI client (3 lines, see `nav-and-console.patch`):
   ```php
   'ai' => [
       'class' => 'app\components\ai\AIClient',
   ],
   ```
   (`config/web.php` already registers it in this codebase — verify production does too.)

3. **`modules/admin/views/partials/nav.php`** — add the menu entry (4 lines, see patch),
   under *Academics → Subject Management*, right after "Subjects Time Table":
   ```json
   {
     "name": "AI Timetable Studio",
     "url": "' . $baseUrl . '/timetable-composer"
   }
   ```

> `nav-and-console.patch` contains the exact 7-line diff for items 2 & 3
> (generated with `--ignore-cr-at-eol`; the repo copies of these files have CRLF endings).

## 3. Dependencies / prerequisites

- Relies on the existing `components/ai/` layer already in this codebase:
  `AIClient.php`, `AuditLogger.php`, `PiiRedactor.php`, `tools/`. If production
  doesn't have these yet, copy that folder across first (the migration creates
  the `ai_invocations` / `ai_proposals` tables they write to).
- **AI provider — three supported modes** (see `modified-files/env.example`):
  | Mode | .env | Cost |
  |---|---|---|
  | Google **Gemini** | `GEMINI_API_KEY=` (+`GEMINI_MODEL=gemini-2.0-flash`) | free tier at aistudio.google.com |
  | Anthropic **Claude** | `ANTHROPIC_API_KEY=` | paid |
  | **No key at all** | — | ₹0 — built-in keyword parser + deterministic narration |
  `components/ai/LlmRouter.php` picks automatically (Claude → Gemini → none).
  The LLM only ever parses rule text and writes explanations — **the timetable
  itself is always produced by the deterministic solver**, so quality of the
  schedule is identical in all three modes.

## 3b. Standalone demo (`standalone-demo/index.php`)

A single-file UI around the real engine — for demos and for developers to see
expected behaviour. No Yii, no DB, no key:

```bash
cd standalone-demo && php -S localhost:8088   # then open http://localhost:8088
```
It is intentionally NOT part of the marketing website; the website demo no
longer contains this feature at all.
- PHP 7.x/8.0 on the server is fine; the new code avoids PHP 8.1+-only syntax
  except `match` in `commands/TimetableController.php` (PHP 8.0+). If production
  runs PHP 7.x, replace that one `match` with a `switch` (5 lines).

## 3c. Mobile apps — parent & teacher (Flutter)

**The data path needs ZERO app changes.** The apps already read
`subject_timetable` through their existing endpoints
(`teacher/time-table`, `teacher/class-wise-time-table`,
`parent/student-class-time-table`) and already merge
`temporary_assign_teacher` for substitutions. The moment `publish()` runs,
both apps show the AI-generated week.

Two integration details ship in this package:

1. **day_id format (critical):** live `subject_timetable` rows store day
   NAMES ('Monday') and the mobile APIs filter with `date('l')`.
   `components/ai/timetable/DayId.php` auto-detects the campus's stored
   format and `publish()` writes the matching one — without this, published
   timetables would be invisible to the apps. Nothing to configure.

2. **Push notifications:** `components/ai/timetable/TimetablePushService.php`
   pushes through the ERP's existing `FirebaseNotification` component
   (same `fcm_notifications` + device-token path every other push uses):
   - on **publish** → every affected teacher + every parent in the sections
   - on **substitution** → the covering teacher and the absent teacher
   Notification `notificationType` = `'timetable'`. Disable globally with
   `$params['ai.timetable.push'] = false;`. Push failures never affect the
   publish transaction.

**App-side (already applied to the dev app copy; files in `mobile-app/`):**
two additive edits make the `'timetable'` push deep-link to the timetable
screen — a new `case 'timetable':` in the notification-type switch of
- `lib/teacherViews/homePage/notification_teacher/notification_page.dart`
  → `timetable(isInClassDetails: false)`
- `lib/views/notification/notification.dart`
  → `Timetable(studntDetails: widget.studntDetails)`
Verified with `dart analyze` (0 errors). Without these edits everything still
works — the push just lands on the notification list instead of deep-linking.

## 3d. AI pipeline

The complete AI engineering reference — every prompt, model call, guardrail,
audit row, cost profile, and the RAG roadmap — is in
**`new-files/components/ai/AI-PIPELINE.md`**. Headline: the LLM only parses
rule text and writes explanations; the schedule itself always comes from the
deterministic solver, every call is audited in `ai_invocations`, and the
whole feature works with no API key at all.

## 4. Deploy steps

```bash
php yii migrate                    # creates the 4 tables (ai_* only if missing)
php yii timetable/solver-test      # engine self-check on the server — expect ALL CHECKS PASSED
# log in as campus admin → Academics → Subject Management → AI Timetable Studio
```

Smoke test on real data (safe — drafts only):
```bash
php yii timetable/generate --campus=1 --class=<student_class.id> --year=<academic_years.id>
# review the draft in the Studio ("View grid"), then publish from the UI
```

## 5. Data mapping notes (for review)

- Published rows mirror the manual flow's columns: `campus_id, day_id (1=Mon),
  class_id, section_id, subject_group_subject_id, subject_id, teacher_details_id,
  time_from, time_to, start_time, end_time, period, room_id, academic_year_id, status`.
- Publish archives the scope first: existing ACTIVE `subject_timetable` rows for
  (campus, class, year, run's sections) → `status = 2` (soft delete), then inserts —
  one transaction, rolled back together on any failure.
- `room_id` defaults to the scope's most-used room, else the campus's first
  `class_rooms` row, else 0 — adjust in `TimetableComposer::resolveRoomId()` if
  production has per-section room mapping.
- Structural bands (assembly/breaks/sports) live only in the draft tables for
  display; they are **not** inserted into `subject_timetable` (parity with the
  manual flow, which stores academic periods only).

## 6. Rollback

- Feature is additive. To remove: drop the nav entry, delete the new files,
  revert the `SubjectTimetable.php` override, `php yii migrate/down 1`.
- To undo a publish: the archived rows still exist with `status = 2` for that
  scope — restore by setting them back to `status = 1` and archiving the new ones
  (or simply regenerate + publish again).
