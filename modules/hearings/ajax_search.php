<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/hearing_helpers.php';

requireLogin();

ob_start();
include __DIR__ . '/table.php';
$html = ob_get_clean();

jsonResponse(true, '', ['html' => $html]);
