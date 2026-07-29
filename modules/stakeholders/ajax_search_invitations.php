<?php
/**
 * modules/stakeholders/ajax_search_invitations.php
 * ------------------------------------------------------------------
 * Returns the filtered/sorted/paginated invitations table body HTML.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

ob_start();
include __DIR__ . '/table_invitations.php';
$html = ob_get_clean();

jsonResponse(true, '', ['html' => $html]);
