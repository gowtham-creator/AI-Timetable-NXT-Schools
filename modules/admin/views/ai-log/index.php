<?php
/**
 * AI Logs — audit dashboard (read-only).
 * @var yii\web\View $this
 * @var array $agg          KPI aggregates
 * @var array $rows         ai_invocations rows
 * @var string[] $tools     distinct tool names
 * @var app\modules\admin\models\TimetableGenerationRun[] $runs
 * @var array $invToRun     invocation_id => run_id
 * @var array $filters      tool|status|from|to
 */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'AI Logs — audit trail';
$sBg = ['success' => '#e8f9f0', 'blocked' => '#fff6e8', 'error' => '#fdeaea', 'pending' => '#eef3ff'];
$sFg = ['success' => '#0c7a43', 'blocked' => '#9a6b16', 'error' => '#b91c1c', 'pending' => '#1E4FB8'];
$badge = static function ($s) use ($sBg, $sFg) {
    return '<span class="ai-badge" style="background:' . ($sBg[$s] ?? '#eef1f5') . ';color:' . ($sFg[$s] ?? '#334') . '">' . Html::encode($s) . '</span>';
};
$tin = (int)($agg['tokens_in'] ?? 0);
$tout = (int)($agg['tokens_out'] ?? 0);
$calls = (int)($agg['calls'] ?? 0);
$ok = (int)($agg['ok'] ?? 0);
?>
<style>
    .ai-wrap { font:14px/1.55 -apple-system,'Segoe UI',Roboto,Arial,sans-serif; color:#11203F; }
    .ai-wrap h1 { font-size:20px; margin:0 0 4px; }
    .ai-wrap .sub { color:#64708a; font-size:13px; margin-bottom:16px; }
    .ai-guard { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; border-radius:8px; padding:10px 13px; font-size:12.5px; margin-bottom:14px; }
    .ai-cards { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:16px; }
    .ai-kpi { background:#fff; border:1px solid #e3e8ef; border-radius:10px; padding:13px 15px; }
    .ai-kpi .n { font-size:20px; font-weight:800; } .ai-kpi .l { color:#64708a; font-size:11.5px; margin-top:2px; }
    .ai-card { background:#fff; border:1px solid #e3e8ef; border-radius:10px; padding:16px 18px; margin-bottom:16px; }
    .ai-card h2 { font-size:15px; margin:0 0 12px; }
    .ai-filters { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px; }
    .ai-filters label { display:block; font-size:11px; color:#64708a; margin-bottom:3px; }
    .ai-filters select, .ai-filters input { padding:6px 9px; border:1px solid #e3e8ef; border-radius:7px; font:inherit; font-size:13px; }
    .ai-filters button { padding:7px 14px; border:none; background:#1E4FB8; color:#fff; border-radius:7px; font-weight:600; cursor:pointer; }
    .ai-table { border-collapse:collapse; width:100%; font-size:12.5px; }
    .ai-table th, .ai-table td { border-bottom:1px solid #e3e8ef; padding:8px 9px; text-align:left; vertical-align:top; }
    .ai-table thead th { color:#64708a; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
    .ai-badge { display:inline-block; border-radius:999px; padding:2px 9px; font-size:11px; font-weight:700; }
    .ai-mono { font-family:ui-monospace,Menlo,monospace; font-size:11.5px; }
    .ai-mut { color:#64708a; }
    @media (max-width:980px){ .ai-cards{grid-template-columns:repeat(2,1fr);} }
</style>

<div class="ai-wrap">
    <h1>AI Logs <span class="ai-mut" style="font-weight:400">· audit trail</span></h1>
    <div class="sub">Every AI call in this campus, with the exact prompt-hash, model version, tokens, latency and outcome.</div>

    <div class="ai-guard">🔒 <strong>Read-only &amp; complete:</strong> failures, fallbacks and guardrail blocks are logged too. A disputed schedule or message can always be traced to what the AI saw and produced.</div>

    <div class="ai-cards">
        <div class="ai-kpi"><div class="n"><?= $calls ?></div><div class="l">AI calls logged</div></div>
        <div class="ai-kpi"><div class="n" style="color:#0c7a43"><?= $calls ? (int)round($ok / $calls * 100) : 100 ?>%</div><div class="l"><?= $ok ?>/<?= $calls ?> succeeded</div></div>
        <div class="ai-kpi"><div class="n"><?= number_format($tin + $tout) ?></div><div class="l">Tokens (<?= number_format($tin) ?> in + <?= number_format($tout) ?> out)</div></div>
        <div class="ai-kpi"><div class="n"><?= (int)($agg['avg_ms'] ?? 0) ?>ms</div><div class="l">Avg latency / call</div></div>
        <div class="ai-kpi"><div class="n"><?= count($runs) ?></div><div class="l">Recent runs</div></div>
    </div>

    <div class="ai-card">
        <form class="ai-filters" method="get" action="<?= Url::to(['ai-log/index']) ?>">
            <div><label>Tool</label>
                <select name="tool">
                    <option value="">All tools</option>
                    <?php foreach ($tools as $t): ?>
                        <option value="<?= Html::encode($t) ?>"<?= $filters['tool'] === $t ? ' selected' : '' ?>><?= Html::encode($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Status</label>
                <select name="status">
                    <?php foreach (['' => 'All', 'success' => 'success', 'blocked' => 'blocked', 'error' => 'error'] as $v => $l): ?>
                        <option value="<?= Html::encode($v) ?>"<?= $filters['status'] === $v ? ' selected' : '' ?>><?= Html::encode($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>From</label><input type="date" name="from" value="<?= Html::encode($filters['from']) ?>"></div>
            <div><label>To</label><input type="date" name="to" value="<?= Html::encode($filters['to']) ?>"></div>
            <div><button type="submit">Filter</button></div>
        </form>

        <h2>AI invocation audit trail <span class="ai-mut" style="font-weight:400">· ai_invocations</span></h2>
        <table class="ai-table">
            <thead><tr><th>ID</th><th>Run</th><th>Tool</th><th>Model</th><th>Status</th><th>Tokens</th><th>Latency</th><th>Prompt hash</th><th>When</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="10" class="ai-mut">No AI calls logged for the current filter.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $e): $rid = $invToRun[(int)$e['id']] ?? null; ?>
                <tr>
                    <td class="ai-mono">INV-<?= (int)$e['id'] ?></td>
                    <td class="ai-mono"><?= $rid ? Html::a('#R-' . $rid, Url::to(['ai-log/run', 'id' => $rid])) : '—' ?></td>
                    <td class="ai-mono"><?= Html::encode($e['tool_name']) ?></td>
                    <td class="ai-mono"><?= Html::encode($e['model'] ?? '—') ?></td>
                    <td><?= $badge($e['status']) ?></td>
                    <td class="ai-mono ai-mut"><?= $e['tokens_in'] !== null ? (int)$e['tokens_in'] . '→' . (int)$e['tokens_out'] : '—' ?></td>
                    <td class="ai-mono"><?= (int)$e['latency_ms'] ?>ms</td>
                    <td class="ai-mono ai-mut"><?= Html::encode(substr((string)$e['prompt_hash'], 0, 12)) ?></td>
                    <td class="ai-mono ai-mut"><?= Html::encode($e['created_on']) ?></td>
                    <td><?= Html::a('view', Url::to(['ai-log/view', 'id' => $e['id']])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="ai-card">
        <h2>Generation runs <span class="ai-mut" style="font-weight:400">· timetable_generation_runs</span></h2>
        <table class="ai-table">
            <thead><tr><th>Run</th><th>Class</th><th>Status</th><th>Rules (intake)</th><th>When</th><th></th></tr></thead>
            <tbody>
            <?php if (!$runs): ?>
                <tr><td colspan="6" class="ai-mut">No generation runs yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($runs as $r): ?>
                <tr>
                    <td class="ai-mono">#R-<?= (int)$r->id ?></td>
                    <td><?= Html::encode($r->studentClass->title ?? ('class ' . $r->class_id)) ?></td>
                    <td><?= $badge($r->status) ?></td>
                    <td style="max-width:280px"><?= Html::encode((string)$r->rules_text) ?></td>
                    <td class="ai-mono ai-mut"><?= Html::encode($r->created_on) ?></td>
                    <td><?= Html::a('audit chain', Url::to(['ai-log/run', 'id' => $r->id])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
