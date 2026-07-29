<?php
/**
 * modules/stakeholders/ajax_import_csv.php
 * ------------------------------------------------------------------
 * Bulk-imports stakeholders from an uploaded CSV file.
 * Expected columns (header row required, case-insensitive, any order):
 *   full_name, email, phone, organization, category
 * `category` is matched by name against stakeholder_categories; unknown
 * categories are left blank (category_id = NULL) rather than rejecting
 * the whole row. Duplicate emails (already in DB, or repeated in the
 * file) are skipped and reported back to the user.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(false, 'Please choose a CSV file to import.');
}

$tmpPath = $_FILES['csv_file']['tmp_name'];
$ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    jsonResponse(false, 'Only .csv files are supported.');
}

$handle = fopen($tmpPath, 'r');
if (!$handle) jsonResponse(false, 'Unable to read the uploaded file.');

$header = fgetcsv($handle);
if (!$header) {
    fclose($handle);
    jsonResponse(false, 'The CSV file appears to be empty.');
}
$header = array_map(fn($h) => strtolower(trim($h)), $header);

$colIndex = array_flip($header);
$required = ['full_name', 'email'];
foreach ($required as $r) {
    if (!isset($colIndex[$r])) {
        fclose($handle);
        jsonResponse(false, "CSV is missing required column: $r. Required columns: full_name, email (optional: phone, organization, category).");
    }
}

$pdo = db();
// Build a case-insensitive category name => id map for matching the CSV's "category" column.
$catMap = [];
foreach ($pdo->query('SELECT id, name FROM stakeholder_categories')->fetchAll() as $c) {
    $catMap[strtolower($c['name'])] = (int)$c['id'];
}

$existingEmails = [];
foreach ($pdo->query('SELECT email FROM stakeholders')->fetchAll() as $r) {
    $existingEmails[strtolower($r['email'])] = true;
}

$inserted = 0;
$skipped  = 0;
$skipReasons = [];
$rowNum = 1;

$insertStmt = $pdo->prepare(
    'INSERT INTO stakeholders (full_name, email, phone, organization, category_id, status, created_at)
     VALUES (:name, :email, :phone, :org, :cat, :status, NOW())'
);
$qrStmt = $pdo->prepare('INSERT INTO qr_codes (stakeholder_id, code_value, created_at) VALUES (:sid, :code, NOW())');

$pdo->beginTransaction();
try {
    while (($row = fgetcsv($handle)) !== false) {
        $rowNum++;
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue; // skip blank lines

        $name  = trim($row[$colIndex['full_name']] ?? '');
        $email = trim($row[$colIndex['email']] ?? '');
        $phone = isset($colIndex['phone']) ? trim($row[$colIndex['phone']] ?? '') : '';
        $org   = isset($colIndex['organization']) ? trim($row[$colIndex['organization']] ?? '') : '';
        $catName = isset($colIndex['category']) ? trim($row[$colIndex['category']] ?? '') : '';
        $catId = $catName !== '' ? ($catMap[strtolower($catName)] ?? null) : null;

        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skipped++;
            $skipReasons[] = "Row $rowNum: missing/invalid name or email.";
            continue;
        }
        if (isset($existingEmails[strtolower($email)])) {
            $skipped++;
            $skipReasons[] = "Row $rowNum: email \"$email\" already exists.";
            continue;
        }

        $insertStmt->execute([
            ':name' => $name, ':email' => $email, ':phone' => $phone,
            ':org' => $org, ':cat' => $catId, ':status' => 'Pending',
        ]);
        $newId = (int)$pdo->lastInsertId();
        $qrStmt->execute([':sid' => $newId, ':code' => generateCode('STK-')]);

        $existingEmails[strtolower($email)] = true; // guard against duplicate rows within the same file
        $inserted++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fclose($handle);
    error_log('CSV import error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred while importing the CSV file. No records were saved.');
}

fclose($handle);

logActivity(currentUserId(), 'Import', "Imported $inserted stakeholder(s) from CSV, $skipped skipped.");

jsonResponse(true, "Import complete: $inserted added, $skipped skipped.", [
    'inserted' => $inserted,
    'skipped'  => $skipped,
    'skip_reasons' => array_slice($skipReasons, 0, 20), // cap for a manageable response
]);
