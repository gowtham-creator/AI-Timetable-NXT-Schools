<?php

/* @var $this yii\web\View */
/* @var $run app\modules\admin\models\TimetableGenerationRun */
/* @var $layout array */
/* @var $days array */
/* @var $by_section array */
/* @var $section_names array */
/* @var $subject_names array */
/* @var $teacher_names array */
/* @var $stats array */

use yii\helpers\Html;

$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$bandColors = [
    'assembly' => '#fdf3e3',
    'break'    => '#f2f4f7',
    'lunch'    => '#f2f4f7',
    'activity' => '#e9f7ef',
];
?>
<style>
    .ttg{border-collapse:collapse;width:100%;margin-bottom:26px;font-size:12.5px}
    .ttg th,.ttg td{border:1px solid #e3e8ef;padding:6px 8px;text-align:center;vertical-align:middle}
    .ttg thead th{background:#1E4FB8;color:#fff;font-weight:600}
    .ttg .ttg-day{background:#f7f9fc;font-weight:700;white-space:nowrap}
    .ttg .ttg-band{font-weight:600;color:#666;font-size:11.5px}
    .ttg .ttg-subj{font-weight:700;color:#1b2b4b;display:block}
    .ttg .ttg-teach{color:#7a8194;font-size:11px;display:block;margin-top:1px}
    .ttg-section-title{margin:6px 0 8px;font-size:15px}
    .ttg-meta{color:#888;font-size:12px;margin-bottom:14px}
</style>

<div class="ttg-meta">
    Run #<?= (int)$run->id ?> · status <strong><?= Html::encode($run->status) ?></strong>
    <?php if (!empty($stats)): ?>
        · <?= (int)($stats['placed'] ?? 0) ?>/<?= (int)($stats['required'] ?? 0) ?> periods
        · <?= (int)($stats['clashes'] ?? 0) ?> clashes
    <?php endif; ?>
    · generated <?= Html::encode($run->created_on) ?>
</div>

<?php foreach ($by_section as $sectionId => $data): ?>
    <h4 class="ttg-section-title">
        <i class="fa fa-users"></i>
        Section <?= Html::encode($section_names[$sectionId] ?? $sectionId) ?>
    </h4>
    <table class="ttg">
        <thead>
        <tr>
            <th style="width:90px">Day</th>
            <?php foreach ($layout as $col): ?>
                <?php if ($col['kind'] === 'period'): ?>
                    <th>P<?= (int)$col['no'] ?><br>
                        <small style="font-weight:400"><?= Html::encode($col['time_from']) ?>–<?= Html::encode($col['time_to']) ?></small>
                    </th>
                <?php else: ?>
                    <th style="background:#16408f"><?= Html::encode($col['label'] ?? ucfirst($col['kind'])) ?><br>
                        <small style="font-weight:400"><?= Html::encode($col['time_from']) ?>–<?= Html::encode($col['time_to']) ?></small>
                    </th>
                <?php endif; ?>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($days as $day): ?>
            <tr>
                <td class="ttg-day"><?= $dayNames[$day] ?? $day ?></td>
                <?php foreach ($layout as $col): ?>
                    <?php if ($col['kind'] === 'period'):
                        $slot = $data['academic'][$day][(int)$col['no']] ?? null; ?>
                        <td>
                            <?php if ($slot !== null): ?>
                                <span class="ttg-subj"><?= Html::encode($subject_names[$slot['subject_id']] ?? ('Subject #' . $slot['subject_id'])) ?></span>
                                <span class="ttg-teach"><?= Html::encode($teacher_names[$slot['teacher_details_id']] ?? '') ?></span>
                            <?php else: ?>
                                <span style="color:#c4cbd8">—</span>
                            <?php endif; ?>
                        </td>
                    <?php else: ?>
                        <td class="ttg-band" style="background:<?= $bandColors[$col['kind']] ?? '#f2f4f7' ?>">
                            <?= Html::encode($col['label'] ?? ucfirst($col['kind'])) ?>
                        </td>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>

<?php if ($by_section === []): ?>
    <em>This run has no slots.</em>
<?php endif; ?>
