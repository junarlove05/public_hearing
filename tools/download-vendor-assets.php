<?php
/**
 * tools/download-vendor-assets.php
 * ------------------------------------------------------------------
 * ONE-TIME SETUP SCRIPT — run this once while your machine has
 * internet access, and the entire system will work 100% offline
 * afterwards. It downloads every third-party library (Bootstrap,
 * jQuery, DataTables, SweetAlert2, Chart.js, QR code libraries) into
 * assets/vendor/. Every page in the app already checks for these local
 * copies first (see includes/functions.php::vendorAsset()) and only
 * falls back to a CDN if a file hasn't been downloaded yet — so the
 * app keeps working via CDN before you run this, and switches to
 * fully local files the moment you do. No code changes needed either way.
 *
 * HOW TO RUN:
 *   Browser:  http://localhost/lph/tools/download-vendor-assets.php
 *   CLI:      php tools/download-vendor-assets.php
 *
 * SECURITY NOTE: this script fetches external URLs, which is not
 * something you want exposed on a public production server. Delete
 * this file (or the whole tools/ folder) once you've run it, or make
 * sure it's not reachable outside your local network.
 * ------------------------------------------------------------------
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><title>Download Vendor Assets</title>';
    echo '<style>body{font-family:monospace;background:#0b3d6e;color:#fff;padding:30px;line-height:1.6;}
          .ok{color:#7ee787;} .fail{color:#ff7b72;} .skip{color:#ffd166;} h1{color:#fff;}
          a{color:#7ee787;}</style></head><body>';
    echo '<h1>Downloading Vendor Assets for Offline Use</h1><pre>';
}

function out(string $line): void
{
    global $isCli;
    echo $isCli ? $line . "\n" : $line . "\n";
    if (!$isCli && ob_get_level() > 0) { @ob_flush(); flush(); }
}

$vendorDir = __DIR__ . '/../assets/vendor';

// [local relative path within assets/vendor/] => [source URL]
$manifest = [
    'bootstrap/bootstrap.min.css'                  => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'bootstrap/bootstrap.bundle.min.js'            => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    'bootstrap-icons/bootstrap-icons.css'          => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',
    'bootstrap-icons/fonts/bootstrap-icons.woff2'  => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2',
    'bootstrap-icons/fonts/bootstrap-icons.woff'   => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff',
    'jquery/jquery-3.7.1.min.js'                   => 'https://code.jquery.com/jquery-3.7.1.min.js',
    'datatables/dataTables.bootstrap5.min.css'     => 'https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css',
    'datatables/jquery.dataTables.min.js'          => 'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
    'datatables/dataTables.bootstrap5.min.js'      => 'https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js',
    'sweetalert2/sweetalert2.min.js'               => 'https://cdn.jsdelivr.net/npm/sweetalert2@11.10.7/dist/sweetalert2.all.min.js',
    'chartjs/chart.umd.min.js'                     => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
    'qrcodejs/qrcode.min.js'                       => 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
    'html5-qrcode/html5-qrcode.min.js'             => 'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js',
];

$ok = 0; $fail = 0; $skip = 0;

foreach ($manifest as $relativePath => $url) {
    $destination = $vendorDir . '/' . $relativePath;
    $destDir = dirname($destination);

    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    if (is_file($destination) && filesize($destination) > 0) {
        out("SKIP  already present: $relativePath");
        $skip++;
        continue;
    }

    $context = stream_context_create([
        'http' => ['timeout' => 20, 'header' => "User-Agent: Mozilla/5.0 (compatible; LPH-CMS vendor downloader)\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $content = @file_get_contents($url, false, $context);

    if ($content === false || strlen($content) === 0) {
        out("FAIL  could not download: $relativePath  <-  $url");
        $fail++;
        continue;
    }

    if (file_put_contents($destination, $content) === false) {
        out("FAIL  could not write file (check folder permissions): $destination");
        $fail++;
        continue;
    }

    out("OK    " . number_format(strlen($content) / 1024, 1) . " KB  ->  $relativePath");
    $ok++;
}

out('');
out("Done. $ok downloaded, $skip already present, $fail failed.");
if ($fail > 0) {
    out('Some files failed — check that this server has internet access, or download them');
    out('manually into assets/vendor/ using the paths/URLs listed in the manifest above.');
} else {
    out('All vendor assets are in place. The system will now run fully offline.');
    out('For security, delete this file (or the tools/ folder) now that setup is complete.');
}

if (!$isCli) {
    echo '</pre><p><a href="' . htmlspecialchars(dirname(dirname($_SERVER['REQUEST_URI'] ?? '/lph/tools')) . '/dashboard.php') . '">Return to the app</a></p>';
    echo '</body></html>';
}
