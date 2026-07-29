<?php
/**
 * modules/stakeholders/ajax_search_registrations.php
 * ------------------------------------------------------------------
 * Returns the filtered/sorted/paginated registrations table body HTML.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

ob_start();
include __DIR__ . '/table_registrations.php';
$html = ob_get_clean();

jsonResponse(true, '', ['html' => $html]);
