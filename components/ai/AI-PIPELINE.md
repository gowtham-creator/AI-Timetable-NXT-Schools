# AI Timetable — AI Pipeline Reference

The complete AI engineering picture for the timetable feature: every prompt,
every model call, every guardrail. Nothing AI-related happens outside what is
documented here.

## 1. The pipeline at a glance

```
 coordinator's plain-English rules
            │
            ▼
 ┌─────────────────────────────┐     no key / LLM error
 │ ① INTAKE  (LLM, optional)   │ ──────────────────────┐
 │ ConstraintIntake::parse()   │                       ▼
 │ prompt: timetable_intake.txt│        ┌───────────────────────────┐
 │ → strict JSON constraints   │        │ ①b FALLBACK (deterministic)│
 └──────────────┬──────────────┘        │ fallbackParse() — regex    │
                │  sanitize() clamps     │ keyword parser, same JSON  │
                ▼                        └─────────────┬─────────────┘
 ┌─────────────────────────────┐                       │
 │ ② SOLVER  (NO AI — pure CSP)│ ◀─────────────────────┘
 │ TimetableSolver::solve()    │
 │ greedy + backtracking,      │   The schedule is NEVER produced by an
 │ hard constraints, scoring   │   LLM. Deterministic, testable, 0-clash
 └──────────────┬──────────────┘   guaranteed by construction + validate().
                ▼
 ┌─────────────────────────────┐
 │ ③ NARRATION (LLM, optional) │
 │ TimetableComposer::narrate()│
 │ prompt: timetable_narrate.txt│ → fallbackNarrative() when no key
 └──────────────┬──────────────┘
                ▼
   draft run (DB) → human review → publish() → subject_timetable
                                      └→ TimetablePushService → FCM (apps)
```

**Design rule:** the LLM only ever (a) reads rule text, (b) writes explanation
text. It cannot place a period, pick a teacher, or touch the database. All
schedule correctness is owned by the deterministic solver — so a hallucination
can at worst mis-read a rule, and the coordinator sees the parsed-rule chips +
draft grid before anything goes live.

## 2. Model routing — `LlmRouter`

| Priority | Provider | Env | Default model |
|---|---|---|---|
| 1 | Anthropic Claude | `ANTHROPIC_API_KEY` | `claude-sonnet-4-6` (`ANTHROPIC_MODEL`) |
| 2 | Google Gemini | `GEMINI_API_KEY` | `gemini-2.5-flash` (`GEMINI_MODEL`) |

> **Gemini 2.5 = a reasoning model (verified live).** 2.5 models spend a
> large, variable share of `maxOutputTokens` on hidden "thinking" before the
> answer — a structured-JSON intake call burned ~765 thinking tokens and
> truncated. Both timetable tasks are extraction/short-summary (no
> chain-of-thought needed), so `GeminiClient` ships `thinkingBudget=0`
> (`GEMINI_THINKING_BUDGET`): complete JSON, ~1.5s, cheaper. Also note
> `gemini-2.0-flash`'s free tier is now 0 on new projects — default is 2.5.
> Two parser hardenings came from live testing: the model emits explicit
> `null` for unspecified fields (sanitize drops them, never `(int)null=0`),
> and may return day **names** instead of ints (both accepted).
| 3 | none | — | deterministic fallbacks (feature fully functional) |

`LlmRouter::resolve()` returns the first configured provider;
`LlmRouter::chat()` normalises messages/usage across both APIs. Callers never
contain provider-specific code. Adding a provider = one client class + one
branch in the router.

## 3. Prompts (the only two in the feature)

### ① `prompts/timetable_intake.txt` — rules → JSON
- **System prompt**, grounded per call with the school's REAL subject names,
  teacher names and planned days (`{{SUBJECTS}}`, `{{TEACHERS}}`, `{{DAYS}}`).
  Grounding keeps the model from inventing entities — it can only reference
  names that exist in this campus's data.
- Output contract: a single JSON object, schema documented inside the prompt
  (days / subjects[] / teachers[] with per_week, max_per_day,
  after_lunch_only, morning_only, unavailable…). "Reply with ONLY the JSON."
- **User message** = the coordinator's rules, passed through
  `PiiRedactor::redact()` first.

### ③ `prompts/timetable_narrate.txt` — result → explanation
- System prompt with `{{DATA}}` = generation stats + per-teacher load (names,
  no ids) + sections. Instructed: 3–6 sentences, no markdown, never invent
  details absent from the data, explain bottlenecks with one actionable fix.

## 4. Guardrails (in order of execution)

1. **PII redaction** (`PiiRedactor`) — phone numbers, ids etc. stripped from
   rule text before it leaves the server.
2. **JSON extraction** (`extractJson`) — tolerates prose/fences around the
   object; hard-fails → fallback parser.
3. **Schema sanitisation** (`sanitize`) — whitelist of keys, integer clamps
   (per_week ≤ 15, max_per_day ≤ 4, caps ≤ 48), name_like length-capped.
   A prompt-injected or malformed reply cannot reach the solver with anything
   outside this schema. This is the injection boundary.
4. **Constraint overlay clamps** (`TimetableDataLoader::applyConstraints`) —
   second clamp at apply time; unknown fields ignored.
5. **Solver hard constraints** — even hostile constraints can only make the
   problem infeasible, never produce a clashing timetable; `validate()`
   re-checks the finished grid from scratch.
6. **Approval-first** — nothing reaches `subject_timetable` until a human
   clicks Publish; publish re-validates the whole run atomically.

## 4b. Feasibility diagnosis (coordinator experience)

`FeasibilityAnalyzer` runs as a deterministic pre-flight (no LLM, no solver)
and turns "could not place: PET, PET, PET…" into a precise, actionable reason:

- **Grid budget** — Σ(per_week) vs periods × days. *"Each section's week has
  54 slots but the subjects total 58 — 4 too many; lower Mathematics (10/wk)."*
- **Per-day cap** — a subject's weekly count can't exceed max_per_day × days.
- **Teacher capacity** — demand (per_week × sections) vs what its teachers can
  cover in the allowed half of the day. *"PET: 5 × 6 = 30 periods but 1 teacher,
  afternoons only, covers 24 — add a PE teacher or reduce to ≤4/week."*

The report is attached to every run (`stats_json.feasibility`), fed into the
LLM narration prompt so the explanation names the real cause, and shown in the
studio as a why/fix panel. Publish is disabled until blockers clear. This is
the difference between "the AI failed" and "here's the one number to change."

## 5. Audit trail — every LLM call is recorded

`AuditLogger` writes one `ai_invocations` row per call:
`tool_name` (`timetable_intake` | `timetable_narrate`), `model`
(`anthropic:claude-…` / `gemini:…`), `user_id`, `campus_id`, `prompt_hash`
(sha256), full request/response payloads, `tokens_in/out`, `latency_ms`,
`status`, `error_message`. Failures are logged with `status='error'` and the
pipeline continues on the fallback path. Each generation run stores its
`ai_invocation_id`, so a published timetable traces back to the exact prompt
that parsed its rules.

## 6. Cost & latency profile

| Call | When | Tokens (typ.) | Cost |
|---|---|---|---|
| intake | once per Generate click, only if rules text non-empty | ~600 in / ~200 out | ~free on Gemini flash tier |
| narrate | once per Generate | ~400 in / ~150 out | ~free on Gemini flash tier |
| solver | every Generate | 0 — pure PHP, ~5 ms | ₹0 |

No background calls, no per-student calls, no polling. A school generating
50 timetables a term spends ~100 LLM calls a term.

## 7. Why there is no RAG (and where it would plug in)

The intake prompt is **grounded directly** with the campus's live subject and
teacher names — the entire "knowledge base" relevant to parsing rules fits in
a few hundred tokens, so retrieval would add latency and failure modes for
zero gain. RAG becomes worth it when these land (hooks already exist):

- **Constraint memory across terms** — embed each term's `constraints_json`
  (stored on every run) and retrieve "what rules did this school use last
  term?" to pre-fill the rules box. Storage hook: `timetable_generation_runs`.
- **Policy documents** — a school uploads its scheduling policy PDF; chunks
  retrieved into the intake prompt. Insertion point: the system-prompt
  builder in `ConstraintIntake::parseWithLlm()`.
- **NL schedule queries in the apps** ("when does 6E have Maths?") — answered
  via the whitelisted-tool pattern already used by `components/ai/tools/`
  (ToolRegistry → safe SQL, never model-generated SQL).

## 8. Evaluation / regression harness

`tests/timetable_solver_test.php` (28 checks, runs with zero infrastructure):
solver invariants (quotas, clashes, caps, after-lunch, anti-column, teacher
consistency), the full rules→constraints→solver chain on the fallback parser,
and a fixture calibrated against a real school's allocation sheets
(270/270 placed, 100% fill). Run it in CI; any prompt or parser change that
breaks rule semantics fails the chain checks.
