<?php
/**
 * AI Logs — single invocation detail (read-only).
 * @var yii\web\View $this
 * @var array $inv         ai_invocations row
 * @var array $proposals   ai_proposals rows linked to this invocation
 * @var app\modules\admin\models\TimetableGenerationRun|null $run
 */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'AI invocation INV-' . (int)$inv['id'];
$pretty = static function ($json) {
    $d = json_decode((string)$json, true);
    return $d === null ? (string)$json : json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
?>
<style>
    .ai-wrap { font:14px/1.55 -apple-system,'Segoe UI',Roboto,Arial,sans-serif; color:#11203F; max-width:920px; }
    .ai-wrap h1 { font-size:19px; margin:0 0 4px; }
    .ai-back { color:#1E4FB8; text-decoration:none; font-size:12.5px; font-weight:600; }
    .ai-meta { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin:14px 0; }
    .ai-meta .b { background:#fff; border:1px solid #e3e8ef; border-radius:9px; padding:10px 12px; }
    .ai-meta .l { color:#64708a; font-size:11px; } .ai-meta .v { font-weight:700; font-size:13px; margin-top:2px; }
    .ai-card { background:#fff; border:1px solid #e3e8ef; border-radius:10px; padding:14px 16px; margin-bottom:14px; }
    .ai-card h2 { font-size:14px; margin:0 0 8px; }
    pre { background:#0d1b35; color:#dce6fb; border-radius:8px; padding:12px 14px; font-size:11.5px; overflow:auto; margin:0; }
    .ai-badge { display:inline-block; border-radius:999px; padding:2px 9px; font-size:11px; font-weight:700; background:#e8f9f0; color:#0c7a43; }
    .ai-err { background:#fdeaea; color:#b91c1c; border:1px solid #f3c4c4; border-radius:8px; padding:10px 12px; font-size:12.5px; }
</style>

<div class="ai-wrap">
    <a class="ai-back" href="<?= Url::to(['ai-log/index']) ?>">← Back to AI logs</a>
    <h1>INV-<?= (int)$inv['id'] ?> · <?= Html::encode($inv['tool_name']) ?></h1>

    <div class="ai-meta">
        <div class="b"><div class="l">Model</div><div class="v"><?= Html::encode($inv['model'] ?? '—') ?></div></div>
        <div class="b"><div class="l">Status</div><div class="v"><span class="ai-badge" style="<?= $inv['status'] === 'success' ? '' : 'background:#fdeaea;color:#b91c1c' ?>"><?= Html::encode($inv['status']) ?></span></div></div>
        <div class="b"><div class="l">Tokens (in→out)</div><div class="v"><?= $inv['tokens_in'] !== null ? (int)$inv['tokens_in'] . ' → ' . (int)$inv['tokens_out'] : '—' ?></div></div>
        <div class="b"><div class="l">Latency</div><div class="v"><?= (int)$inv['latency_ms'] ?> ms</div></div>
    </div>
    <p style="font-size:12.5px;color:#64708a">
        Prompt hash <code><?= Html::encode($inv['prompt_hash']) ?></code> · logged <?= Html::encode($inv['created_on']) ?>
        <?php if ($run !== null): ?> · run <?= Html::a('#R-' . (int)$run->id, Url::to(['ai-log/run', 'id' => $run->id])) ?><?php endif; ?>
    </p>

    <?php if (!empty($inv['error_message'])): ?>
        <div class="ai-err">⚠ <?= Html::encode($inv['error_message']) ?></div>
    <?php endif; ?>

    <div class="ai-card">
        <h2>Request payload</h2>
        <pre><?= Html::encode($pretty($inv['request_payload'] ?? '')) ?></pre>
    </div>
    <div class="ai-card">
        <h2>Response payload</h2>
        <pre><?= Html::encode($pretty($inv['response_payload'] ?? '')) ?></pre>
    </div>

    <?php if ($proposals): ?>
        <div class="ai-card">
            <h2>Proposals from this call · ai_proposals</h2>
            <?php foreach ($proposals as $p): ?>
                <p style="font-size:12.5px;margin:6px 0">
                    <strong>#<?= (int)$p['id'] ?></strong> → <code><?= Html::encode($p['target_table']) ?></code>
                    (<?= Html::encode($p['status']) ?>)<?= $p['reasoning'] ? ' — ' . Html::encode($p['reasoning']) : '' ?>
                </p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
