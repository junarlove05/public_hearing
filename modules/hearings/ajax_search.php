<?php
/**
 * modules/hearings/ajax_search.php
 * ------------------------------------------------------------------
 * Returns the filtered/sorted/paginated hearings table body as HTML
 * for the live AJAX search/filter/pagination/sorting UI on index.php.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

ob_start();
include __DIR__ . '/table.php';
$html = ob_get_clean();

jsonResponse(true, '', ['html' => $html]);
