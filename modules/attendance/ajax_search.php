<?php
/**
 * modules/attendance/ajax_search.php
 * ------------------------------------------------------------------
 * Returns the filtered/paginated attendance table body HTML for a
 * given hearing.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

ob_start();
include __DIR__ . '/table.php';
$html = ob_get_clean();

jsonResponse(true, '', ['html' => $html]);
