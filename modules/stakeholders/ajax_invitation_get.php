<?php
/**
 * modules/stakeholders/ajax_invitation_get.php
 * ------------------------------------------------------------------
 * Returns a single invitation with joined stakeholder + hearing info,
 * used to populate the email-ready template preview modal.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid invitation id.');

$stmt = db()->prepare(
    'SELECT i.*, s.full_name, s.email, h.title AS hearing_title, h.venue, h.hearing_date, h.hearing_time
     FROM invitations i
     JOIN stakeholders s ON s.id = i.stakeholder_id
     LEFT JOIN hearings h ON h.id = i.hearing_id
     WHERE i.id = :id'
);
$stmt->execute([':id' => $id]);
$invitation = $stmt->fetch();

if (!$invitation) jsonResponse(false, 'Invitation not found.');

jsonResponse(true, '', ['invitation' => $invitation]);
