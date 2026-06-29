<?php
/**
 * AI Timetable — STANDALONE TIMETABLE STUDIO (one place to generate)
 * ------------------------------------------------------------------
 * ONE clean studio over the REAL engine (components/ai/timetable/):
 *   • Generate — choose a SCOPE (This section / Whole class / Whole school),
 *     pick class/section as needed, see who teaches what, then generate the
 *     clash-free timetable(s). Whole-school solves every class in ONE run so a
 *     teacher is never double-booked ACROSS classes.
 *   • Teachers — every teacher, the subjects they teach, the classes & sections
 *     they cover; click a teacher for their full weekly schedule.
 * No Yii, no database, no API key. (Production parses plain-English rules with
 * Gemini — add GEMINI_API_KEY; this demo runs the built-in solver.)
 *
 *   php -S localhost:8088 -t standalone-demo   →   http://localhost:8088/class.php
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

$cands = [__DIR__ . '/../components/ai/timetable', __DIR__ . '/../new-files/components/ai/timetable', __DIR__ . '/new-files/components/ai/timetable'];
$enginePath = null;
foreach ($cands as $c) {
    if (is_file($c . '/TimetableSolver.php')) { $enginePath = $c; break; }
}
if ($enginePath === null) { http_response_code(500); exit('Engine not found — keep this file next to components/ai/timetable/.'); }
require $enginePath . '/TimetableSolver.php';
require $enginePath . '/SolverFixtures.php';

use app\components\ai\timetable\SolverFixtures;
use app\components\ai\timetable\TimetableSolver;

// ── Solve the whole school ONCE; the studio is a clean view over it. ─────────
$input = SolverFixtures::wholeSchoolInput();   // classes 6–10, sections A/B, shared teachers
$res   = TimetableSolver::solve($input, 20260629);
$slots = $res['slots'];

$secName = $secClass = [];
foreach ($input['sections'] as $s) { $secName[$s['id']] = $s['name']; $secClass[$s['id']] = $s['class']; }
$teaName = array_column($input['teachers'], 'name', 'id');
$subName = [];
foreach ($input['sections'] as $s) {
    foreach (($s['subjects'] ?? $input['subjects']) as $sub) { $subName[$sub['id']] = $sub['name']; }
}

$periods = [];
foreach ($input['layout'] as $c) { if ($c['kind'] === 'period') { $periods[(int)$c['no']] = $c; } }
ksort($periods);

$owner = $grid = $teaSched = [];
foreach ($slots as $sl) {
    $owner[$sl['section_id']][$sl['subject_id']] = $sl['teacher_id'];
    $grid[$sl['section_id']][$sl['day']][$sl['period']] = [
        's' => $subName[$sl['subject_id']] ?? '', 't' => $teaName[$sl['teacher_id']] ?? '',
    ];
    $teaSched[$sl['teacher_id']][$sl['day']][$sl['period']] = [
        'sec' => $secName[$sl['section_id']] ?? '', 'sub' => $subName[$sl['subject_id']] ?? '',
    ];
}

$classes = [];
foreach ($input['sections'] as $s) { $classes[$s['class']] = true; }
$classes = array_keys($classes);
sort($classes, SORT_NATURAL);
$sectionsByClass = [];
foreach ($input['sections'] as $s) { $sectionsByClass[$s['class']][] = ['id' => $s['id'], 'name' => $s['name']]; }

// per-section: teachers and the subject(s) each teaches (teacher-centric)
$allocTea = [];
foreach ($input['sections'] as $s) {
    $sid = $s['id'];
    $byT = [];
    foreach (($owner[$sid] ?? []) as $subId => $tid) { $byT[$tid][] = $subName[$subId] ?? ''; }
    $list = [];
    foreach ($byT as $tid => $subs) { $list[] = ['teacher' => $teaName[$tid] ?? '', 'subjects' => array_values(array_unique($subs))]; }
    usort($list, static fn($a, $b) => strcmp($a['teacher'], $b['teacher']));
    $allocTea[$sid] = $list;
}

// teachers module: id, name, subjects, class/section placements
$tAgg = [];
foreach ($input['sections'] as $s) {
    $sid = $s['id'];
    foreach (($owner[$sid] ?? []) as $subId => $tid) {
        if (!isset($tAgg[$tid])) { $tAgg[$tid] = ['rows' => []]; }
        $tAgg[$tid]['rows'][] = ['subject' => $subName[$subId] ?? '', 'class' => $secClass[$sid], 'section' => $secName[$sid]];
    }
}
$teachers = [];
foreach ($tAgg as $tid => $t) {
    $subs = array_values(array_unique(array_map(static fn($r) => $r['subject'], $t['rows'])));
    usort($t['rows'], static fn($a, $b) => [$a['class'], $a['section']] <=> [$b['class'], $b['section']]);
    $teachers[] = ['id' => $tid, 'name' => $teaName[$tid] ?? '', 'subjects' => $subs, 'rows' => $t['rows'],
        'periods' => array_sum(array_map(static fn($d) => count($d), $teaSched[$tid] ?? []))];
}
usort($teachers, static fn($a, $b) => strcmp($a['name'], $b['name']));

// grids for JS
$gridsOut = [];
foreach ($input['sections'] as $s) {
    $sid = $s['id']; $cells = [];
    foreach ($input['days'] as $d) {
        foreach ($periods as $no => $p) {
            if (isset($grid[$sid][$d][$no])) { $cells[$d][$no] = $grid[$sid][$d][$no]; }
        }
    }
    $gridsOut[$sid] = $cells;
}
$periodsOut = [];
foreach ($periods as $no => $p) { $periodsOut[] = ['no' => $no, 'from' => $p['time_from'], 'to' => $p['time_to']]; }

$DATA = [
    'classes'         => $classes,
    'sectionsByClass' => $sectionsByClass,
    'allocTea'        => $allocTea,
    'teachers'        => $teachers,
    'grids'           => $gridsOut,
    'teaSched'        => $teaSched,
    'periods'         => $periodsOut,
    'days'            => array_values($input['days']),
    'dayNames'        => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'],
    'secName'         => $secName,
];
$json = json_encode($DATA, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>NXT School ERP — Timetable studio</title>
<style>
    :root { --brand:#1E4FB8; --ink:#11203F; --mut:#64708a; --line:#e6eaf1; --good:#0c7a43; --bg:#f5f7fb; }
    * { box-sizing:border-box; }
    body { margin:0; font:14px/1.55 -apple-system,'Segoe UI',Roboto,Arial,sans-serif; color:var(--ink); background:var(--bg); }
    header { background:linear-gradient(120deg,#0d2a5e,var(--brand)); color:#fff; padding:18px 28px; }
    header h1 { margin:0; font-size:19px; } header p { margin:4px 0 0; opacity:.85; font-size:12.5px; }
    nav { margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; }
    nav a { color:#fff; background:rgba(255,255,255,.14); padding:6px 13px; border-radius:999px; text-decoration:none; font-weight:600; font-size:12.5px; }
    nav a.on { background:#fff; color:var(--brand); }
    main { max-width:980px; margin:0 auto; padding:22px 24px 64px; }

    .tabs { display:flex; gap:6px; margin-bottom:18px; }
    .tabs button { border:1px solid var(--line); background:#fff; color:var(--mut); font:600 13.5px inherit; padding:9px 18px; border-radius:10px; cursor:pointer; transition:all .12s; }
    .tabs button.on { background:var(--brand); color:#fff; border-color:var(--brand); }

    .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:20px 22px; margin-bottom:18px; }
    .card h2 { font-size:15px; margin:0 0 4px; } .card .sub { color:var(--mut); font-size:12.5px; margin-bottom:16px; }

    .pickers { display:flex; gap:16px; flex-wrap:wrap; }
    .field { flex:1; min-width:180px; }
    .field label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--mut); margin-bottom:6px; }
    select { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:10px; font:inherit; font-size:14px; background:#fff; color:var(--ink); appearance:none; cursor:pointer;
        background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364708a' stroke-width='2.5'><path d='M6 9l6 6 6-6'/></svg>");
        background-repeat:no-repeat; background-position:right 12px center; }
    select:focus { outline:2px solid color-mix(in srgb, var(--brand) 40%, transparent); outline-offset:1px; }
    select:disabled { background:#f1f3f7; color:#aeb6c4; cursor:not-allowed; }

    table.simple { width:100%; border-collapse:collapse; font-size:13px; }
    table.simple th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--mut); padding:8px 10px; border-bottom:2px solid var(--line); }
    table.simple td { padding:9px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
    table.simple tr:last-child td { border-bottom:0; }
    table.simple tr.click { cursor:pointer; } table.simple tr.click:hover td { background:#f5f8ff; }
    .tagrow span { display:inline-block; background:#eef3ff; border:1px solid #d4e0fb; color:var(--brand); border-radius:999px; padding:2px 10px; margin:0 5px 4px 0; font-size:12px; font-weight:600; }
    .muted { color:var(--mut); }
    .link { color:var(--brand); font-weight:600; font-size:12px; }

    .btn { background:var(--brand); color:#fff; border:0; font:600 14px inherit; padding:11px 20px; border-radius:10px; cursor:pointer; }
    .btn:disabled { background:#aeb6c4; cursor:not-allowed; }
    .gen-row { margin-top:18px; display:flex; align-items:center; gap:12px; }
    .spin { display:none; color:var(--brand); font-weight:600; font-size:13px; }

    .chips { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-bottom:12px; }
    .chips .lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--mut); margin-right:4px; }
    .chip { padding:6px 13px; border:1px solid var(--line); border-radius:999px; background:#fff; cursor:pointer; font-size:12.5px; font-weight:600; color:var(--mut); }
    .chip.on { background:var(--brand); color:#fff; border-color:var(--brand); }

    .note { background:#eef3ff; border:1px solid #d4e0fb; color:#1b3a86; border-radius:10px; padding:10px 13px; font-size:12.5px; margin-bottom:14px; }
    .grid-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:8px; }
    .ok-badge { display:inline-flex; align-items:center; gap:6px; background:#f0fdf4; border:1px solid #bbf7d0; color:var(--good); border-radius:999px; padding:4px 12px; font-size:12px; font-weight:700; }
    table.grid { width:100%; border-collapse:collapse; font-size:11.5px; }
    table.grid th, table.grid td { border:1px solid var(--line); padding:6px 7px; text-align:center; }
    table.grid th { background:#f3f6fb; color:var(--mut); font-size:10.5px; font-weight:700; }
    table.grid td.day { background:#f7f9fc; font-weight:700; }
    table.grid td .s { display:block; font-weight:600; }
    table.grid td .t { display:block; color:var(--mut); font-size:9.5px; }
    table.grid td.free { color:#cdd5e1; }

    .search { width:100%; padding:11px 14px; border:1px solid var(--line); border-radius:10px; font:inherit; font-size:14px; margin-bottom:14px; }
    .foot { color:var(--mut); font-size:12px; margin-top:6px; } .foot b { color:#6d28d9; }
    .hide { display:none; }

    .modal { position:fixed; inset:0; background:rgba(17,32,63,.45); display:none; align-items:flex-start; justify-content:center; padding:40px 16px; z-index:50; overflow:auto; }
    .modal.on { display:flex; }
    .modal .box { background:#fff; border-radius:14px; max-width:860px; width:100%; padding:20px 22px; box-shadow:0 24px 60px -12px rgba(17,32,63,.5); }
    .modal .x { float:right; border:0; background:#f1f3f7; border-radius:8px; width:30px; height:30px; cursor:pointer; font-size:16px; color:var(--mut); }
    .modal h3 { margin:0 0 2px; font-size:17px; }
</style>
</head>
<body>
<header>
    <h1>NXT School ERP — Timetable studio</h1>
    <p>One place to generate timetables — a single section, a whole class, or the whole school — and to see every teacher's load.</p>
    <nav>
        <a href="class.php" class="on">Timetable studio</a>
        <a href="logs.php">AI logs</a>
    </nav>
</header>
<main>
    <div class="tabs">
        <button id="tab-gen" class="on" onclick="showTab('gen')">Generate</button>
        <button id="tab-tea" onclick="showTab('tea')">Teachers</button>
    </div>

    <!-- ════ Generate ════ -->
    <div id="panel-gen">
        <div class="card">
            <h2>1 · What do you want to generate?</h2>
            <div class="sub">One studio for every scale. Whole-school solves all classes together, so a teacher is never double-booked across classes.</div>
            <div class="pickers">
                <div class="field">
                    <label>Scope</label>
                    <select id="sel-scope">
                        <option value="section">This section</option>
                        <option value="class">Whole class (all its sections)</option>
                        <option value="school">Whole school (all classes)</option>
                    </select>
                </div>
                <div class="field" id="f-class">
                    <label>Class</label>
                    <select id="sel-class"><option value="">— Select class —</option></select>
                </div>
                <div class="field" id="f-section">
                    <label>Section</label>
                    <select id="sel-section" disabled><option value="">— Select section —</option></select>
                </div>
            </div>
            <div class="gen-row">
                <button class="btn" id="gen-btn" disabled>⚡ Generate</button>
                <span class="spin" id="gen-spin">Solving…</span>
            </div>
        </div>

        <!-- browse chips (whole class / whole school) -->
        <div class="card hide" id="nav-card">
            <div id="res-note"></div>
            <div class="chips hide" id="chips-class"><span class="lbl">Class</span></div>
            <div class="chips hide" id="chips-section"><span class="lbl">Section</span></div>
        </div>

        <!-- 2 · teachers & subjects (shows as soon as a section is chosen) -->
        <div class="card hide" id="alloc-card">
            <h2 id="alloc-title">Teachers &amp; subjects</h2>
            <div class="sub">Who teaches what in this section — a teacher may teach more than one subject. Review this, then generate the timetable.</div>
            <table class="simple">
                <thead><tr><th style="width:42%">Teacher</th><th>Subject(s) taught</th></tr></thead>
                <tbody id="alloc-body"></tbody>
            </table>
            <div class="gen-row" id="sec-gen-row">
                <button class="btn" id="gen-btn2">⚡ Generate this timetable</button>
                <span class="spin" id="gen-spin2">Solving…</span>
            </div>
        </div>

        <!-- 3 · the timetable -->
        <div class="card hide" id="grid-card">
            <div class="grid-head">
                <h2 style="margin:0" id="grid-title">Timetable</h2>
                <span class="ok-badge">✓ clash-free · no teacher double-booked</span>
            </div>
            <div style="overflow-x:auto"><div id="grid-wrap"></div></div>
        </div>

        <div class="foot">In production, plain-English scheduling rules are parsed by <b>Gemini</b> (add your <code>GEMINI_API_KEY</code> when live). This demo runs the built-in deterministic solver.</div>
    </div>

    <!-- ════ Teachers ════ -->
    <div id="panel-tea" class="hide">
        <div class="card">
            <h2>Teachers</h2>
            <div class="sub">Every teacher, the subjects they teach, and the classes &amp; sections they cover. Click a teacher for their full weekly schedule.</div>
            <input class="search" id="tea-search" placeholder="Search teacher or subject…">
            <table class="simple">
                <thead><tr><th style="width:22%">Teacher</th><th style="width:26%">Subject(s)</th><th>Classes &amp; sections</th><th style="width:90px">Periods/wk</th></tr></thead>
                <tbody id="tea-body"></tbody>
            </table>
        </div>
    </div>
</main>

<!-- teacher detail modal -->
<div class="modal" id="tdetail" onclick="if(event.target===this)closeTeacher()">
    <div class="box">
        <button class="x" onclick="closeTeacher()">×</button>
        <h3 id="td-name"></h3>
        <div class="sub muted" id="td-meta"></div>
        <div style="overflow-x:auto;margin-top:12px"><div id="td-grid"></div></div>
    </div>
</div>

<script>
var DATA = <?= $json ?>;
DATA.classes = DATA.classes.map(String); // PHP turns numeric class keys to ints; keep them strings for === checks
function el(id){ return document.getElementById(id); }
function esc(s){ return String(s).replace(/[&<>]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c]; }); }

function showTab(which){
    el('tab-gen').classList.toggle('on', which==='gen');
    el('tab-tea').classList.toggle('on', which==='tea');
    el('panel-gen').classList.toggle('hide', which!=='gen');
    el('panel-tea').classList.toggle('hide', which!=='tea');
}

// populate class dropdown
DATA.classes.forEach(function(c){ var o=document.createElement('option'); o.value=c; o.textContent='Class '+c; el('sel-class').appendChild(o); });

var active = { cls:null, sid:null };
function scope(){ return el('sel-scope').value; }

function hideResults(){
    el('nav-card').classList.add('hide');
    el('alloc-card').classList.add('hide');
    el('grid-card').classList.add('hide');
}
// card-1 Generate button is ONLY for bulk (whole class / whole school).
function refreshGenEnabled(){
    var s = scope();
    el('gen-btn').disabled = !((s==='class' && el('sel-class').value) || s==='school');
}

el('sel-scope').addEventListener('change', function(){
    var s = this.value;
    el('f-class').style.display   = (s==='school') ? 'none' : '';
    el('f-section').style.display = (s==='section') ? '' : 'none';
    el('gen-btn').style.display   = (s==='section') ? 'none' : '';  // section scope generates from the allocation card
    hideResults();
    refreshGenEnabled();
});

el('sel-class').addEventListener('change', function(){
    var sec = el('sel-section');
    sec.innerHTML = '<option value="">— Select section —</option>';
    (DATA.sectionsByClass[this.value] || []).forEach(function(s){
        var o=document.createElement('option'); o.value=s.id; o.textContent='Section '+s.name; sec.appendChild(o);
    });
    sec.disabled = !this.value;
    hideResults();
    refreshGenEnabled();
});

// Section scope: choosing a section IMMEDIATELY shows who teaches what (before generating).
el('sel-section').addEventListener('change', function(){
    el('grid-card').classList.add('hide');
    if (scope()==='section' && this.value){
        active.cls = el('sel-class').value; active.sid = this.value;
        el('nav-card').classList.add('hide');
        showAlloc(this.value, true);
        el('alloc-card').scrollIntoView({behavior:'smooth', block:'nearest'});
    } else {
        el('alloc-card').classList.add('hide');
    }
});

// section-scope generate (button sits with the allocation you just reviewed)
el('gen-btn2').addEventListener('click', function(){
    var spin=el('gen-spin2'); spin.style.display='inline'; this.disabled=true; var self=this;
    setTimeout(function(){
        spin.style.display='none'; self.disabled=false;
        showGrid(active.sid);
        el('grid-card').scrollIntoView({behavior:'smooth', block:'nearest'});
    }, 600);
});

// bulk generate (whole class / whole school)
el('gen-btn').addEventListener('click', function(){
    var spin=el('gen-spin'); spin.style.display='inline'; this.disabled=true; var self=this;
    setTimeout(function(){
        spin.style.display='none'; self.disabled=false;
        buildBulk();
        el('nav-card').scrollIntoView({behavior:'smooth', block:'nearest'});
    }, 600);
});

function showAlloc(sid, withGen){
    var nm = DATA.secName[sid] || '';
    el('alloc-title').innerHTML = 'Teachers &amp; the subject(s) they teach <span class="muted">· '+esc(nm)+'</span>';
    var rows = DATA.allocTea[sid] || [];
    el('alloc-body').innerHTML = rows.map(function(r){
        return '<tr><td><strong>'+esc(r.teacher)+'</strong></td><td class="tagrow">'
             + r.subjects.map(function(x){return '<span>'+esc(x)+'</span>';}).join('')+'</td></tr>';
    }).join('') || '<tr><td colspan="2" class="muted">No allocation.</td></tr>';
    el('sec-gen-row').style.display = withGen ? '' : 'none';
    el('alloc-card').classList.remove('hide');
}
function showGrid(sid){
    var nm = DATA.secName[sid] || '';
    el('grid-title').innerHTML = 'Timetable <span class="muted">· '+esc(nm)+'</span>';
    el('grid-wrap').innerHTML = gridHtml(DATA.grids[sid] || {}, function(c){
        return '<span class="s">'+esc(c.s)+'</span><span class="t">'+esc(c.t)+'</span>';
    });
    el('grid-card').classList.remove('hide');
}
function renderActive(){ showAlloc(active.sid, false); showGrid(active.sid); }

function buildBulk(){
    var s = scope(); var note='';
    el('nav-card').classList.remove('hide');
    if (s==='class'){
        active.cls = el('sel-class').value;
        el('chips-class').classList.add('hide');
        note = 'All sections of Class '+active.cls+' were generated together — switch sections below.';
    } else { // school
        active.cls = DATA.classes[0];
        el('chips-class').classList.remove('hide');
        note = 'Every class was solved in ONE run, so no teacher is double-booked across classes. Browse any class &amp; section below.';
        var cc = el('chips-class'); cc.querySelectorAll('.chip').forEach(function(n){n.remove();});
        DATA.classes.forEach(function(c){
            var b=document.createElement('button'); b.className='chip'; b.textContent=c;
            b.onclick=function(){ active.cls=c; buildSectionChips(); renderActive(); markChips(); };
            cc.appendChild(b);
        });
    }
    el('res-note').innerHTML = '<div class="note">'+note+'</div>';
    buildSectionChips();
    renderActive();
    markChips();
}

function buildSectionChips(){
    var secs = DATA.sectionsByClass[active.cls] || [];
    active.sid = secs.length ? String(secs[0].id) : null;
    var sc = el('chips-section'); sc.classList.remove('hide');
    sc.querySelectorAll('.chip').forEach(function(n){n.remove();});
    secs.forEach(function(s){
        var b=document.createElement('button'); b.className='chip'; b.textContent='Section '+s.name; b.dataset.sid=s.id;
        b.onclick=function(){ active.sid=String(s.id); renderActive(); markChips(); };
        sc.appendChild(b);
    });
}
function markChips(){
    el('chips-class').querySelectorAll('.chip').forEach(function(b){ b.classList.toggle('on', b.textContent===String(active.cls)); });
    el('chips-section').querySelectorAll('.chip').forEach(function(b){ b.classList.toggle('on', b.dataset.sid===String(active.sid)); });
}

el('sel-scope').dispatchEvent(new Event('change')); // set initial control visibility

function gridHtml(cells, cellFn){
    var h = '<table class="grid"><thead><tr><th></th>';
    DATA.periods.forEach(function(p){ h += '<th>P'+p.no+'<br><span style="font-weight:400">'+p.from+'</span></th>'; });
    h += '</tr></thead><tbody>';
    DATA.days.forEach(function(d){
        h += '<tr><td class="day">'+(DATA.dayNames[d]||d)+'</td>';
        DATA.periods.forEach(function(p){
            var c=(cells[d]||{})[p.no];
            h += c ? '<td>'+cellFn(c)+'</td>' : '<td class="free">—</td>';
        });
        h += '</tr>';
    });
    return h+'</tbody></table>';
}

// ── Teachers module + detail ──
function renderTeachers(q){
    q=(q||'').toLowerCase();
    var rows = DATA.teachers.filter(function(t){
        if(!q) return true;
        if(t.name.toLowerCase().indexOf(q)>=0) return true;
        return t.subjects.some(function(s){return s.toLowerCase().indexOf(q)>=0;});
    });
    el('tea-body').innerHTML = rows.map(function(t){
        var subs = t.subjects.map(function(s){return '<span>'+esc(s)+'</span>';}).join('');
        var places = t.rows.map(function(r){return esc(r.section);});
        var uniq = places.filter(function(v,i){return places.indexOf(v)===i;}).join(', ');
        return '<tr class="click" data-tid="'+t.id+'"><td><strong>'+esc(t.name)+'</strong> <span class="link">view ›</span></td>'
             + '<td class="tagrow">'+subs+'</td><td class="muted">'+uniq+'</td><td>'+t.periods+'</td></tr>';
    }).join('') || '<tr><td colspan="4" class="muted">No teachers match.</td></tr>';
}
el('tea-search').addEventListener('input', function(){ renderTeachers(this.value); });
el('tea-body').addEventListener('click', function(e){
    var tr = e.target.closest('tr[data-tid]'); if(tr) openTeacher(parseInt(tr.dataset.tid,10));
});

function openTeacher(tid){
    var t = DATA.teachers.filter(function(x){return x.id===tid;})[0]; if(!t) return;
    el('td-name').textContent = t.name;
    var secs = t.rows.map(function(r){return r.section;}).filter(function(v,i,a){return a.indexOf(v)===i;});
    el('td-meta').textContent = t.subjects.join(', ') + ' · ' + t.periods + ' periods/week · ' + secs.length + ' sections (' + secs.join(', ') + ')';
    var sched = DATA.teaSched[tid] || {};
    el('td-grid').innerHTML = gridHtml(sched, function(c){
        return '<span class="s">'+esc(c.sec)+'</span><span class="t">'+esc(c.sub)+'</span>';
    });
    el('tdetail').classList.add('on');
}
function closeTeacher(){ el('tdetail').classList.remove('on'); }
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeTeacher(); });

renderTeachers('');
</script>
</body>
</html>
