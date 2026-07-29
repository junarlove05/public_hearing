<?php
/**
 * pages/ajax_activity_logs_search.php
 * ------------------------------------------------------------------
 * Returns the filtered/paginated activity logs table body HTML.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN]);

ob_start();
include __DIR__ . '/activity_logs_table.php';
$html = ob_get_clean();

jsonResponse(true, '', ['html' => $html]);
