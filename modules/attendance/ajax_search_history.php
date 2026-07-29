<?php
/**
 * modules/attendance/ajax_search_history.php
 * ------------------------------------------------------------------
 * Returns the filtered/paginated attendance history table body HTML.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

ob_start();
include __DIR__ . '/history_table.php';
$html = ob_get_clean();

jsonResponse(true, '', ['html' => $html]);
