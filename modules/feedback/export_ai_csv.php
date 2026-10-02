<?php
declare(strict_types=1);

/**
 * Combined AI CSV export:
 * - LPH Public Feedback
 * - Citizen Portal / CEPFMS Feedback and Complaint records
 */

require_once __DIR__ . '/../../includes/auth.php';
requirePermission('lph.feedback.review');

$pdo=db();

$lphAvailable=(bool)$pdo->query("SHOW TABLES LIKE 'feedback_ai_analysis'")->fetchColumn();
$cefAvailable=(bool)$pdo->query("SHOW TABLES LIKE 'lph_cef_ai_analysis'")->fetchColumn();

$parts=[];

if($lphAvailable){
    $parts[]=
        "SELECT
            'LPH Public Feedback' source_system,
            CAST(f.id AS CHAR) source_id,
            CONCAT('LPH-FB-',f.id) source_reference,
            f.submitted_at submitted_at,
            fc.name feedback_category,
            f.feedback_position citizen_position,
            f.subject subject,
            f.message message,
            ai.analysis_scope,
            ai.sentiment,
            ai.confidence_score,
            ai.urgency_level,
            ai.urgency_score,
            ai.keyword_score,
            ai.summary,
            ai.keywords,
            ai.risk_keywords,
            ai.recommended_category,
            ai.flagged_for_review,
            ai.review_status,
            ai.review_notes,
            ru.full_name reviewed_by_name,
            ai.reviewed_at,
            ai.analysis_version,
            ai.model_used,
            ai.analyzed_at
         FROM feedback_ai_analysis ai
         JOIN feedback f ON f.id=ai.feedback_id
         LEFT JOIN feedback_categories fc ON fc.id=f.category_id
         LEFT JOIN users ru ON ru.id=ai.reviewed_by
         WHERE ai.status='completed'";
}

if($cefAvailable){
    $parts[]=
        "SELECT
            'Citizen Portal / CEPFMS' source_system,
            CAST(s.id AS CHAR) source_id,
            s.reference_number source_reference,
            s.created_at submitted_at,
            c.name feedback_category,
            s.submission_type citizen_position,
            s.title subject,
            s.details message,
            ai.analysis_scope,
            ai.sentiment,
            ai.confidence_score,
            ai.urgency_level,
            ai.urgency_score,
            ai.keyword_score,
            ai.summary,
            ai.keywords,
            ai.risk_keywords,
            ai.recommended_category,
            ai.flagged_for_review,
            ai.review_status,
            ai.review_notes,
            ru.full_name reviewed_by_name,
            ai.reviewed_at,
            ai.analysis_version,
            ai.model_used,
            ai.analyzed_at
         FROM lph_cef_ai_analysis ai
         JOIN cef_submissions s ON s.id=ai.submission_id
         LEFT JOIN cef_categories c ON c.id=s.category_id
         LEFT JOIN users ru ON ru.id=ai.reviewed_by
         WHERE ai.status='completed'
           AND s.deleted_at IS NULL
           AND s.submission_type IN ('Feedback','Complaint')";
}

$rows=[];
if($parts){
    $sql="SELECT * FROM (".implode(' UNION ALL ',$parts).") combined ORDER BY analyzed_at DESC,source_system,source_id DESC";
    $rows=$pdo->query($sql)->fetchAll();
}

$filename='LPH_Combined_AI_Sentiment_Report_'.date('Ymd_His').'.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out=fopen('php://output','w');
fwrite($out,"\xEF\xBB\xBF");

fputcsv($out,[
    'Source System','Source ID','Reference','Submitted At','Category / Type',
    'Citizen Position / Submission Type','Subject / Title','Message / Details',
    'AI Scope','Sentiment','Confidence %','Urgency','Urgency Score %',
    'Keyword Score %','AI Summary','Keywords','Risk / Urgency Keywords',
    'Recommended Category','Flagged for Review','Staff AI Review','Review Notes',
    'Reviewed By','Reviewed At','Analysis Version','Model','Analyzed At'
]);

foreach($rows as $row){
    $keywords=json_decode((string)($row['keywords']??''),true);
    $risk=json_decode((string)($row['risk_keywords']??''),true);

    fputcsv($out,[
        $row['source_system'],$row['source_id'],$row['source_reference'],$row['submitted_at'],
        $row['feedback_category'],$row['citizen_position'],$row['subject'],$row['message'],
        $row['analysis_scope'],$row['sentiment'],$row['confidence_score'],$row['urgency_level'],
        $row['urgency_score'],$row['keyword_score'],$row['summary'],
        is_array($keywords)?implode(', ',$keywords):'',
        is_array($risk)?implode(', ',$risk):'',
        $row['recommended_category'],
        (int)$row['flagged_for_review']===1?'Yes':'No',
        $row['review_status'],$row['review_notes'],$row['reviewed_by_name'],$row['reviewed_at'],
        $row['analysis_version'],$row['model_used'],$row['analyzed_at'],
    ]);
}

fclose($out);
exit;
