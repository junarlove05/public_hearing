<?php
/**
 * pages/ajax_users_search.php
 * ------------------------------------------------------------------
 * Returns the filtered/sorted/paginated users table body HTML.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN]);

ob_start();
include __DIR__ . '/users_table.php';
$html = ob_get_clean();

jsonResponse(true, '', ['html' => $html]);
