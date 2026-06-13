# AI Timetable — NXT Schools ERP

An AI-assisted, **clash-free** weekly timetable generator for the NXT Schools
ERP — built as a **pluggable module** so it drops into the production Yii2
backend and surfaces in the parent & teacher mobile apps with minimal wiring.

> **Design principle:** the LLM only *reads* plain-English rules and *writes*
> explanations. The timetable itself is always produced by a deterministic
> constraint solver — so the schedule is reproducible, testable, and
> guaranteed clash-free, whether or not an AI key is configured.

---

## What it does

- **Plain-English rules → timetable.** "No more than 2 maths a day, PT twice a
  week in the afternoon, library once a week" → a full week for every section.
- **Hard guarantees:** no teacher/section/room double-booking, teacher daily &
  weekly caps, after-lunch-only subjects (PT/games), one teacher per
  (section × subject) for the whole week (the "teacher-wise workload" rule).
- **Coordinator / Teacher / Substitute** workflows.
- **Feasibility diagnosis:** if a request can't fit, it says exactly why and how
  to fix it ("each section's week has 54 slots but you asked for 58 — drop 4").
- **Approval-first publish** + **push notifications** to the parent/teacher apps.

## Run the demo (no database, no API key)

```bash
cd standalone-demo
php -S localhost:8088
# open http://localhost:8088
```

The studio runs the **real engine** (`components/ai/timetable/`). Type rules,
generate, review the teacher-wise workload sheet and per-section grids, switch
to the Teacher and Substitute tabs. With a Gemini/Claude key set it parses
free-form rules via the LLM; without one, a built-in keyword parser handles it.

### Optional: enable the LLM

```bash
GEMINI_API_KEY=your_key GEMINI_MODEL=gemini-2.5-flash php -S localhost:8088
```

Get a free key at https://aistudio.google.com/apikey. (Anthropic Claude is also
supported via `ANTHROPIC_API_KEY` — see `components/ai/AI-PIPELINE.md`.)

## Verify the engine

```bash
php tests/timetable_solver_test.php     # 40 checks, no infra required
```

Covers solver invariants (quotas, 0 clashes, caps, after-lunch, teacher
consistency, anti-column), the rules→constraints→solver chain, a fixture
calibrated against a real school's allocation sheets (270/270 placed, 100%
fill), LLM-output sanitisation, and feasibility diagnosis.

## Repository layout

| Path | What |
|---|---|
| `components/ai/timetable/TimetableSolver.php` | The constraint solver (framework-free) |
| `components/ai/timetable/*` | Data loader, AI intake, conflict checker, substitute finder, feasibility analyzer, day-id + push helpers |
| `components/ai/GeminiClient.php` · `LlmRouter.php` · `TimetableComposer.php` | Provider routing (Gemini/Claude/none) + orchestrator |
| `components/ai/prompts/` | The two prompts (rule intake, narration) |
| `components/ai/AI-PIPELINE.md` | Full AI engineering reference (prompts, guardrails, audit, cost, RAG roadmap) |
| `commands/TimetableController.php` | Console: `yii timetable/solver-test\|generate\|publish` |
| `migrations/` | `timetable_generation_runs/slots` (+ AI audit tables) |
| `modules/admin/` | Studio controller, views, AR models, conflict-guard override |
| `standalone-demo/` | Single-file studio demo (this is what you run above) |
| `mobile-app/` | The 2 Flutter notification deep-link edits (parent + teacher apps) |
| `patches/` | Small edits to existing ERP files (`SubjectTimetable`, console config, nav) + `env.example` |
| `INTEGRATION.md` | Step-by-step guide for integrating into the production ERP |

## Integrating into production

See **[INTEGRATION.md](INTEGRATION.md)** — file map, the few existing-file
edits (in `patches/`), `php yii migrate`, provider config, mobile-app notes,
and rollback.

---

*Engine is provider-agnostic and works with no API key (deterministic
fallbacks). Built for the NXT Schools ERP.*
