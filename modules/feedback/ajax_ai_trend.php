<?php
declare(strict_types=1);

/**
 * Combined LPH + Citizen Portal / CEPFMS sentiment trend.
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$period=clean($_GET['period']??'day');
if(!in_array($period,['day','week','month','year'],true))$period='day';

$pdo=db();

$lphTable=(bool)$pdo->query("SHOW TABLES LIKE 'feedback_ai_analysis'")->fetchColumn();
$cefTable=(bool)$pdo->query("SHOW TABLES LIKE 'lph_cef_ai_analysis'")->fetchColumn();

if(!$lphTable&&!$cefTable){
    jsonResponse(true,'',[
        'labels'=>[],
        'positive'=>[],
        'neutral'=>[],
        'negative'=>[],
    ]);
}

switch($period){
    case 'week':
        $lphBucket="DATE_FORMAT(f.submitted_at,'%x-W%v')";
        $cefBucket="DATE_FORMAT(s.created_at,'%x-W%v')";
        $since='DATE_SUB(CURDATE(),INTERVAL 12 WEEK)';
        break;
    case 'month':
        $lphBucket="DATE_FORMAT(f.submitted_at,'%Y-%m')";
        $cefBucket="DATE_FORMAT(s.created_at,'%Y-%m')";
        $since='DATE_SUB(CURDATE(),INTERVAL 12 MONTH)';
        break;
    case 'year':
        $lphBucket="DATE_FORMAT(f.submitted_at,'%Y')";
        $cefBucket="DATE_FORMAT(s.created_at,'%Y')";
        $since='DATE_SUB(CURDATE(),INTERVAL 5 YEAR)';
        break;
    default:
        $lphBucket="DATE_FORMAT(f.submitted_at,'%Y-%m-%d')";
        $cefBucket="DATE_FORMAT(s.created_at,'%Y-%m-%d')";
        $since='DATE_SUB(CURDATE(),INTERVAL 30 DAY)';
        break;
}

$parts=[];
if($lphTable){
    $parts[]=
        "SELECT {$lphBucket} bucket,ai.sentiment
         FROM feedback f
         JOIN feedback_ai_analysis ai ON ai.feedback_id=f.id
         WHERE ai.status='completed'
           AND ai.sentiment IS NOT NULL
           AND f.submitted_at>={$since}";
}
if($cefTable){
    $parts[]=
        "SELECT {$cefBucket} bucket,ai.sentiment
         FROM cef_submissions s
         JOIN lph_cef_ai_analysis ai ON ai.submission_id=s.id
         WHERE ai.status='completed'
           AND ai.sentiment IS NOT NULL
           AND s.deleted_at IS NULL
           AND s.submission_type IN ('Feedback','Complaint')
           AND s.created_at>={$since}";
}

$sql=
    "SELECT bucket,sentiment,COUNT(*) total
     FROM (".implode(' UNION ALL ',$parts).") x
     GROUP BY bucket,sentiment
     ORDER BY bucket";

$rows=$pdo->query($sql)->fetchAll();
$buckets=[];
foreach($rows as $row){
    $bucket=(string)$row['bucket'];
    if(!isset($buckets[$bucket])){
        $buckets[$bucket]=['Positive'=>0,'Neutral'=>0,'Negative'=>0];
    }
    if(isset($buckets[$bucket][$row['sentiment']])){
        $buckets[$bucket][$row['sentiment']]=(int)$row['total'];
    }
}
ksort($buckets);

jsonResponse(true,'',[
    'labels'=>array_keys($buckets),
    'positive'=>array_map(fn($b)=>$b['Positive'],$buckets),
    'neutral'=>array_map(fn($b)=>$b['Neutral'],$buckets),
    'negative'=>array_map(fn($b)=>$b['Negative'],$buckets),
]);
