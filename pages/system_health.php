<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requirePermission('lph.system_health.view');

require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/AI/AIServiceFactory.php';

$pageTitle='System Health';
$activeMenu='system_health';
$pdo=db();

/**
 * Avoid direct information_schema queries because some local MariaDB
 * accounts can be denied access to that database. SHOW statements work
 * against the currently-selected legislative_management_db instead.
 */
function healthTableExists(PDO $pdo,string $table): bool {
    $stmt=$pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return (bool)$stmt->fetchColumn();
}

function healthColumnExists(PDO $pdo,string $table,string $column): bool {
    if(!healthTableExists($pdo,$table)) return false;

    $quoted='`'.str_replace('`','``',$table).'`';
    $stmt=$pdo->query("SHOW COLUMNS FROM {$quoted} LIKE " . $pdo->quote($column));
    return (bool)$stmt->fetchColumn();
}

$checks=[];

$requiredTables=[
 'hearings','hearing_history','hearing_documents',
 'stakeholders','stakeholder_history','invitations','invitation_history',
 'registrations','registration_history','qr_codes',
 'attendance','attendance_event_history','hearing_session_days',
 'feedback','feedback_history',
 'feedback_ai_analysis','feedback_ai_analysis_history','ai_request_logs',
 'surveys','survey_questions','survey_question_options','survey_submissions','survey_answers',
 'hearing_issues','hearing_issue_history','hearing_issue_documents',
 'hearing_actions','hearing_action_updates','hearing_action_documents',
 'hearing_responses','hearing_response_history',
 'activity_logs','users','roles','permissions','role_permissions','user_system_access',
 'lph_login_attempts','lph_schema_migrations'
];

foreach($requiredTables as $table){
    $ok=healthTableExists($pdo,$table);
    $checks[]=[
      'status'=>$ok?'pass':'fail',
      'name'=>'Table: '.$table,
      'message'=>$ok?'Available':'Missing — install the required migration/package.'
    ];
}

$requiredColumns=[
 ['attendance','attendance_date'],
 ['attendance_logs','session_date'],
 ['attendance_event_history','session_date'],
 ['feedback','reply_text'],
 ['feedback','replied_at'],
 ['feedback','replied_by'],
 ['feedback_ai_analysis','sentiment'],
 ['feedback_ai_analysis','urgency_level'],
 ['feedback_ai_analysis','urgency_score'],
 ['feedback_ai_analysis','risk_keywords'],
 ['feedback_ai_analysis','review_status'],
 ['feedback_ai_analysis','analysis_version'],
 ['hearing_issues','resolution_summary'],
 ['hearing_issues','resolved_by'],
 ['hearing_issues','resolved_at'],
 ['hearing_responses','status'],
 ['hearing_responses','reference_number'],
 ['hearing_responses','published_by'],
];

foreach($requiredColumns as [$table,$column]){
    $ok=healthColumnExists($pdo,$table,$column);
    $checks[]=[
      'status'=>$ok?'pass':'fail',
      'name'=>"Column: {$table}.{$column}",
      'message'=>$ok?'Available':'Missing — re-run the corresponding migration.'
    ];
}

$migrations=[];
if(healthTableExists($pdo,'lph_schema_migrations')){
    $migrations=$pdo->query(
      'SELECT migration_key,description,applied_at
       FROM lph_schema_migrations
       ORDER BY applied_at,migration_key'
    )->fetchAll();
}

$integrity=[];

try{
    $integrity[]=[
      'name'=>'Duplicate registrations',
      'value'=>(int)$pdo->query(
        'SELECT COUNT(*) FROM (
           SELECT stakeholder_id,hearing_id
           FROM registrations
           GROUP BY stakeholder_id,hearing_id
           HAVING COUNT(*)>1
         ) x'
      )->fetchColumn()
    ];

    $integrity[]=[
      'name'=>'Attendance without registration',
      'value'=>(int)$pdo->query(
        'SELECT COUNT(*)
         FROM attendance a
         LEFT JOIN registrations r
           ON r.stakeholder_id=a.stakeholder_id
          AND r.hearing_id=a.hearing_id
         WHERE r.id IS NULL'
      )->fetchColumn()
    ];

    $integrity[]=[
      'name'=>'Resolved issues without resolution',
      'value'=>(int)$pdo->query(
        "SELECT COUNT(*) FROM hearing_issues
         WHERE status IN ('Resolved','Closed')
           AND (resolution_summary IS NULL OR TRIM(resolution_summary)='')"
      )->fetchColumn()
    ];

    $integrity[]=[
      'name'=>'Published non-public responses',
      'value'=>(int)$pdo->query(
        "SELECT COUNT(*) FROM hearing_responses
         WHERE status='Published' AND visibility<>'Public'"
      )->fetchColumn()
    ];

    if(healthTableExists($pdo,'feedback_ai_analysis')){
        $integrity[]=[
          'name'=>'Completed AI rows without sentiment',
          'value'=>(int)$pdo->query(
            "SELECT COUNT(*) FROM feedback_ai_analysis
             WHERE status='completed'
               AND (sentiment IS NULL OR TRIM(sentiment)='')"
          )->fetchColumn()
        ];

        $integrity[]=[
          'name'=>'Completed AI rows without urgency',
          'value'=>(int)$pdo->query(
            "SELECT COUNT(*) FROM feedback_ai_analysis
             WHERE status='completed'
               AND (urgency_level IS NULL OR TRIM(urgency_level)='')"
          )->fetchColumn()
        ];
    }
}catch(Throwable $e){
    $checks[]=[
      'status'=>'warn',
      'name'=>'Integrity query execution',
      'message'=>'Some integrity checks could not run: '.$e->getMessage()
    ];
}

/* ---------------- Ollama / AI readiness ---------------- */
$aiHealth=[
    'available'=>false,
    'message'=>'AI health check not run.'
];

if(!AI_ENABLED){
    $checks[]=[
      'status'=>'warn',
      'name'=>'Ollama AI',
      'message'=>'AI_ENABLED is FALSE.'
    ];
}else{
    try{
        $aiHealth=AIServiceFactory::make()->healthCheck();
        $checks[]=[
          'status'=>!empty($aiHealth['available'])?'pass':'warn',
          'name'=>'AI Service (' . strtoupper(AI_PROVIDER) . ')',
          'message'=>(string)($aiHealth['message']??'No AI health message.')
        ];
    }catch(Throwable $e){
        $checks[]=[
          'status'=>'warn',
          'name'=>'AI Service (' . strtoupper(AI_PROVIDER) . ')',
          'message'=>'AI health check failed: '.$e->getMessage()
        ];
    }
}

$aiCounts=[
    'analyzed'=>0,
    'pending'=>0,
    'failed'=>0,
    'flagged'=>0,
    'high_critical'=>0,
];

if(healthTableExists($pdo,'feedback_ai_analysis')){
    try{
        $aiCounts['analyzed']=(int)$pdo->query(
            "SELECT COUNT(*) FROM feedback_ai_analysis WHERE status='completed'"
        )->fetchColumn();

        $aiCounts['pending']=(int)$pdo->query(
            "SELECT COUNT(*) FROM feedback_ai_analysis WHERE status='pending'"
        )->fetchColumn();

        $aiCounts['failed']=(int)$pdo->query(
            "SELECT COUNT(*) FROM feedback_ai_analysis WHERE status='failed'"
        )->fetchColumn();

        $aiCounts['flagged']=(int)$pdo->query(
            "SELECT COUNT(*) FROM feedback_ai_analysis WHERE flagged_for_review=1"
        )->fetchColumn();

        $aiCounts['high_critical']=(int)$pdo->query(
            "SELECT COUNT(*) FROM feedback_ai_analysis
             WHERE status='completed' AND urgency_level IN ('High','Critical')"
        )->fetchColumn();
    }catch(Throwable $e){
        $checks[]=[
          'status'=>'warn',
          'name'=>'AI statistics',
          'message'=>'AI statistics could not be calculated: '.$e->getMessage()
        ];
    }
}

if(healthTableExists($pdo,'ai_request_logs')){
    try{
        $recentAiFailures=(int)$pdo->query(
            "SELECT COUNT(*) FROM ai_request_logs
             WHERE success=0
               AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)"
        )->fetchColumn();

        $checks[]=[
          'status'=>$recentAiFailures===0?'pass':'warn',
          'name'=>'AI requests in last 24 hours',
          'message'=>$recentAiFailures===0
            ? 'No failed Ollama requests in the last 24 hours.'
            : "{$recentAiFailures} failed Ollama request(s) in the last 24 hours."
        ];
    }catch(Throwable $e){
        $checks[]=[
          'status'=>'warn',
          'name'=>'AI request logs',
          'message'=>'Unable to check recent AI request failures.'
        ];
    }
}

$uploadWritable=is_dir(UPLOAD_DIR)&&is_writable(UPLOAD_DIR);
$checks[]=[
 'status'=>$uploadWritable?'pass':'warn',
 'name'=>'Uploads directory',
 'message'=>$uploadWritable?'Writable: '.UPLOAD_DIR:'Not writable or missing: '.UPLOAD_DIR
];

$checks[]=[
 'status'=>APP_DEBUG?'warn':'pass',
 'name'=>'Production error display',
 'message'=>APP_DEBUG
   ? 'APP_DEBUG is TRUE. Keep this during local development; set it FALSE before production deployment.'
   : 'APP_DEBUG is disabled.'
];

$permissions=(int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE code LIKE 'lph.%'")->fetchColumn();
$checks[]=[
 'status'=>$permissions>=20?'pass':'warn',
 'name'=>'LPH permission matrix',
 'message'=>"{$permissions} LPH permission definitions found."
];

$q=$pdo->prepare(
 "SELECT COUNT(*) FROM user_system_access WHERE system_id=:system AND status='Active'"
);
$q->execute([':system'=>lphSystemId()]);
$systemAccess=(int)$q->fetchColumn();

$checks[]=[
 'status'=>$systemAccess>0?'pass':'warn',
 'name'=>'Explicit LPH user access',
 'message'=>"{$systemAccess} active user-system access record(s)."
];

$pass=count(array_filter($checks,fn($c)=>$c['status']==='pass'));
$warn=count(array_filter($checks,fn($c)=>$c['status']==='warn'));
$fail=count(array_filter($checks,fn($c)=>$c['status']==='fail'));
$total=max(1,count($checks));
$score=(int)round((($pass + ($warn*0.5))/$total)*100);

$counts=[
 'hearings'=>(int)$pdo->query('SELECT COUNT(*) FROM hearings')->fetchColumn(),
 'stakeholders'=>(int)$pdo->query('SELECT COUNT(*) FROM stakeholders')->fetchColumn(),
 'feedback'=>(int)$pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn(),
 'issues'=>(int)$pdo->query('SELECT COUNT(*) FROM hearing_issues')->fetchColumn(),
 'actions'=>(int)$pdo->query('SELECT COUNT(*) FROM hearing_actions')->fetchColumn(),
 'responses'=>(int)$pdo->query('SELECT COUNT(*) FROM hearing_responses')->fetchColumn(),
];

include __DIR__.'/../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-admin-final.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../layouts/sidebar.php'; ?><div class="main-content">
<?php include __DIR__ . '/../layouts/top_controls.php'; ?>

<div class="lpha-head">
<div>
<div class="lpha-eyebrow"><i class="bi bi-heart-pulse"></i> Final Integration</div>
<h1>Subsystem Health & Readiness</h1>
<p>Read-only validation of the database, authorization, uploads, data integrity and Ollama AI readiness.</p>
</div>
<a class="btn btn-outline-secondary" href="<?= e(APP_URL.'/database/final_validation_ai.sql') ?>" download>
<i class="bi bi-database-check"></i> AI Validation SQL
</a>
</div>

<div class="lpha-score mb-3">
<strong><?= $score ?>%</strong>
<div>
<div class="fw-semibold">Readiness Score</div>
<span><?= $pass ?> PASS · <?= $warn ?> WARNING · <?= $fail ?> FAIL checks.</span>
</div>
</div>

<div class="row g-3 mb-3">
<?php foreach([
 ['Hearings',$counts['hearings'],'bi-calendar-event'],
 ['Stakeholders',$counts['stakeholders'],'bi-people'],
 ['Feedback',$counts['feedback'],'bi-chat-square-text'],
 ['Issues',$counts['issues'],'bi-exclamation-triangle'],
 ['Actions',$counts['actions'],'bi-list-check'],
 ['Responses',$counts['responses'],'bi-reply-all'],
] as [$l,$v,$i]): ?>
<div class="col-6 col-xl-2">
<div class="lpha-stat"><i class="bi <?= e($i) ?>"></i><div><strong><?= $v ?></strong><small><?= e($l) ?></small></div></div>
</div>
<?php endforeach; ?>
</div>

<div class="card lpha-card mb-3">
<div class="card-header"><i class="bi bi-robot"></i> Ollama AI Status</div>
<div class="card-body">
<div class="row g-3">
<?php foreach([
 ['Ollama',!empty($aiHealth['available'])?'Ready':'Review','bi-cpu'],
 ['Analyzed',$aiCounts['analyzed'],'bi-check2-circle'],
 ['Pending',$aiCounts['pending'],'bi-hourglass-split'],
 ['Failed',$aiCounts['failed'],'bi-x-circle'],
 ['Flagged',$aiCounts['flagged'],'bi-flag'],
 ['High/Critical',$aiCounts['high_critical'],'bi-exclamation-triangle'],
] as [$label,$value,$icon]): ?>
<div class="col-6 col-lg-2">
<div class="lpha-stat">
<i class="bi <?= e($icon) ?>"></i>
<div><strong><?= e((string)$value) ?></strong><small><?= e($label) ?></small></div>
</div>
</div>
<?php endforeach; ?>
</div>
<div class="small text-muted mt-3">
<?= e((string)($aiHealth['message']??'')) ?>
</div>
</div>
</div>

<div class="card lpha-card mb-3">
<div class="card-header">System Checks</div>
<div class="card-body"><div class="lpha-health-grid">
<?php foreach($checks as $c): ?>
<div class="lpha-health <?= e($c['status']) ?>">
<strong>
<?php if($c['status']==='pass'): ?><i class="bi bi-check-circle text-success"></i>
<?php elseif($c['status']==='warn'): ?><i class="bi bi-exclamation-triangle text-warning"></i>
<?php else: ?><i class="bi bi-x-circle text-danger"></i>
<?php endif; ?>
<?= e($c['name']) ?>
</strong>
<span><?= e($c['message']) ?></span>
</div>
<?php endforeach; ?>
</div></div>
</div>

<div class="row g-3">
<div class="col-xl-6">
<div class="card lpha-card h-100">
<div class="card-header">Data Integrity</div>
<div class="table-responsive">
<table class="table lpha-table mb-0">
<thead><tr><th>Check</th><th>Problem Rows</th><th>Status</th></tr></thead>
<tbody>
<?php foreach($integrity as $i): ?>
<tr>
<td><?= e($i['name']) ?></td>
<td><?= (int)$i['value'] ?></td>
<td><span class="badge text-bg-<?= (int)$i['value']===0?'success':'warning' ?>"><?= (int)$i['value']===0?'PASS':'REVIEW' ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>

<div class="col-xl-6">
<div class="card lpha-card h-100">
<div class="card-header">Applied LPH Migrations</div>
<div class="card-body lphwf-timeline">
<?php if(!$migrations): ?><div class="text-muted">No migration tracking records found.</div><?php endif; ?>
<?php foreach($migrations as $m): ?>
<div class="mb-2 p-2 bg-light rounded">
<strong class="small"><?= e($m['migration_key']) ?></strong>
<div class="small text-muted"><?= e($m['description']?:'') ?><br><?= formatDateTime($m['applied_at']) ?></div>
</div>
<?php endforeach; ?>
</div>
</div>
</div>
</div>

<div class="alert alert-info mt-3 mb-0">
<strong>Final AI test:</strong> run <code>database/final_validation_ai.sql</code>, then test one Positive, one Neutral and one Negative complaint/feedback through Ollama.
</div>

</div></div>
<?php include __DIR__.'/../layouts/footer.php'; ?>
