<?php
/**
 * includes/functions.php
 * ------------------------------------------------------------------
 * Reusable, generic helper functions shared by every page/module.
 * Keeping these in one place avoids duplicated code across modules.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';

/* =========================================================
 * OUTPUT / SANITIZATION
 * ========================================================= */

/** Escape a string for safe HTML output (XSS protection). */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Trim + strip tags from user input (generic sanitizer). */
function clean(?string $value): string
{
    return trim(strip_tags($value ?? ''));
}

/* =========================================================
 * REDIRECT / FLASH MESSAGES
 * ========================================================= */

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Store a one-time flash message in the session. */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Retrieve and clear all flash messages. */
function getFlashMessages(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/* =========================================================
 * CSRF PROTECTION
 * ========================================================= */

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(): bool
{
    $token =
        $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_GET['csrf_token']
        ?? '';

    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Call at the top of any state-changing request handler (POST). */
function requireCsrf(): void
{
    if (!verifyCsrf()) {
        http_response_code(403);
        if (isAjaxRequest()) {
            jsonResponse(false, 'Invalid or expired security token. Please refresh the page and try again.');
        }
        die('Invalid or expired security token. Please refresh the page and try again.');
    }
}

/* =========================================================
 * AJAX / JSON HELPERS
 * ========================================================= */

function isAjaxRequest(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Send a JSON response and terminate the script.
 *
 * FIX (network-error root cause): if any PHP warning/notice/deprecation
 * message leaked into the output buffer before this was called (e.g. from
 * an included partial), that stray text would get prepended to the JSON
 * body. The browser's fetch().json() call would then fail to parse the
 * response and reject with a generic error — which is exactly what
 * surfaces to the user as "A network error occurred." even though the
 * server actually processed the request. Discarding any buffered output
 * right before sending the JSON guarantees the response body is always
 * valid, parseable JSON.
 */
function jsonResponse(bool $success, string $message = '', array $data = []): void
{
    jsonResponsePrepare($success, $message, $data);
    exit;
}

/**
 * Same as jsonResponse() but does NOT call exit(). Used together with
 * fastcgi_finish_request() to send the HTTP response to the client while
 * letting the script keep running in the background afterward (e.g. to
 * perform AI analysis without making the citizen wait for it). Only
 * useful when immediately followed by fastcgi_finish_request(); for a
 * normal request/response, use jsonResponse() instead.
 */
function jsonResponsePrepare(bool $success, string $message = '', array $data = []): void
{
    if (ob_get_level() > 0) {
        @ob_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }

    $payload = json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data));

    if ($payload === false) {
        error_log('jsonResponsePrepare() failed to encode payload: ' . json_last_error_msg());
        $payload = json_encode([
            'success' => false,
            'message' => 'The server produced an invalid response. Please try again or contact your administrator.',
        ]);
    }

    echo $payload;

    // Flush this buffer level so the content is ready to send the instant
    // fastcgi_finish_request() is called; harmless if that isn't reached.
    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
}

/* =========================================================
 * FEEDBACK REPLY TRACKING (optional migration support)
 * ========================================================= */

/**
 * Whether the optional reply_text/replied_at/replied_by columns exist on
 * the `feedback` table (see database/migration_001_feedback_reply_tracking.sql).
 * Cached per-request since it's checked on every feedback reply/view.
 * If the migration hasn't been run, the reply feature degrades gracefully
 * (replies still get recorded in activity_logs as before; the public
 * "view reply" page just explains the feature isn't enabled yet instead
 * of erroring).
 */
function feedbackTableHasReplyColumns(): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $stmt = db()->query("SHOW COLUMNS FROM feedback LIKE 'reply_text'");
        $cached = $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/* =========================================================
 * OFFLINE / VENDOR ASSET SUPPORT
 * ========================================================= */

/**
 * Resolve a third-party library (Bootstrap, jQuery, Chart.js, etc.) to a
 * local vendored copy if one has been downloaded, or the CDN URL
 * otherwise. This lets the app run fully offline once
 * tools/download-vendor-assets.php has been run once (with internet
 * access), while continuing to work exactly as before (via CDN) if it
 * hasn't been run yet — no code changes needed either way.
 *
 * @param string $relativePath e.g. 'bootstrap/bootstrap.min.css'
 * @param string $cdnFallback  the original CDN URL to use if the local
 *                             file isn't present yet
 */
function vendorAsset(string $relativePath, string $cdnFallback): string
{
    $localFsPath = __DIR__ . '/../assets/vendor/' . $relativePath;
    if (is_file($localFsPath) && filesize($localFsPath) > 0) {
        return rtrim(APP_URL, '/') . '/assets/vendor/' . $relativePath;
    }
    return $cdnFallback;
}

/* =========================================================
 * DATE / TEXT FORMATTING
 * ========================================================= */

function formatDate(?string $date, string $format = 'M d, Y'): string
{
    if (empty($date) || $date === '0000-00-00') return '-';
    return date($format, strtotime($date));
}

function formatDateTime(?string $datetime, string $format = 'M d, Y h:i A'): string
{
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '-';
    return date($format, strtotime($datetime));
}

function formatTime(?string $time, string $format = 'h:i A'): string
{
    if (empty($time)) return '-';
    return date($format, strtotime($time));
}

function truncate(string $text, int $length = 100): string
{
    $text = strip_tags($text);
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '...' : $text;
}

/* =========================================================
 * STATUS BADGES (Bootstrap 5)
 * ========================================================= */

function statusBadge(string $status): string
{
    $map = [
        // hearings
        'Upcoming'   => 'primary',
        'Ongoing'    => 'warning',
        'Completed'  => 'success',
        'Cancelled'  => 'danger',
        // generic / registrations / invitations
        'Pending'    => 'secondary',
        'Approved'   => 'success',
        'Rejected'   => 'danger',
        'Sent'       => 'info',
        'Active'     => 'success',
        'Inactive'   => 'secondary',
        // attendance
        'Present'    => 'success',
        'Absent'     => 'danger',
        'Late'       => 'warning',
        // feedback / issues
        'New'        => 'info',
        'Reviewed'   => 'primary',
        'Open'       => 'primary',
        'Processing' => 'warning',
        'In Progress'=> 'warning',
        'Resolved'   => 'success',
        'Closed'     => 'secondary',
        'Replied'    => 'success',
        // actions
        'On Going'   => 'warning',
        'On going'   => 'warning',
        'Ongoing '   => 'warning',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . e($status) . '</span>';
}

function priorityBadge(string $priority): string
{
    $map = ['Critical' => 'danger', 'High' => 'danger', 'Medium' => 'warning', 'Low' => 'success'];
    $color = $map[$priority] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . e($priority) . '</span>';
}

/**
 * Render an AI sentiment result as a colored badge with emoji indicator,
 * used consistently across the admin feedback list, view modal, and AI
 * Analytics page. Returns a neutral "not analyzed" badge if $sentiment
 * is null/empty (e.g. AI analysis hasn't run or failed).
 */
function sentimentBadge(?string $sentiment, ?float $confidence = null): string
{
    $map = [
        'Positive' => ['color' => 'success', 'emoji' => '🟢'],
        'Neutral'  => ['color' => 'warning', 'emoji' => '🟡'],
        'Negative' => ['color' => 'danger',  'emoji' => '🔴'],
    ];

    if (!$sentiment || !isset($map[$sentiment])) {
        return '<span class="badge bg-light text-dark border">⚪ Not analyzed</span>';
    }

    $conf = $confidence !== null ? ' (' . round($confidence) . '%)' : '';
    $m = $map[$sentiment];
    return '<span class="badge bg-' . $m['color'] . '">' . $m['emoji'] . ' ' . e($sentiment) . e($conf) . '</span>';
}

/* =========================================================
 * PAGINATION
 * ========================================================= */

/**
 * Build pagination metadata.
 * @return array{page:int, perPage:int, offset:int, totalPages:int, total:int}
 */
function paginate(int $totalRows, int $perPage = DEFAULT_PAGE_SIZE): array
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    return [
        'page'       => $page,
        'perPage'    => $perPage,
        'offset'     => $offset,
        'totalPages' => $totalPages,
        'total'      => $totalRows,
    ];
}

/** Render Bootstrap 5 pagination links, preserving existing query string. */
function renderPagination(array $pageInfo, string $baseUrl): string
{
    if ($pageInfo['totalPages'] <= 1) return '';

    $params = $_GET;
    $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center mb-0">';

    $mkLink = function (int $p) use ($params, $baseUrl) {
        $params['page'] = $p;
        return $baseUrl . '?' . http_build_query($params);
    };

    $current = $pageInfo['page'];
    $total   = $pageInfo['totalPages'];

    $html .= '<li class="page-item' . ($current <= 1 ? ' disabled' : '') . '">
        <a class="page-link" href="' . e($mkLink(max(1, $current - 1))) . '">Previous</a></li>';

    for ($i = 1; $i <= $total; $i++) {
        if ($i === 1 || $i === $total || abs($i - $current) <= 2) {
            $html .= '<li class="page-item' . ($i === $current ? ' active' : '') . '">
                <a class="page-link" href="' . e($mkLink($i)) . '">' . $i . '</a></li>';
        } elseif (abs($i - $current) === 3) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
    }

    $html .= '<li class="page-item' . ($current >= $total ? ' disabled' : '') . '">
        <a class="page-link" href="' . e($mkLink(min($total, $current + 1))) . '">Next</a></li>';

    $html .= '</ul></nav>';
    return $html;
}

/* =========================================================
 * CODE / TOKEN GENERATORS
 * ========================================================= */

function generateCode(string $prefix = ''): string
{
    return strtoupper($prefix . bin2hex(random_bytes(6)));
}

/* =========================================================
 * FILE UPLOAD HELPER
 * ========================================================= */

/**
 * Handle a single file upload with validation.
 * @param array  $file       A single entry from $_FILES
 * @param string $subfolder  Subfolder under assets/uploads/ (e.g. 'hearings')
 * @return array{success:bool, message:string, file_name?:string, file_path?:string}
 */
function handleUpload(array $file, string $subfolder): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'message' => 'No file was uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error (code ' . $file['error'] . ').'];
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['success' => false, 'message' => 'File exceeds maximum allowed size of ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_UPLOAD_EXT, true)) {
        return ['success' => false, 'message' => 'File type not allowed. Allowed: ' . implode(', ', ALLOWED_UPLOAD_EXT)];
    }

    // Basic MIME sanity check (defense in depth, not foolproof). Guarded because the
    // `fileinfo` PHP extension isn't always enabled by default on fresh XAMPP installs —
    // if it's missing, finfo_open() returns false, and calling finfo_file(false, ...)
    // would otherwise fatal-error and silently break every upload in the app.
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            // .docx/.doc files are occasionally reported with a generic zip/octet-stream
            // MIME by some fileinfo databases — the extension check above already
            // constrains the file type, so we only use MIME to catch obvious mismatches
            // (e.g. an .exe renamed to .pdf), not to second-guess a valid extension.
            $allowedMimes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip', 'application/x-zip-compressed', // docx containers on some systems
                'application/octet-stream', // some servers report this for valid doc/docx
                'image/png', 'image/jpeg', 'image/jpg',
            ];
            if ($mime !== false && !in_array($mime, $allowedMimes, true)) {
                return ['success' => false, 'message' => "Invalid file content detected (detected type: $mime). Please upload a genuine " . implode('/', ALLOWED_UPLOAD_EXT) . ' file.'];
            }
        }
        // If finfo_open() itself failed, skip the MIME check rather than blocking every upload —
        // the extension whitelist above already provides a baseline of protection.
    }

    $targetDir = rtrim(UPLOAD_DIR, '/') . '/' . trim($subfolder, '/') . '/';
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return ['success' => false, 'message' => "Could not create the uploads folder ($targetDir). Check that the web server has write permission to assets/uploads/."];
        }
    }
    if (!is_writable($targetDir)) {
        return ['success' => false, 'message' => "The uploads folder ($targetDir) is not writable by the web server. Check its folder permissions."];
    }

    $safeName = generateCode() . '_' . time() . '.' . $ext;
    $destination = $targetDir . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'message' => 'Failed to move the uploaded file into place. Check assets/uploads/ folder permissions and available disk space.'];
    }

    return [
        'success'   => true,
        'message'   => 'File uploaded successfully.',
        'file_name' => $file['name'],
        'file_path' => trim($subfolder, '/') . '/' . $safeName, // stored relative to uploads dir
    ];
}

/* =========================================================
 * MISC
 * ========================================================= */

/** Get the current full request URL (for "return to" redirects). */
function currentUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
}

/** Convert an array of assoc rows into a CSV string. */
function arrayToCsv(array $rows, array $headers = []): string
{
    $fh = fopen('php://temp', 'w+');
    if (!empty($headers)) fputcsv($fh, $headers);
    foreach ($rows as $row) fputcsv($fh, $row);
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

/**
 * Stream a CSV file for download.
 * @param string $filename
 * @param array  $headers  Column headers
 * @param array  $rows     Array of indexed/associative row arrays
 */
function outputCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel renders special characters correctly
    echo arrayToCsv($rows, $headers);
    exit;
}

/**
 * Stream an Excel-openable file for download using the well-known
 * "HTML table served as .xls" technique. No PHPSpreadsheet/Composer
 * dependency required (matches the native-PHP-only stack) — Excel
 * and LibreOffice both open this format directly, preserving column
 * headers and basic formatting.
 * @param string $filename  should end in .xls
 * @param string $title     report title shown above the table
 * @param array  $headers   column headers
 * @param array  $rows      array of indexed row arrays (values in header order)
 */
function outputExcel(string $filename, string $title, array $headers, array $rows): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1" cellpadding="4" cellspacing="0">';
    echo '<tr><td colspan="' . count($headers) . '" style="font-weight:bold;font-size:14px;">' . e($title) . '</td></tr>';
    echo '<tr><td colspan="' . count($headers) . '">Generated: ' . e(date('F j, Y g:i A')) . '</td></tr>';
    echo '<tr></tr>';
    echo '<tr>';
    foreach ($headers as $h) {
        echo '<th style="background:#0b3d6e;color:#ffffff;">' . e($h) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . e((string)$cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}
