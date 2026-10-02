<?php
declare(strict_types=1);

require_once __DIR__ . '/AIServiceInterface.php';

/**
 * includes/AI/GeminiService.php
 * ------------------------------------------------------------------
 * Google Gemini API integration for Subsystem 7 (LPH).
 * Uses Gemini REST API via cURL to perform structured sentiment,
 * urgency, and category analysis on citizen feedback.
 * ------------------------------------------------------------------
 */
class GeminiService implements AIServiceInterface
{
    private string $apiKey;
    private string $model;
    private int $connectTimeout;
    private int $requestTimeout;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        int $connectTimeout = 5,
        int $requestTimeout = 30
    ) {
        $this->apiKey = $apiKey ?? $this->resolveApiKey();
        $this->model = $model ?? (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-1.5-flash');
        $this->connectTimeout = defined('AI_CONNECT_TIMEOUT_SECONDS') ? (int)AI_CONNECT_TIMEOUT_SECONDS : $connectTimeout;
        $this->requestTimeout = defined('AI_REQUEST_TIMEOUT_SECONDS') ? (int)AI_REQUEST_TIMEOUT_SECONDS : $requestTimeout;
    }

    /**
     * Resolves the Gemini API key from constants, environment, or database.
     */
    private function resolveApiKey(): string
    {
        if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
            return (string)GEMINI_API_KEY;
        }

        $envKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');
        if (!empty($envKey)) {
            return (string)$envKey;
        }

        // Try to load from lph_settings table if available
        try {
            if (function_exists('db')) {
                $pdo = db();
                $stmt = $pdo->prepare("SELECT setting_value FROM lph_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
                $stmt->execute();
                $val = $stmt->fetchColumn();
                if ($val && trim((string)$val) !== '') {
                    return trim((string)$val);
                }
            }
        } catch (Throwable $e) {
            // Ignore database lookup error
        }

        return '';
    }

    public function healthCheck(): array
    {
        if (empty($this->apiKey)) {
            return [
                'available' => false,
                'message' => 'Gemini API key is not configured. Add your GEMINI_API_KEY in config/ai_config.php or system settings.'
            ];
        }

        // Lightweight check: list models
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($this->model) . '?key=' . urlencode($this->apiKey);
        $result = $this->curlRequest('GET', $url, null, 8, 10);

        if (!$result['success']) {
            return ['available' => false, 'message' => 'Cannot reach Google Gemini API: ' . $this->friendlyError($result)];
        }

        if (($result['http_status'] ?? 0) === 400 || ($result['http_status'] ?? 0) === 403) {
            return ['available' => false, 'message' => 'Invalid or unauthorized Gemini API key (HTTP ' . $result['http_status'] . ').'];
        }

        if (($result['http_status'] ?? 0) >= 400) {
            return ['available' => false, 'message' => 'Gemini API returned HTTP ' . (int)$result['http_status'] . '.'];
        }

        return ['available' => true, 'message' => 'Google Gemini API is ready and model "' . $this->model . '" is available.'];
    }

    public function analyzeFeedback(string $text): array
    {
        $startedAt = microtime(true);
        $text = trim($text);

        if ($text === '') {
            return $this->errorResult('Cannot analyze empty feedback text.', 0);
        }

        if (empty($this->apiKey)) {
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            return $this->fallbackAnalysis(
                $text,
                'Gemini API key is missing. Please set GEMINI_API_KEY in config/ai_config.php.',
                $durationMs
            );
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($this->model) . ':generateContent?key=' . urlencode($this->apiKey);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $this->buildPrompt($text)]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => defined('AI_MAX_RESPONSE_TOKENS') ? (int)AI_MAX_RESPONSE_TOKENS : 600,
                'responseMimeType' => 'application/json'
            ]
        ];

        $result = $this->curlRequest('POST', $url, $payload, $this->connectTimeout, $this->requestTimeout);
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        if (!$result['success']) {
            return $this->fallbackAnalysis($text, $this->friendlyError($result), $durationMs);
        }

        if (($result['http_status'] ?? 0) >= 400) {
            $errBody = json_decode((string)$result['body'], true);
            $msg = $errBody['error']['message'] ?? ('HTTP error ' . (int)$result['http_status']);
            return $this->fallbackAnalysis($text, 'Gemini API returned HTTP ' . (int)$result['http_status'] . ': ' . $msg, $durationMs);
        }

        $responseObj = json_decode((string)$result['body'], true);
        $rawCandidateText = $responseObj['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (empty($rawCandidateText)) {
            return $this->fallbackAnalysis($text, 'Gemini returned an empty content candidate.', $durationMs);
        }

        // Clean possible markdown code fences if model enclosed in ```json
        $cleaned = trim($rawCandidateText);
        if (str_starts_with($cleaned, '```json')) {
            $cleaned = substr($cleaned, 7);
        } elseif (str_starts_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 3);
        }
        if (str_ends_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 0, -3);
        }
        $cleaned = trim($cleaned);

        $parsedJson = json_decode($cleaned, true);
        if (!is_array($parsedJson)) {
            return $this->fallbackAnalysis($text, 'Gemini response could not be parsed as valid JSON.', $durationMs);
        }

        $sanitized = $this->sanitizeAnalysis($parsedJson);
        $sanitized['success'] = true;
        $sanitized['raw_response'] = (string)$result['body'];
        $sanitized['error'] = null;
        $sanitized['http_status'] = (int)$result['http_status'];
        $sanitized['duration_ms'] = $durationMs;
        $sanitized['model_used'] = $this->model;
        $sanitized['provider'] = 'gemini';

        return $sanitized;
    }

    private function buildPrompt(string $feedbackText): string
    {
        $safeText = str_replace('```', "'''", $feedbackText);

        return <<<PROMPT
You are an expert AI public sentiment analyst for the Legislative Public Hearing and Consultation Management System (City Council).

Analyze this citizen feedback or public hearing submission:

[BEGIN CITIZEN SUBMISSION]
{$safeText}
[END CITIZEN SUBMISSION]

RULES:
1. Sentiment:
   - "Positive": expression of support, praise, resolution, satisfaction, or gratitude.
   - "Neutral": general inquiry, procedural question, neutral suggestion, request for information.
   - "Negative": grievance, service failure, complaint, dissatisfaction, safety risk, delay, harm, or urgent community problem.
2. Urgency:
   - "Low": non-urgent suggestion, general query, compliment.
   - "Medium": standard complaint or concern needing routine administrative action.
   - "High": serious complaint, health/safety hazard, public disruption, or recurring issue.
   - "Critical": imminent danger, violence, emergency, fire, or severe threat to life/property.
3. Summary: One concise, objective sentence summarizing the citizen's core message.
4. Keywords: Array of up to 6 key subject/topic keywords.
5. Risk Keywords: Array of up to 6 urgency/risk keywords found in or implied by the submission.
6. Recommended Category: Best-fitting municipal issue category (e.g., "Public Safety", "Infrastructure & Roads", "Health & Sanitation", "Zoning & Environment", "Traffic & Transport", "Education", "General Services").
7. Suggested Response: Professional, empathetic 2-3 sentence draft response from the Legislative Committee Secretariat to the citizen.

You MUST respond strictly with a valid JSON object matching this schema:
{
  "sentiment": "Positive" | "Neutral" | "Negative",
  "confidence_score": 0 to 100,
  "urgency_level": "Low" | "Medium" | "High" | "Critical",
  "urgency_score": 0 to 100,
  "keyword_score": 0 to 100,
  "summary": "<concise sentence>",
  "keywords": ["<keyword1>", "<keyword2>"],
  "risk_keywords": ["<risk1>", "<risk2>"],
  "recommended_category": "<category>",
  "suggested_response": "<draft staff response>"
}
PROMPT;
    }

    private function sanitizeAnalysis(array $raw): array
    {
        $sentiment = $this->normalizeEnum(
            $raw['sentiment'] ?? null,
            ['Positive', 'Neutral', 'Negative'],
            'Neutral'
        );

        $urgency = $this->normalizeEnum(
            $raw['urgency_level'] ?? null,
            ['Low', 'Medium', 'High', 'Critical'],
            'Low'
        );

        $summary = is_string($raw['summary'] ?? null) ? trim($raw['summary']) : '';
        if ($summary === '') $summary = 'Feedback received and reviewed.';

        $category = is_string($raw['recommended_category'] ?? null)
            ? trim($raw['recommended_category'])
            : 'General';

        $response = is_string($raw['suggested_response'] ?? null)
            ? trim($raw['suggested_response'])
            : 'Thank you for your submission. The committee secretariat has logged your feedback for review.';

        return [
            'sentiment' => $sentiment,
            'confidence_score' => $this->clampScore($raw['confidence_score'] ?? 85),
            'urgency_level' => $urgency,
            'urgency_score' => $this->clampScore($raw['urgency_score'] ?? 0),
            'keyword_score' => $this->clampScore($raw['keyword_score'] ?? 0),
            'summary' => $summary,
            'keywords' => $this->sanitizeStringList($raw['keywords'] ?? [], 6),
            'risk_keywords' => $this->sanitizeStringList($raw['risk_keywords'] ?? [], 6),
            'recommended_category' => $category,
            'suggested_response' => $response,
        ];
    }

    private function normalizeEnum(mixed $value, array $allowed, string $default): string
    {
        foreach ($allowed as $candidate) {
            if (strcasecmp($candidate, trim((string)$value)) === 0) return $candidate;
        }
        return $default;
    }

    private function clampScore(mixed $value): float
    {
        return is_numeric($value)
            ? round(max(0, min(100, (float)$value)), 2)
            : 0.0;
    }

    private function sanitizeStringList(mixed $value, int $limit): array
    {
        if (!is_array($value)) return [];

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) continue;
            $item = trim($item);
            if ($item === '') continue;
            $items[] = mb_substr($item, 0, 100);
            if (count($items) >= $limit) break;
        }

        return array_values(array_unique($items));
    }

    private function fallbackAnalysis(string $text, string $reason, int $durationMs): array
    {
        // Rule-based fallback if API is not reachable
        $lower = strtolower($text);

        $negativeWords = ['corrupt', 'danger', 'hazard', 'baha', 'delay', 'worst', 'reklamo', 'fail', 'bad', 'broken', 'accident', 'fire', 'crime', 'illegal', 'scam', 'terrible', 'unfair'];
        $positiveWords = ['salamat', 'thank', 'great', 'good', 'approved', 'support', 'commend', 'appreciate', 'congrats', 'helpful', 'efficient', 'best'];

        $negCount = 0;
        foreach ($negativeWords as $w) {
            if (str_contains($lower, $w)) $negCount++;
        }

        $posCount = 0;
        foreach ($positiveWords as $w) {
            if (str_contains($lower, $w)) $posCount++;
        }

        $sentiment = 'Neutral';
        if ($negCount > $posCount) $sentiment = 'Negative';
        elseif ($posCount > $negCount) $sentiment = 'Positive';

        $urgency = ($negCount >= 2) ? 'High' : ($negCount === 1 ? 'Medium' : 'Low');

        return [
            'success' => false,
            'sentiment' => $sentiment,
            'confidence_score' => 60.0,
            'urgency_level' => $urgency,
            'urgency_score' => $negCount * 25.0,
            'keyword_score' => ($negCount + $posCount) * 15.0,
            'summary' => 'Feedback analyzed using fallback heuristic (' . $reason . ').',
            'keywords' => ['citizen feedback', 'public consultation'],
            'risk_keywords' => $negCount > 0 ? ['attention needed'] : [],
            'recommended_category' => 'General Concerns',
            'suggested_response' => 'Thank you for your feedback. We have received your submission and forwarded it to the appropriate committee for evaluation.',
            'raw_response' => json_encode(['fallback' => true, 'reason' => $reason]),
            'error' => $reason,
            'http_status' => null,
            'duration_ms' => $durationMs,
            'model_used' => $this->model . ' (fallback)',
            'provider' => 'gemini'
        ];
    }

    private function errorResult(string $message, int $durationMs): array
    {
        return [
            'success' => false,
            'sentiment' => null,
            'confidence_score' => null,
            'urgency_level' => null,
            'urgency_score' => null,
            'keyword_score' => null,
            'summary' => null,
            'keywords' => [],
            'risk_keywords' => [],
            'recommended_category' => null,
            'suggested_response' => null,
            'raw_response' => '',
            'error' => $message,
            'http_status' => null,
            'duration_ms' => $durationMs,
            'model_used' => $this->model,
            'provider' => 'gemini'
        ];
    }

    private function friendlyError(array $result): string
    {
        $curlErrno = (int)($result['curl_errno'] ?? 0);
        if ($curlErrno === -1) return (string)($result['curl_error'] ?? 'PHP cURL is not enabled.');
        if ($curlErrno === CURLE_OPERATION_TIMEDOUT) return 'Gemini request timed out after ' . $this->requestTimeout . ' seconds.';
        if ($curlErrno !== 0) return (string)($result['curl_error'] ?? 'Connection error.');
        return 'Unexpected API error.';
    }

    private function curlRequest(string $method, string $url, ?array $jsonBody, int $connectTimeout, int $requestTimeout): array
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'http_status' => null,
                'body' => '',
                'curl_errno' => -1,
                'curl_error' => 'PHP cURL is not enabled in php.ini.',
            ];
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            return [
                'success' => false,
                'http_status' => $httpStatus ?: null,
                'body' => $body ?: '',
                'curl_errno' => $errno,
                'curl_error' => $error,
            ];
        }

        return [
            'success' => true,
            'http_status' => $httpStatus,
            'body' => (string)$body,
        ];
    }
}
