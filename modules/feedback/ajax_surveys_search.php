<?php
/**
 * modules/feedback/ajax_surveys_search.php
 * ------------------------------------------------------------------
 * Returns the filtered/paginated surveys table body HTML.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

ob_start();
include __DIR__ . '/surveys_table.php';
$html = ob_get_clean();

jsonResponse(true, '', ['html' => $html]);
