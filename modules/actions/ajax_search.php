<?php
/**
 * modules/actions/ajax_search.php
 * ------------------------------------------------------------------
 * Returns the filtered/sorted/paginated actions table body HTML.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

ob_start();
include __DIR__ . '/table.php';
$html = ob_get_clean();

jsonResponse(true, '', ['html' => $html]);
