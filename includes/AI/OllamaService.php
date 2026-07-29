<?php
/**
 * includes/AI/OllamaService.php
 * ------------------------------------------------------------------
 * Talks to a locally-running Ollama instance (http://localhost:11434)
 * to perform sentiment analysis on feedback text. Implements
 * AIServiceInterface so it can be swapped for a cloud provider later
 * without touching any other file.
 *
 * Uses Ollama's /api/generate endpoint with "format": "json" to force
 * the model to return syntactically valid JSON, then validates and
 * sanitizes that JSON against our expected schema in PHP (a model can
 * return valid JSON that doesn't match our exact field names/types, so
 * we never trust it blindly).
 *
 * Requires ONLY native PHP cURL — no Composer packages, no internet
 * access beyond the local Ollama server.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/AIServiceInterface.php';

class OllamaService implements AIServiceInterface
{
    private string $baseUrl;
    private string $model;
    private int $connectTimeout;
    private int $requestTimeout;

    public function __construct(
        string $baseUrl = OLLAMA_BASE_URL,
        string $model = OLLAMA_MODEL,
        int $connectTimeout = AI_CONNECT_TIMEOUT_SECONDS,
        int $requestTimeout = AI_REQUEST_TIMEOUT_SECONDS
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
        $this->connectTimeout = $connectTimeout;
        $this->requestTimeout = $requestTimeout;
    }

    /* ---------------------------------------------------------------
     * Public API (AIServiceInterface)
     * --------------------------------------------------------------- */

    public function healthCheck(): array
    {
        // /api/tags lists installed models — cheap, fast way to confirm
        // Ollama is running AND that our configured model is installed.
        $result = $this->curlRequest('GET', '/api/tags', null, 10, 5);

        if (!$result['success']) {
            return ['available' => false, 'message' => $this->friendlyConnectionError($result)];
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data) || !isset($data['models'])) {
            return ['available' => false, 'message' => 'Ollama responded, but with an unexpected format. Is the server healthy?'];
        }

        $installedModels = array_column($data['models'], 'name');
        $modelInstalled = false;
        foreach ($installedModels as $installed) {
            // Ollama model names can include/omit the ":tag" suffix; compare loosely.
            if ($installed === $this->model || strpos($installed, explode(':', $this->model)[0]) === 0) {
                $modelInstalled = true;
                break;
            }
        }

        if (!$modelInstalled) {
            return [
                'available' => false,
                'message' => "Ollama is running, but the model \"{$this->model}\" is not installed. Run: ollama pull {$this->model}",
            ];
        }

        return ['available' => true, 'message' => 'Ollama is running and the model is installed.'];
    }

    public function analyzeFeedback(string $text): array
    {
        $startTime = microtime(true);
        $text = trim($text);

        if ($text === '') {
            return $this->errorResult('Cannot analyze empty feedback text.', 0);
        }

        $prompt = $this->buildPrompt($text);

        $payload = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json', // forces Ollama to constrain output to valid JSON syntax
            'keep_alive' => AI_MODEL_KEEP_ALIVE_MINUTES . 'm', // keep model warm in memory between requests
            'options' => [
                'temperature' => 0.2,     // low temperature: consistent, less creative/random analysis
                'num_predict' => AI_MAX_RESPONSE_TOKENS, // cap output length — biggest speed lever on slow hardware
            ],
        ];

        $result = $this->curlRequest('POST', '/api/generate', $payload, $this->connectTimeout, $this->requestTimeout);
        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        if (!$result['success']) {
            $errorMsg = $this->friendlyConnectionError($result);
            return $this->errorResult($errorMsg, $durationMs, $result['body'] ?? '', $result['http_status'] ?? null);
        }

        // Ollama returned an HTTP response — but it might still be an error
        // (e.g. 404 "model not found") rather than a successful generation.
        if ($result['http_status'] >= 400) {
            $errBody = json_decode($result['body'], true);
            $ollamaError = is_array($errBody) && isset($errBody['error']) ? $errBody['error'] : $result['body'];

            if (stripos((string)$ollamaError, 'not found') !== false) {
                $msg = "The AI model \"{$this->model}\" is not installed on this Ollama server. Run: ollama pull {$this->model}";
            } else {
                $msg = 'The AI service returned an error: ' . $ollamaError;
            }
            return $this->errorResult($msg, $durationMs, $result['body'], $result['http_status']);
        }

        $outer = json_decode($result['body'], true);
        if (!is_array($outer) || !isset($outer['response'])) {
            return $this->errorResult(
                'The AI service returned an unexpected response format.',
                $durationMs, $result['body'], $result['http_status']
            );
        }

        // $outer['response'] is itself a JSON string (because we requested format=json) —
        // this is the model's actual structured analysis.
        $inner = json_decode($outer['response'], true);
        if (!is_array($inner)) {
            return $this->errorResult(
                'The AI model did not return valid structured analysis. Try again, or try a different model.',
                $durationMs, $result['body'], $result['http_status']
            );
        }

        $parsed = $this->sanitizeAnalysis($inner);
        $parsed['success'] = true;
        $parsed['raw_response'] = $result['body'];
        $parsed['error'] = null;
        $parsed['http_status'] = $result['http_status'];
        $parsed['duration_ms'] = $durationMs;
        $parsed['model_used'] = $outer['model'] ?? $this->model;
        $parsed['provider'] = 'ollama';

        return $parsed;
    }

    /* ---------------------------------------------------------------
     * Internals
     * --------------------------------------------------------------- */

    /**
     * Build a prompt that firmly constrains the model to a specific JSON
     * schema. Explicit about every field, valid values, and format, since
     * "format": "json" only guarantees syntactically valid JSON — not
     * that it matches OUR schema.
     */
    private function buildPrompt(string $feedbackText): string
    {
        // Escape the feedback text minimally for prompt embedding (it's not
        // executed as code, but we avoid letting stray content break the
        // instructive framing around it).
        $safeText = str_replace(['"""', '```'], ["'''", "'''"], $feedbackText);

        return <<<PROMPT
You are an AI assistant for a government legislative public hearing system. Analyze the following citizen feedback and respond with ONLY a single valid JSON object — no markdown, no code fences, no explanation text before or after it.

Citizen feedback to analyze:
\"\"\"
{$safeText}
\"\"\"

Return a JSON object with EXACTLY these fields:
{
  "sentiment": "Positive" | "Neutral" | "Negative",
  "confidence_score": <number 0-100, your confidence in the sentiment classification>,
  "summary": "<one concise sentence summarizing the feedback>",
  "keywords": ["<keyword1>", "<keyword2>", "<up to 6 short keywords/phrases>"],
  "recommended_category": "<the single best-fit issue category, e.g. Policy, Procedure, Logistics, Compliance, Budget, Public Safety, Infrastructure, Environment, or another concise category name>",
  "suggested_response": "<a short, professional, empathetic draft response a legislative staff member could send to this citizen, 2-3 sentences>"
}

Rules:
- sentiment must be exactly one of: Positive, Neutral, Negative
- confidence_score must be a number between 0 and 100
- keywords must be an array of short strings, no more than 6 items
- Do not include any text outside the single JSON object.
PROMPT;
    }

    /**
     * Validate and clamp the model's parsed JSON against our expected
     * schema, filling in safe defaults for anything missing or malformed —
     * never trust an LLM's output blindly, even when format=json was used.
     */
    private function sanitizeAnalysis(array $raw): array
    {
        $sentiment = $raw['sentiment'] ?? null;
        if (!in_array($sentiment, ['Positive', 'Neutral', 'Negative'], true)) {
            // Try a case-insensitive match before giving up.
            $normalized = ucfirst(strtolower((string)$sentiment));
            $sentiment = in_array($normalized, ['Positive', 'Neutral', 'Negative'], true) ? $normalized : 'Neutral';
        }

        $confidence = $raw['confidence_score'] ?? null;
        $confidence = is_numeric($confidence) ? max(0, min(100, (float)$confidence)) : 50.0;

        $summary = is_string($raw['summary'] ?? null) ? trim($raw['summary']) : '';
        if ($summary === '') $summary = 'No summary was generated.';

        $keywords = [];
        if (is_array($raw['keywords'] ?? null)) {
            foreach ($raw['keywords'] as $kw) {
                if (is_string($kw) && trim($kw) !== '') $keywords[] = trim($kw);
                if (count($keywords) >= 6) break;
            }
        }

        $category = is_string($raw['recommended_category'] ?? null) ? trim($raw['recommended_category']) : '';
        if ($category === '') $category = 'General';

        $response = is_string($raw['suggested_response'] ?? null) ? trim($raw['suggested_response']) : '';

        return [
            'sentiment' => $sentiment,
            'confidence_score' => round($confidence, 2),
            'summary' => $summary,
            'keywords' => $keywords,
            'recommended_category' => $category,
            'suggested_response' => $response,
        ];
    }

    /** Build a consistent error-result array matching the interface contract. */
    private function errorResult(string $message, int $durationMs, string $rawResponse = '', ?int $httpStatus = null): array
    {
        return [
            'success' => false,
            'sentiment' => null,
            'confidence_score' => null,
            'summary' => null,
            'keywords' => [],
            'recommended_category' => null,
            'suggested_response' => null,
            'raw_response' => $rawResponse,
            'error' => $message,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'model_used' => $this->model,
            'provider' => 'ollama',
        ];
    }

    /** Translate a low-level cURL failure into a friendly, specific message. */
    private function friendlyConnectionError(array $result): string
    {
        $curlErrno = $result['curl_errno'] ?? 0;

        if ($curlErrno === -1) {
            return $result['curl_error'] ?? 'The PHP curl extension is not enabled.';
        }
        if ($curlErrno === CURLE_COULDNT_CONNECT || $curlErrno === CURLE_COULDNT_RESOLVE_HOST) {
            return "Could not connect to the AI service at {$this->baseUrl}. Make sure Ollama is running (try: ollama serve).";
        }
        if ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
            return "The AI analysis request timed out after {$this->requestTimeout} seconds using model \"{$this->model}\". " .
                "If this keeps happening, your hardware may need an even lighter model — try: ollama pull qwen2.5:0.5b, " .
                "then set OLLAMA_MODEL to 'qwen2.5:0.5b' in config/ai_config.php.";
        }
        if ($curlErrno !== 0) {
            return 'Could not reach the AI service: ' . ($result['curl_error'] ?? 'unknown connection error') . '.';
        }
        return 'The AI service did not respond as expected.';
    }

    /**
     * Low-level cURL wrapper shared by all requests to Ollama.
     * @return array{success:bool, http_status:?int, body:?string, curl_errno:int, curl_error:?string}
     */
    private function curlRequest(string $method, string $path, ?array $jsonBody, int $connectTimeout, int $requestTimeout): array
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false, 'http_status' => null, 'body' => '',
                'curl_errno' => -1, 'curl_error' => 'The PHP curl extension is not enabled. Enable it in php.ini (extension=curl) and restart your web server.',
            ];
        }

        $url = $this->baseUrl . $path;
        $ch = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            return ['success' => false, 'http_status' => null, 'body' => $body ?: '', 'curl_errno' => $errno, 'curl_error' => $error];
        }

        return ['success' => true, 'http_status' => $httpStatus, 'body' => $body, 'curl_errno' => 0, 'curl_error' => null];
    }
}
