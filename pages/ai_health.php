<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (function_exists('requirePermission')) {
    requirePermission('lph.system_health.view');
}

require_once __DIR__ . '/../config/ai_config.php';

$pageTitle = 'Ollama AI Health Check';
$activeMenu = 'system_health';

$result = [
    'reachable' => false,
    'http_status' => 0,
    'model_found' => false,
    'message' => '',
    'models' => [],
];

if (!AI_ENABLED) {
    $result['message'] = 'AI is disabled in config/ai_config.php.';
} elseif (!function_exists('curl_init')) {
    $result['message'] = 'PHP cURL extension is not enabled.';
} else {
    $url = rtrim(OLLAMA_BASE_URL, '/') . '/api/tags';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => AI_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result['http_status'] = $status;

    if ($body === false || $error !== '') {
        $result['message'] = 'Cannot connect to Ollama: ' . $error;
    } elseif ($status < 200 || $status >= 300) {
        $result['message'] = 'Ollama returned HTTP ' . $status . '.';
    } else {
        $decoded = json_decode($body, true);
        $models = [];

        foreach (($decoded['models'] ?? []) as $model) {
            $name = (string)($model['name'] ?? $model['model'] ?? '');
            if ($name !== '') {
                $models[] = $name;
            }
        }

        $result['reachable'] = true;
        $result['models'] = $models;

        foreach ($models as $name) {
            if ($name === OLLAMA_MODEL || str_starts_with($name, OLLAMA_MODEL . ':')) {
                $result['model_found'] = true;
                break;
            }
            if (str_starts_with(OLLAMA_MODEL, $name . ':')) {
                $result['model_found'] = true;
                break;
            }
        }

        $result['message'] = $result['model_found']
            ? 'Ollama is reachable and the configured model is installed.'
            : 'Ollama is reachable, but the configured model was not found.';
    }
}

include __DIR__ . '/../layouts/header.php';
?>
<div class="app-wrapper">
    <?php include __DIR__ . '/../layouts/sidebar.php'; ?>

    <main class="main-content">
        <div class="container-fluid py-3">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-cpu"></i> Ollama AI Health Check
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <strong>AI Enabled:</strong>
                        <?= AI_ENABLED ? 'Yes' : 'No' ?>
                    </div>

                    <div class="mb-3">
                        <strong>Provider:</strong>
                        <?= htmlspecialchars(AI_PROVIDER, ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <div class="mb-3">
                        <strong>Ollama URL:</strong>
                        <?= htmlspecialchars(OLLAMA_BASE_URL, ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <div class="mb-3">
                        <strong>Configured Model:</strong>
                        <?= htmlspecialchars(OLLAMA_MODEL, ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <div class="alert <?= $result['reachable'] && $result['model_found'] ? 'alert-success' : 'alert-warning' ?>">
                        <?= htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="border rounded p-3">
                                <small class="text-muted d-block">Ollama Reachable</small>
                                <strong><?= $result['reachable'] ? 'YES' : 'NO' ?></strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3">
                                <small class="text-muted d-block">HTTP Status</small>
                                <strong><?= (int)$result['http_status'] ?></strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3">
                                <small class="text-muted d-block">Model Installed</small>
                                <strong><?= $result['model_found'] ? 'YES' : 'NO' ?></strong>
                            </div>
                        </div>
                    </div>

                    <?php if ($result['models']): ?>
                        <hr>
                        <strong>Installed Models</strong>
                        <ul class="mt-2 mb-0">
                            <?php foreach ($result['models'] as $model): ?>
                                <li><?= htmlspecialchars($model, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../layouts/footer.php'; ?>
