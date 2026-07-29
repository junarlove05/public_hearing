<?php
/**
 * modules/attendance/ajax_stakeholder_search.php
 * ------------------------------------------------------------------
 * Lightweight search-as-you-type endpoint for the manual attendance
 * form, so staff can find a stakeholder by name/email without
 * scrolling a long dropdown.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$q = clean($_GET['q'] ?? '');
if (mb_strlen($q) < 2) jsonResponse(true, '', ['stakeholders' => []]);

$stmt = db()->prepare(
    "SELECT id, full_name, email, organization FROM stakeholders
     WHERE full_name LIKE :q1 OR email LIKE :q2
     ORDER BY full_name LIMIT 15"
);
$stmt->execute([':q1' => '%' . $q . '%', ':q2' => '%' . $q . '%']);

jsonResponse(true, '', ['stakeholders' => $stmt->fetchAll()]);
