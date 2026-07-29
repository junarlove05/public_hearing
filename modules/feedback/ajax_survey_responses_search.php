<?php
/**
 * modules/feedback/ajax_survey_responses_search.php
 * ------------------------------------------------------------------
 * Returns the filtered/paginated survey responses table body HTML.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

ob_start();
include __DIR__ . '/survey_responses_table.php';
$html = ob_get_clean();

jsonResponse(true, '', ['html' => $html]);
