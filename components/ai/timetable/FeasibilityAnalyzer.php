<?php

namespace app\components\ai\timetable;

/**
 * FeasibilityAnalyzer — deterministic pre-flight that tells a coordinator, in
 * plain language, WHETHER a request can fit and, if not, exactly WHY and HOW
 * to fix it. No LLM, no solver — pure arithmetic on the constraints.
 *
 * This is what turns "Could not place: PET, PET, PET…" (a hassle) into
 * "Each section's week has 54 slots but you asked for 58 — drop 4, e.g. PET
 * 6→2." Run it before solving (warn early) and after (explain any shortfall).
 *
 * Three checks, ordered by how intuitive they are to a human:
 *   1. Grid budget   — Σ(per_week) vs (periods × days) per section
 *   2. Per-day cap   — a subject's per_week can't exceed max_per_day × days
 *   3. Teacher slots — demand (per_week × sections) vs what its teachers,
 *                      in the allowed part of the day, can actually cover
 */
class FeasibilityAnalyzer
{
    /**
     * @return array{ok:bool, blockers:array<int,array{subject:?string,message:string,fix:string}>,
     *               notes:array<int,string>, budget:array}
     */
    public static function analyze(array $input): array
    {
        $days = count($input['days']);
        $periodCount = 0;
        $afternoon = 0;
        $lunchSeen = false;
        foreach ($input['layout'] as $col) {
            if ($col['kind'] === 'lunch') {
                $lunchSeen = true;
            }
            if ($col['kind'] === 'period') {
                $periodCount++;
                if ($lunchSeen) {
                    $afternoon++;
                }
            }
        }
        $cellsPerSection = $periodCount * $days;
        $sections = count($input['sections']);
        $teachers = [];
        foreach ($input['teachers'] as $t) {
            $teachers[(int)$t['id']] = $t;
        }

        $blockers = [];
        $notes = [];

        // ── 1. Grid budget per section ─────────────────────────────────────
        $totalPerWeek = 0;
        $heaviest = null;
        foreach ($input['subjects'] as $s) {
            $totalPerWeek += (int)$s['per_week'];
            if ($heaviest === null || (int)$s['per_week'] > (int)$heaviest['per_week']) {
                $heaviest = $s;
            }
        }
        if ($totalPerWeek > $cellsPerSection) {
            $over = $totalPerWeek - $cellsPerSection;
            $blockers[] = [
                'subject' => null,
                'message' => "Each section's week has only {$cellsPerSection} teaching slots "
                    . "({$periodCount} periods × {$days} days), but the subjects requested total "
                    . "{$totalPerWeek} periods — {$over} too many.",
                'fix'     => "Remove {$over} period(s) per section"
                    . ($heaviest ? " — e.g. lower {$heaviest['name']} ({$heaviest['per_week']}/wk)" : '')
                    . ", or add a period to the daily schedule.",
            ];
        } elseif ($totalPerWeek < $cellsPerSection) {
            $notes[] = ($cellsPerSection - $totalPerWeek) . " free period(s) per section after every subject is placed.";
        } else {
            $notes[] = "Subjects fill the week exactly ({$totalPerWeek}/{$cellsPerSection} slots).";
        }

        // ── 2. Per-day cap vs weekly count ─────────────────────────────────
        foreach ($input['subjects'] as $s) {
            $cap = (int)($s['max_per_day'] ?? 2) * $days;
            if ((int)$s['per_week'] > $cap) {
                $blockers[] = [
                    'subject' => $s['name'],
                    'message' => "{$s['name']}: {$s['per_week']}/week can't fit under its limit of "
                        . "{$s['max_per_day']}/day ({$s['max_per_day']} × {$days} days = {$cap} max).",
                    'fix'     => "Raise {$s['name']}'s per-day limit, or lower it to ≤{$cap}/week.",
                ];
            }
        }

        // ── 3. Teacher capacity per subject ────────────────────────────────
        foreach ($input['subjects'] as $s) {
            $demand = (int)$s['per_week'] * $sections;
            $slotsPerDay = !empty($s['after_lunch_only']) ? $afternoon : $periodCount;
            $capacity = 0;
            $names = [];
            foreach ($s['teacher_ids'] as $tid) {
                $t = $teachers[(int)$tid] ?? null;
                if ($t === null) {
                    continue;
                }
                $perDay = $slotsPerDay;
                if (!empty($t['morning_only']) && !empty($s['after_lunch_only'])) {
                    $perDay = 0; // can't teach an afternoon-only subject in the morning
                }
                $capacity += min($perDay * $days, (int)($t['max_per_week'] ?? 44));
                $names[] = $t['name'];
            }
            if ($demand > $capacity) {
                $short = $demand - $capacity;
                $perSecMax = $sections > 0 ? intdiv($capacity, $sections) : 0;
                $fix = "Add another teacher who can take {$s['name']}";
                if (!empty($s['after_lunch_only'])) {
                    $fix .= ", allow it outside the afternoon,";
                }
                $fix .= " or reduce it to ≤{$perSecMax}/week per section.";
                $blockers[] = [
                    'subject' => $s['name'],
                    'message' => "{$s['name']}: {$sections} sections × {$s['per_week']}/week = {$demand} periods, "
                        . "but " . count($names) . " teacher(s) can cover only {$capacity}/week"
                        . (!empty($s['after_lunch_only']) ? ' (afternoons only)' : '')
                        . " — short by {$short}.",
                    'fix'     => $fix,
                ];
            }
        }

        return [
            'ok'       => $blockers === [],
            'blockers' => $blockers,
            'notes'    => $notes,
            'budget'   => [
                'cells_per_section' => $cellsPerSection,
                'requested_per_section' => $totalPerWeek,
                'periods' => $periodCount,
                'afternoon' => $afternoon,
                'days' => $days,
                'sections' => $sections,
            ],
        ];
    }

    /** One-line headline for logs / short summaries. */
    public static function headline(array $report): string
    {
        if ($report['ok']) {
            return 'Feasible: ' . implode(' ', $report['notes']);
        }
        $b = $report['blockers'][0];
        $more = count($report['blockers']) - 1;
        return $b['message'] . ' ' . $b['fix'] . ($more > 0 ? " (+{$more} more)" : '');
    }
}
