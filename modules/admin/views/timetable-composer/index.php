<?php

/* @var $this yii\web\View */
/* @var $classes app\modules\admin\models\StudentClass[] */
/* @var $years app\modules\admin\models\AcademicYears[] */
/* @var $teachers app\modules\admin\models\TeacherDetails[] */
/* @var $runs app\modules\admin\models\TimetableGenerationRun[] */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = Yii::t('app', 'AI Timetable Studio');
$this->params['breadcrumbs'][] = $this->title;

$generateUrl    = Url::to(['generate']);
$sectionsUrl    = Url::to(['sections']);
$allocationUrl  = Url::to(['allocation']);
$runUrl         = Url::to(['run']);
$publishUrl     = Url::to(['publish']);
$discardUrl     = Url::to(['discard']);
$substitutesUrl = Url::to(['substitutes']);
$applySubUrl    = Url::to(['apply-substitute']);
$csrf           = Yii::$app->request->getCsrfToken();
?>

<style>
    .ttc-card{background:#fff;border:1px solid #e3e8ef;border-radius:8px;padding:18px;margin-bottom:18px}
    .ttc-card h3{margin:0 0 12px;font-size:17px}
    .ttc-chips{margin:10px 0}
    .ttc-chip{display:inline-block;background:#eef3ff;border:1px solid #c9d8f5;color:#1E4FB8;
        border-radius:999px;padding:3px 12px;margin:0 6px 6px 0;font-size:12px;font-weight:600}
    .ttc-chip.warn{background:#fff6e8;border-color:#f0d9ae;color:#9a6b16}
    .ttc-narrative{background:#f7f9fc;border-left:3px solid #1E4FB8;padding:10px 14px;margin-top:10px;
        font-size:13.5px;color:#333;border-radius:0 6px 6px 0}
    .ttc-actions{margin-top:14px}
    .ttc-sub-period{border:1px solid #e3e8ef;border-radius:6px;padding:10px 14px;margin-bottom:10px}
    .ttc-sub-cand{padding:6px 0;border-top:1px dashed #e3e8ef}
    .ttc-sub-cand:first-child{border-top:0}
    .ttc-reason{color:#777;font-size:12px}
    #ttc-grid-wrap{overflow-x:auto}
    .ttc-spin{display:none;color:#1E4FB8;font-weight:600}
    .ttc-alloc-sec{border:1px solid #e3e8ef;border-radius:6px;padding:10px 14px;margin-bottom:10px}
    .ttc-alloc-sec h4{margin:0 0 8px;font-size:14px}
    .ttc-alloc-table{width:100%;font-size:12.5px;border-collapse:collapse}
    .ttc-alloc-table th,.ttc-alloc-table td{border-bottom:1px solid #eef1f5;padding:5px 8px;text-align:left;vertical-align:top}
    .ttc-alloc-table th{color:#64708a;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
</style>

<div class="ttc-card">
    <h3><i class="fa fa-magic"></i> <?= Html::encode($this->title) ?>
        <small style="color:#888;font-weight:400"> — describe your rules in plain English, generate a clash-free week, review, publish.</small>
    </h3>

    <div class="row">
        <div class="col-md-3">
            <label><?= Yii::t('app', 'Class') ?></label>
            <select id="ttc-class" class="form-control">
                <option value=""><?= Yii::t('app', '-- Select class --') ?></option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int)$c->id ?>"><?= Html::encode($c->title) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label><?= Yii::t('app', 'Academic year') ?></label>
            <select id="ttc-year" class="form-control">
                <?php foreach ($years as $y): ?>
                    <option value="<?= (int)$y->id ?>"><?= Html::encode($y->title) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label><?= Yii::t('app', 'Sections (default: all)') ?></label>
            <select id="ttc-sections" class="form-control" multiple size="3"></select>
        </div>
    </div>

    <div id="ttc-allocation-card" style="display:none;margin-top:14px">
        <label><?= Yii::t('app', 'Teachers & the subject(s) they teach') ?>
            <small style="color:#888;font-weight:400"> — confirm the allocation, then generate. A teacher may teach more than one subject.</small>
        </label>
        <div id="ttc-allocation-warn" class="ttc-chips"></div>
        <div id="ttc-allocation-body"></div>
    </div>

    <div class="row" style="margin-top:12px">
        <div class="col-md-12">
            <label><?= Yii::t('app', 'Scheduling rules — plain English (optional)') ?></label>
            <textarea id="ttc-rules" class="form-control" rows="3"
                placeholder="e.g. 6 maths and 6 english periods a week. No more than 2 maths a day. PT twice a week in the afternoon. Library once a week. Mr. Rao only teaches mornings."></textarea>
        </div>
    </div>

    <div class="ttc-actions">
        <button id="ttc-generate" class="btn btn-primary"><i class="fa fa-bolt"></i> <?= Yii::t('app', 'Generate timetable') ?></button>
        <span id="ttc-busy" class="ttc-spin"><i class="fa fa-spinner fa-spin"></i> <?= Yii::t('app', 'Solving the week…') ?></span>
    </div>

    <div id="ttc-result" style="display:none">
        <div class="ttc-chips" id="ttc-stats"></div>
        <div class="ttc-narrative" id="ttc-narrative"></div>
        <div class="ttc-actions">
            <button id="ttc-publish" class="btn btn-success"><i class="fa fa-check"></i> <?= Yii::t('app', 'Publish to live timetable') ?></button>
            <button id="ttc-discard" class="btn btn-default"><?= Yii::t('app', 'Discard draft') ?></button>
        </div>
    </div>
</div>

<div class="ttc-card" id="ttc-grid-card" style="display:none">
    <h3><i class="fa fa-table"></i> <?= Yii::t('app', 'Draft preview') ?></h3>
    <div id="ttc-grid-wrap"></div>
</div>

<div class="ttc-card">
    <h3><i class="fa fa-user-clock"></i> <?= Yii::t('app', 'Substitute finder') ?>
        <small style="color:#888;font-weight:400"> — teacher on leave? Get ranked, conflict-free cover in one click.</small>
    </h3>
    <div class="row">
        <div class="col-md-4">
            <label><?= Yii::t('app', 'Absent teacher') ?></label>
            <select id="ttc-sub-teacher" class="form-control">
                <option value=""><?= Yii::t('app', '-- Select teacher --') ?></option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int)$t->id ?>"><?= Html::encode($t->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label><?= Yii::t('app', 'Date') ?></label>
            <input type="date" id="ttc-sub-date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-3" style="padding-top:24px">
            <button id="ttc-sub-find" class="btn btn-info"><i class="fa fa-search"></i> <?= Yii::t('app', 'Find substitutes') ?></button>
        </div>
    </div>
    <div id="ttc-sub-result" style="margin-top:14px"></div>
</div>

<div class="ttc-card">
    <h3><i class="fa fa-history"></i> <?= Yii::t('app', 'Recent generations') ?></h3>
    <table class="table table-striped" style="margin-bottom:0">
        <thead><tr>
            <th>#</th><th><?= Yii::t('app', 'Class') ?></th><th><?= Yii::t('app', 'Status') ?></th>
            <th><?= Yii::t('app', 'Created') ?></th><th></th>
        </tr></thead>
        <tbody>
        <?php if ($runs === []): ?>
            <tr><td colspan="5" style="color:#999"><?= Yii::t('app', 'No generations yet — run your first above.') ?></td></tr>
        <?php endif; ?>
        <?php foreach ($runs as $r): ?>
            <tr>
                <td><?= (int)$r->id ?></td>
                <td><?= $r->studentClass ? Html::encode($r->studentClass->title) : (int)$r->class_id ?></td>
                <td><span class="label label-<?= $r->status === 'published' ? 'success' : ($r->status === 'draft' ? 'primary' : 'default') ?>">
                    <?= Html::encode($r->status) ?></span></td>
                <td><?= Html::encode($r->created_on) ?></td>
                <td><a href="#" class="ttc-view-run" data-id="<?= (int)$r->id ?>"><?= Yii::t('app', 'View grid') ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
$js = <<<JS
(function(){
    var runId = null;
    var csrf = '{$csrf}';
    var allocationUrl = '{$allocationUrl}';

    function chips(stats, source, warnings) {
        var h = '';
        h += '<span class="ttc-chip">' + stats.placed + ' / ' + stats.required + ' periods placed</span>';
        h += '<span class="ttc-chip">' + stats.clashes + ' clashes</span>';
        h += '<span class="ttc-chip">' + stats.fill_pct + '% of the week filled</span>';
        h += '<span class="ttc-chip">rules: ' + (source === 'ai' ? 'AI-parsed' : (source === 'fallback' ? 'keyword-parsed' : 'defaults')) + '</span>';
        (warnings || []).forEach(function(w){ h += '<span class="ttc-chip warn">' + w + '</span>'; });
        return h;
    }

    function loadGrid(id) {
        $('#ttc-grid-card').show();
        $('#ttc-grid-wrap').html('<i class="fa fa-spinner fa-spin"></i>');
        $.get('{$runUrl}', {id: id}, function(html){ $('#ttc-grid-wrap').html(html); });
    }

    // Intake step: show each section's teachers and the subject(s) each teaches.
    function renderAllocation(res){
        if (!res || res.ok === false) { $('#ttc-allocation-card').hide(); return; }
        var warn = '';
        (res.warnings || []).forEach(function(w){ warn += '<span class="ttc-chip warn">' + w + '</span>'; });
        if (res.has_sections === false) { warn += '<span class="ttc-chip">No sections — whole class as one timetable</span>'; }
        $('#ttc-allocation-warn').html(warn);
        var h = '';
        (res.sections || []).forEach(function(sec){
            h += '<div class="ttc-alloc-sec"><h4>' + (sec.synthetic ? 'Whole class' : ('Section ' + sec.name)) + '</h4>';
            if (!(sec.teachers || []).length) { h += '<div class="ttc-reason">No teachers resolved yet.</div></div>'; return; }
            h += '<table class="ttc-alloc-table"><thead><tr><th>Teacher</th><th>Subject(s) taught</th></tr></thead><tbody>';
            sec.teachers.forEach(function(t){
                var subs = (t.subjects || []).map(function(s){ return s.name; }).join(', ');
                h += '<tr><td>' + t.name + '</td><td>' + subs + '</td></tr>';
            });
            h += '</tbody></table></div>';
        });
        $('#ttc-allocation-body').html(h);
        $('#ttc-allocation-card').show();
    }

    function loadAllocation(){
        var cid = $('#ttc-class').val();
        if (!cid) { $('#ttc-allocation-card').hide(); return; }
        var secs = $('#ttc-sections').val() || [];
        $('#ttc-allocation-body').html('<i class="fa fa-spinner fa-spin"></i>');
        $('#ttc-allocation-card').show();
        $.get(allocationUrl, {class_id: cid, section_ids: secs.join(','), academic_year_id: $('#ttc-year').val()},
            renderAllocation, 'json').fail(function(){ $('#ttc-allocation-card').hide(); });
    }

    $('#ttc-class').on('change', function(){
        var cid = $(this).val();
        $('#ttc-sections').empty();
        if (!cid) { $('#ttc-allocation-card').hide(); return; }
        $.get('{$sectionsUrl}', {class_id: cid}, function(res){
            (res.sections || []).forEach(function(s){
                $('#ttc-sections').append($('<option>').val(s.id).text('Section ' + s.section_name).prop('selected', true));
            });
            loadAllocation();
        });
    });
    $('#ttc-sections').on('change', loadAllocation);
    $('#ttc-year').on('change', loadAllocation);

    $('#ttc-generate').on('click', function(){
        var cid = $('#ttc-class').val();
        if (!cid) { alert('Pick a class first.'); return; }
        $('#ttc-busy').show(); $('#ttc-result').hide(); $(this).prop('disabled', true);
        $.post('{$generateUrl}', {
            _csrf: csrf,
            class_id: cid,
            academic_year_id: $('#ttc-year').val(),
            'section_ids[]': $('#ttc-sections').val() || [],
            rules: $('#ttc-rules').val()
        }, function(res){
            $('#ttc-busy').hide(); $('#ttc-generate').prop('disabled', false);
            if (!res.ok && !res.run_id) { alert(res.message || 'Generation failed'); return; }
            runId = res.run_id;
            $('#ttc-stats').html(chips(res.stats, res.source, res.warnings));
            if (res.distinct_sections === false) {
                $('#ttc-stats').append('<span class="ttc-chip warn">two sections came out identical — regenerate</span>');
            } else {
                $('#ttc-stats').append('<span class="ttc-chip">all sections distinct · no teacher double-booked</span>');
            }
            $('#ttc-narrative').text(res.narrative || '');
            $('#ttc-result').show();
            $('#ttc-publish').prop('disabled', !res.ok);
            loadGrid(runId);
        }, 'json').fail(function(){ $('#ttc-busy').hide(); $('#ttc-generate').prop('disabled', false); alert('Server error'); });
    });

    $('#ttc-publish').on('click', function(){
        if (!runId) return;
        if (!confirm('Publish this draft? The current timetable for these sections will be archived and replaced.')) return;
        $.post('{$publishUrl}', {_csrf: csrf, run_id: runId}, function(res){
            if (res.ok) { alert('Published! ' + res.inserted + ' periods are now live (' + res.archived + ' old rows archived).'); location.reload(); }
            else { alert(res.message || 'Publish failed'); }
        }, 'json');
    });

    $('#ttc-discard').on('click', function(){
        if (!runId) return;
        $.post('{$discardUrl}', {_csrf: csrf, run_id: runId}, function(){ location.reload(); }, 'json');
    });

    $(document).on('click', '.ttc-view-run', function(e){
        e.preventDefault();
        loadGrid($(this).data('id'));
        $('html,body').animate({scrollTop: $('#ttc-grid-card').offset().top - 60}, 300);
    });

    // ── Substitute finder ────────────────────────────────────────────────
    $('#ttc-sub-find').on('click', function(){
        var tid = $('#ttc-sub-teacher').val(), date = $('#ttc-sub-date').val();
        if (!tid) { alert('Pick the absent teacher.'); return; }
        $('#ttc-sub-result').html('<i class="fa fa-spinner fa-spin"></i>');
        $.get('{$substitutesUrl}', {teacher_id: tid, date: date}, function(res){
            if (!res.ok) { $('#ttc-sub-result').html('<span style="color:#c00">' + (res.message || 'Failed') + '</span>'); return; }
            if (!res.periods.length) { $('#ttc-sub-result').html('<em>No periods scheduled for ' + res.teacher + ' on ' + res.date + '.</em>'); return; }
            var h = '';
            res.periods.forEach(function(block){
                var p = block.period;
                h += '<div class="ttc-sub-period">';
                h += '<strong>' + (p.subject_name || 'Period') + '</strong> — ' + (p.class_name || '') + ' ' + (p.section_name || '')
                   + ' · ' + p.time_from + '–' + p.time_to + ' (P' + p.period + ')';
                if (!block.candidates.length) {
                    h += '<div class="ttc-reason">No free substitute found for this slot.</div>';
                }
                block.candidates.forEach(function(c){
                    h += '<div class="ttc-sub-cand">'
                       + '<button class="btn btn-xs btn-success ttc-sub-apply" data-timetable="' + p.id + '" data-sub="' + c.teacher_details_id + '">Assign</button> '
                       + '<strong>' + c.name + '</strong>'
                       + (c.same_subject ? ' <span class="ttc-chip" style="padding:1px 8px">same subject</span>' : '')
                       + '<div class="ttc-reason">' + c.reasons.join(' · ') + '</div>'
                       + '</div>';
                });
                h += '</div>';
            });
            $('#ttc-sub-result').html(h);
        }, 'json');
    });

    $(document).on('click', '.ttc-sub-apply', function(){
        var btn = $(this);
        $.post('{$applySubUrl}', {
            _csrf: csrf,
            timetable_id: btn.data('timetable'),
            substitute_id: btn.data('sub'),
            teacher_id: $('#ttc-sub-teacher').val(),
            date: $('#ttc-sub-date').val()
        }, function(res){
            if (res.ok) { btn.closest('.ttc-sub-period').css('opacity', .55); btn.replaceWith('<span class="label label-success">Assigned ✓</span>'); }
            else { alert(res.message || 'Failed'); }
        }, 'json');
    });
})();
JS;
$this->registerJs($js);
?>
