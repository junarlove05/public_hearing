<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

function getReportData(string $type, ?string $dateFrom, ?string $dateTo): ?array
{
    $pdo=db();$dateFrom=$dateFrom?:null;$dateTo=$dateTo?:null;
    $where=[];$params=[];

    $dateFilter=function(string $column) use (&$where,&$params,$dateFrom,$dateTo): void {
        if($dateFrom){$where[]="DATE({$column})>=:df";$params[':df']=$dateFrom;}
        if($dateTo){$where[]="DATE({$column})<=:dt";$params[':dt']=$dateTo;}
    };

    switch($type){
      case 'hearings':
        $dateFilter('h.hearing_date');$ws=$where?'WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare("SELECT h.reference_number,h.title,ht.name type_name,c.name committee_name,
          h.hearing_date,h.hearing_time,h.venue,h.status
          FROM hearings h LEFT JOIN hearing_types ht ON ht.id=h.hearing_type_id
          LEFT JOIN committees c ON c.id=h.committee_id {$ws}
          ORDER BY h.hearing_date DESC,h.hearing_time DESC");$s->execute($params);
        $rows=array_map(fn($r)=>[$r['reference_number']?:'-',$r['title'],$r['type_name']?:'-',$r['committee_name']?:'-',formatDate($r['hearing_date']),formatTime($r['hearing_time']),$r['venue']?:'-',$r['status']],$s->fetchAll());
        return ['title'=>'Hearing Schedule Report','headers'=>['Reference','Title','Type','Committee','Date','Time','Venue','Status'],'rows'=>$rows];

      case 'registrations':
        $dateFilter('r.registered_at');$ws=$where?'WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare("SELECT r.registration_code,s.full_name,s.email,h.title hearing_title,
          r.attendance_type,r.registration_status,r.registered_at
          FROM registrations r JOIN stakeholders s ON s.id=r.stakeholder_id
          LEFT JOIN hearings h ON h.id=r.hearing_id {$ws}
          ORDER BY r.registered_at DESC");$s->execute($params);
        $rows=array_map(fn($r)=>[$r['registration_code'],$r['full_name'],$r['email'],$r['hearing_title']?:'-',$r['attendance_type'],$r['registration_status'],formatDateTime($r['registered_at'])],$s->fetchAll());
        return ['title'=>'Stakeholder Registration Report','headers'=>['Code','Stakeholder','Email','Hearing','Attendance Type','Status','Registered'],'rows'=>$rows];

      case 'attendance':
        $dateFilter('a.created_at');$ws=$where?'WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare("SELECT s.full_name,h.title hearing_title,a.status,a.attendance_type,
          a.check_in_method,a.checked_in_at,a.checked_out_at
          FROM attendance a JOIN stakeholders s ON s.id=a.stakeholder_id
          LEFT JOIN hearings h ON h.id=a.hearing_id {$ws}
          ORDER BY a.created_at DESC");$s->execute($params);
        $rows=array_map(fn($r)=>[$r['full_name'],$r['hearing_title']?:'-',$r['status'],$r['attendance_type'],$r['check_in_method']?:'-',$r['checked_in_at']?formatDateTime($r['checked_in_at']):'-',$r['checked_out_at']?formatDateTime($r['checked_out_at']):'-'],$s->fetchAll());
        return ['title'=>'Attendance Report','headers'=>['Stakeholder','Hearing','Status','Type','Method','Check In','Check Out'],'rows'=>$rows];

      case 'stakeholders':
        $dateFilter('s.created_at');$ws=$where?'WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare("SELECT s.full_name,s.email,s.organization,sc.name category_name,s.sector,s.status,s.created_at
          FROM stakeholders s LEFT JOIN stakeholder_categories sc ON sc.id=s.category_id {$ws}
          ORDER BY s.created_at DESC");$s->execute($params);
        $rows=array_map(fn($r)=>[$r['full_name'],$r['email'],$r['organization']?:'-',$r['category_name']?:'-',$r['sector']?:'-',$r['status'],formatDate($r['created_at'])],$s->fetchAll());
        return ['title'=>'Stakeholder Registry Report','headers'=>['Name','Email','Organization','Category','Sector','Status','Created'],'rows'=>$rows];

      case 'feedback':
        $dateFilter('f.submitted_at');$ws=$where?'WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare("SELECT f.id,f.name,f.feedback_position,fc.name category_name,f.subject,f.status,f.visibility,f.submitted_at
          FROM feedback f LEFT JOIN feedback_categories fc ON fc.id=f.category_id {$ws}
          ORDER BY f.submitted_at DESC");$s->execute($params);
        $rows=array_map(fn($r)=>[$r['id'],$r['name'],$r['feedback_position']?:'Comment',$r['category_name']?:'-',$r['subject']?:'-',$r['status'],$r['visibility'],formatDateTime($r['submitted_at'])],$s->fetchAll());
        return ['title'=>'Public Feedback Report','headers'=>['ID','Name','Position','Category','Subject','Status','Visibility','Submitted'],'rows'=>$rows];

      case 'issues':
        $dateFilter('i.created_at');$ws=$where?'WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare("SELECT i.reference_number,i.title,ic.name category_name,
          COALESCE(o.name,u.full_name) assignee,i.priority,i.status,i.due_at,i.created_at
          FROM hearing_issues i LEFT JOIN hearing_issue_categories ic ON ic.id=i.category_id
          LEFT JOIN offices o ON o.id=i.assigned_office_id LEFT JOIN users u ON u.id=i.assigned_user_id
          {$ws} ORDER BY i.created_at DESC");$s->execute($params);
        $rows=array_map(fn($r)=>[$r['reference_number'],$r['title'],$r['category_name']?:'-',$r['assignee']?:'Unassigned',$r['priority'],$r['status'],$r['due_at']?formatDateTime($r['due_at']):'-',formatDate($r['created_at'])],$s->fetchAll());
        return ['title'=>'Issue Tracking Report','headers'=>['Reference','Title','Category','Assignee','Priority','Status','Due','Logged'],'rows'=>$rows];

      case 'actions':
        $dateFilter('a.created_at');$ws=$where?'WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare("SELECT a.reference_number,a.title,i.reference_number issue_ref,
          COALESCE(o.name,u.full_name) assignee,a.deadline,a.status,a.completed_at,a.created_at
          FROM hearing_actions a LEFT JOIN hearing_issues i ON i.id=a.issue_id
          LEFT JOIN offices o ON o.id=a.assigned_office_id LEFT JOIN users u ON u.id=a.assigned_user_id
          {$ws} ORDER BY a.created_at DESC");$s->execute($params);
        $rows=array_map(fn($r)=>[$r['reference_number'],$r['title'],$r['issue_ref']?:'-',$r['assignee']?:'Unassigned',$r['deadline']?formatDate($r['deadline']):'-',$r['status'],$r['completed_at']?formatDateTime($r['completed_at']):'-',formatDate($r['created_at'])],$s->fetchAll());
        return ['title'=>'Response & Action Tracking Report','headers'=>['Reference','Action','Issue','Assignee','Deadline','Status','Completed','Created'],'rows'=>$rows];

      case 'responses':
        $dateFilter('r.created_at');$ws=$where?'WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare("SELECT r.reference_number,i.reference_number issue_ref,r.status,r.visibility,
          p.full_name prepared_by,a.full_name approved_by,r.approved_at,r.published_at
          FROM hearing_responses r JOIN hearing_issues i ON i.id=r.issue_id
          LEFT JOIN users p ON p.id=r.prepared_by LEFT JOIN users a ON a.id=r.approved_by
          {$ws} ORDER BY r.created_at DESC");$s->execute($params);
        $rows=array_map(fn($r)=>[$r['reference_number']?:'-',$r['issue_ref'],$r['status'],$r['visibility'],$r['prepared_by']?:'-',$r['approved_by']?:'-',$r['approved_at']?formatDateTime($r['approved_at']):'-',$r['published_at']?formatDateTime($r['published_at']):'-'],$s->fetchAll());
        return ['title'=>'Official Response Report','headers'=>['Reference','Issue','Status','Visibility','Prepared By','Approved By','Approved','Published'],'rows'=>$rows];

      case 'surveys':
        $dateFilter('s.created_at');$ws=$where?'WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare("SELECT s.title,s.status,s.opens_at,s.closes_at,
          (SELECT COUNT(*) FROM survey_questions q WHERE q.survey_id=s.id) questions,
          (SELECT COUNT(*) FROM survey_submissions ss WHERE ss.survey_id=s.id) responses,
          s.created_at FROM surveys s {$ws} ORDER BY s.created_at DESC");$s->execute($params);
        $rows=array_map(fn($r)=>[$r['title'],$r['status'],$r['questions'],$r['responses'],$r['opens_at']?formatDateTime($r['opens_at']):'-',$r['closes_at']?formatDateTime($r['closes_at']):'-',formatDate($r['created_at'])],$s->fetchAll());
        return ['title'=>'Consultation Survey Report','headers'=>['Survey','Status','Questions','Responses','Opens','Closes','Created'],'rows'=>$rows];

      case 'activity_logs':
        $dateFilter('al.created_at');$ws=$where?'WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare("SELECT u.full_name,al.action,al.details,al.created_at
          FROM activity_logs al LEFT JOIN users u ON u.id=al.user_id {$ws}
          ORDER BY al.created_at DESC LIMIT 5000");$s->execute($params);
        $rows=array_map(fn($r)=>[$r['full_name']?:'System',$r['action'],$r['details']?:'-',formatDateTime($r['created_at'])],$s->fetchAll());
        return ['title'=>'Activity Log Report','headers'=>['User','Action','Details','Date/Time'],'rows'=>$rows];
    }

    return null;
}

function reportTypeMeta(): array
{
    return [
      'hearings'=>['label'=>'Hearings','icon'=>'bi-calendar-event'],
      'registrations'=>['label'=>'Registrations','icon'=>'bi-person-check'],
      'attendance'=>['label'=>'Attendance','icon'=>'bi-qr-code-scan'],
      'stakeholders'=>['label'=>'Stakeholders','icon'=>'bi-people'],
      'feedback'=>['label'=>'Feedback','icon'=>'bi-chat-square-text'],
      'issues'=>['label'=>'Issues','icon'=>'bi-exclamation-triangle'],
      'actions'=>['label'=>'Actions','icon'=>'bi-list-check'],
      'responses'=>['label'=>'Official Responses','icon'=>'bi-reply-all'],
      'surveys'=>['label'=>'Surveys','icon'=>'bi-ui-checks-grid'],
      'activity_logs'=>['label'=>'Activity Logs','icon'=>'bi-clock-history'],
    ];
}
