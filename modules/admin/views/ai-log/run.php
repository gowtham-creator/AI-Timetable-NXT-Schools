<?php
/**
 * AI Logs — generation run audit chain (read-only).
 * @var yii\web\View $this
 * @var app\modules\admin\models\TimetableGenerationRun $run
 * @var array|null $inv     the linked ai_invocations row (or null)
 * @var int $slotCount
 */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Run #R-' . (int)$run->id . ' — audit chain';
$stats = $run->statsArray();
$sBg = ['draft' => '#eef3ff', 'published' => '#e8f9f0', 'discarded' => '#eef1f5', 'failed' => '#fdeaea'];
$sFg = ['draft' => '#1E4FB8', 'published' => '#0c7a43', 'discarded' => '#64708a', 'failed' => '#b91c1c'];
?>
<style>
    .ai-wrap { font:14px/1.55 -apple-system,'Segoe UI',Roboto,Arial,sans-serif; color:#11203F; max-width:920px; }
    .ai-wrap h1 { font-size:19px; margin:0 0 4px; }
    .ai-back { color:#1E4FB8; text-decoration:none; font-size:12.5px; font-weight:600; }
    .ai-badge { display:inline-block; border-radius:999px; padding:2px 10px; font-size:11.5px; font-weight:700; }
    .ai-card { background:#fff; border:1px solid #e3e8ef; border-radius:10px; padding:14px 16px; margin:14px 0; }
    .ai-card h2 { font-size:14px; margin:0 0 8px; }
    .chain { display:flex; flex-direction:column; gap:8px; }
    .step { padding:10px 12px; border:1px solid #e3e8ef; border-radius:9px; background:#fafbfd; }
    .step .t { font-weight:700; font-size:12.5px; } .step .m { color:#64708a; font-size:11.5px; margin-top:2px; }
    .stmt { background:#f7f9fc; border-left:3px solid #1E4FB8; padding:11px 14px; border-radius:0 8px 8px 0; font-size:13px; }
    .pill { display:inline-block; background:#eef3ff; border:1px solid #c9d8f5; color:#1E4FB8; border-radius:999px; padding:2px 9px; font-size:11px; font-weight:600; }
</style>

<div class="ai-wrap">
    <a class="ai-back" href="<?= Url::to(['ai-log/index']) ?>">← Back to AI logs</a>
    <h1>Run #R-<?= (int)$run->id ?>
        <span class="ai-badge" style="background:<?= $sBg[$run->status] ?? '#eef1f5' ?>;color:<?= $sFg[$run->status] ?? '#334' ?>"><?= Html::encode($run->status) ?></span>
    </h1>
    <p style="font-size:12.5px;color:#64708a">
        <?= Html::encode($run->studentClass->title ?? ('class ' . $run->class_id)) ?> ·
        sections <?= Html::encode(implode(', ', $run->sectionIds())) ?> · created <?= Html::encode($run->created_on) ?>
        <?php if ($run->status === 'published' && $run->published_on): ?> · published <?= Html::encode($run->published_on) ?><?php endif; ?>
    </p>

    <div class="ai-card">
        <h2>Audit chain — what the AI did</h2>
        <div class="chain">
            <div class="step"><div class="t">1 · Coordinator submitted rules</div><div class="m">“<?= Html::encode((string)$run->rules_text) ?>”</div></div>
            <?php if ($inv !== null): ?>
                <div class="step">
                    <div class="t">2 · <?= Html::encode($inv['tool_name']) ?> <span class="pill"><?= Html::encode($inv['model'] ?? 'deterministic') ?></span></div>
                    <div class="m">
                        <?= Html::a('INV-' . (int)$inv['id'], Url::to(['ai-log/view', 'id' => $inv['id']])) ?> ·
                        status <?= Html::encode($inv['status']) ?> ·
                        <?= $inv['tokens_in'] !== null ? (int)$inv['tokens_in'] . '→' . (int)$inv['tokens_out'] . ' tokens · ' : '' ?>
                        <?= (int)$inv['latency_ms'] ?>ms · hash <?= Html::encode(substr((string)$inv['prompt_hash'], 0, 12)) ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="step"><div class="t">2 · Constraint intake</div><div class="m">Deterministic parser (no LLM call recorded for this run).</div></div>
            <?php endif; ?>
            <div class="step">
                <div class="t">3 · Solver placed the week</div>
                <div class="m"><?= (int)($stats['placed'] ?? 0) ?>/<?= (int)($stats['required'] ?? 0) ?> periods · <?= (int)($stats['clashes'] ?? 0) ?> clashes · <?= (int)$slotCount ?> slots stored</div>
            </div>
            <?php if ((string)$run->narrative !== ''): ?>
                <div class="step"><div class="t">4 · Result narrated</div><div class="m"><?= Html::encode((string)$run->narrative) ?></div></div>
            <?php endif; ?>
            <div class="step">
                <div class="t">5 · Lifecycle</div>
                <div class="m">
                    <?php if ($run->status === 'published'): ?>Published — academic slots copied into <code>subject_timetable</code>.
                    <?php elseif ($run->status === 'draft'): ?>Draft held for coordinator review. Nothing is applied until “Publish”.
                    <?php elseif ($run->status === 'discarded'): ?>Discarded by the coordinator — never applied.
                    <?php else: ?>Failed — see the intake/solver diagnosis above; nothing was applied.<?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ((string)$run->narrative !== ''): ?>
        <div class="ai-card"><h2>AI narrative</h2><div class="stmt"><?= Html::encode((string)$run->narrative) ?></div></div>
    <?php endif; ?>
</div>
