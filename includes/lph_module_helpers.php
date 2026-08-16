<?php
declare(strict_types=1);

function lphTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=:table'
    );
    $stmt->execute([':table' => $table]);
    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function lphUniqueCode(PDO $pdo, string $table, string $column, string $prefix): string
{
    $allowed = [
        'invitations' => ['invitation_code'],
        'registrations' => ['registration_code'],
        'qr_codes' => ['code_value'],
    ];

    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
        throw new InvalidArgumentException('Unsupported code target.');
    }

    for ($i=0; $i<20; $i++) {
        $code = $prefix . '-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(6)),0,10));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=:code");
        $stmt->execute([':code'=>$code]);
        if ((int)$stmt->fetchColumn()===0) return $code;
    }

    return $prefix . '-' . strtoupper(bin2hex(random_bytes(10)));
}

function lphRegistrationAvailability(PDO $pdo, int $hearingId): array
{
    $stmt = $pdo->prepare(
        'SELECT id,title,status,registration_deadline,maximum_participants
         FROM hearings WHERE id=:id'
    );
    $stmt->execute([':id'=>$hearingId]);
    $h = $stmt->fetch();

    if (!$h) return ['ok'=>false,'message'=>'Hearing not found.','hearing'=>null];

    if (in_array($h['status'], ['Completed','Cancelled'], true)) {
        return ['ok'=>false,'message'=>'Registration is closed for this hearing.','hearing'=>$h];
    }

    if (!empty($h['registration_deadline']) && time() > strtotime($h['registration_deadline'])) {
        return ['ok'=>false,'message'=>'The registration deadline has passed.','hearing'=>$h];
    }

    $count = $pdo->prepare(
        "SELECT COUNT(*) FROM registrations
         WHERE hearing_id=:id AND registration_status IN ('Pending','Approved')"
    );
    $count->execute([':id'=>$hearingId]);
    $current = (int)$count->fetchColumn();

    $max = (int)($h['maximum_participants'] ?? 0);
    if ($max > 0 && $current >= $max) {
        return ['ok'=>false,'message'=>'The hearing has reached its participant capacity.','hearing'=>$h];
    }

    return ['ok'=>true,'message'=>'Registration available.','hearing'=>$h,'current'=>$current,'maximum'=>$max];
}

function lphHistory(PDO $pdo, string $kind, int $entityId, string $action, ?string $old, ?string $new, string $details): void
{
    $maps = [
        'stakeholder' => ['stakeholder_history','stakeholder_id'],
        'invitation' => ['invitation_history','invitation_id'],
        'registration' => ['registration_history','registration_id'],
        'feedback' => ['feedback_history','feedback_id'],
    ];

    if (!isset($maps[$kind])) return;

    [$table,$fk] = $maps[$kind];
    if (!lphTableExists($pdo,$table)) return;

    if ($kind === 'stakeholder') {
        $stmt = $pdo->prepare(
            "INSERT INTO {$table} ({$fk},action,details,changed_by,created_at)
             VALUES (:id,:action,:details,:user,NOW())"
        );
        $stmt->execute([
            ':id'=>$entityId, ':action'=>$action, ':details'=>$details,
            ':user'=>currentUserId()
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO {$table}
            ({$fk},previous_status,new_status,details,changed_by,created_at)
         VALUES (:id,:old,:new,:details,:user,NOW())"
    );
    $stmt->execute([
        ':id'=>$entityId, ':old'=>$old, ':new'=>$new,
        ':details'=>$details, ':user'=>currentUserId()
    ]);
}

function lphSurveyHistory(PDO $pdo, int $surveyId, string $action, string $details): void
{
    if (!lphTableExists($pdo,'survey_history')) return;

    $stmt = $pdo->prepare(
        'INSERT INTO survey_history
         (survey_id,action,details,changed_by,created_at)
         VALUES (:id,:action,:details,:user,NOW())'
    );
    $stmt->execute([
        ':id'=>$surveyId, ':action'=>$action,
        ':details'=>$details, ':user'=>currentUserId()
    ]);
}
