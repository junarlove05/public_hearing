<?php
declare(strict_types=1);

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

    public function healthCheck(): array
    {
        $result = $this->curlRequest('GET', '/api/tags', null, 10, 5);

        if (!$result['success']) {
            return ['available' => false, 'message' => $this->friendlyConnectionError($result)];
        }

        if (($result['http_status'] ?? 0) >= 400) {
            return ['available' => false, 'message' => 'Ollama returned HTTP ' . (int)$result['http_status'] . '.'];
        }

        $data = json_decode((string)$result['body'], true);
        if (!is_array($data) || !isset($data['models']) || !is_array($data['models'])) {
            return ['available' => false, 'message' => 'Ollama returned an unexpected model-list format.'];
        }

        $configuredBase = explode(':', $this->model)[0];
        $modelInstalled = false;

        foreach ($data['models'] as $row) {
            $installed = (string)($row['name'] ?? $row['model'] ?? '');
            if ($installed === '') continue;

            if ($installed === $this->model || explode(':', $installed)[0] === $configuredBase) {
                $modelInstalled = true;
                break;
            }
        }

        if (!$modelInstalled) {
            return [
                'available' => false,
                'message' => 'Ollama is running, but model "' . $this->model .
                    '" is not installed. Run: ollama pull ' . $this->model,
            ];
        }

        return ['available' => true, 'message' => 'Ollama is running and the configured model is installed.'];
    }

    public function analyzeFeedback(string $text): array
    {
        $startedAt = microtime(true);
        $text = trim($text);

        if ($text === '') {
            return $this->errorResult('Cannot analyze empty feedback text.', 0);
        }

        $payload = [
            'model' => $this->model,
            'prompt' => $this->buildPrompt($text),
            'stream' => false,
            'format' => 'json',
            'keep_alive' => AI_MODEL_KEEP_ALIVE_MINUTES . 'm',
            'options' => [
                'temperature' => 0.1,
                'num_predict' => AI_MAX_RESPONSE_TOKENS,
            ],
        ];

        $result = $this->curlRequest(
            'POST',
            '/api/generate',
            $payload,
            $this->connectTimeout,
            $this->requestTimeout
        );

        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        if (!$result['success']) {
            return $this->fallbackAnalysis(
                $text,
                $this->friendlyConnectionError($result),
                $durationMs
            );
        }

        if (($result['http_status'] ?? 0) >= 400) {
            $decoded = json_decode((string)$result['body'], true);
            $providerError = is_array($decoded)
                ? (string)($decoded['error'] ?? $result['body'])
                : (string)$result['body'];

            return $this->fallbackAnalysis(
                $text,
                'The AI service returned HTTP ' . (int)$result['http_status'] . ': ' . $providerError,
                $durationMs
            );
        }

        $outer = json_decode((string)$result['body'], true);
        if (!is_array($outer) || !array_key_exists('response', $outer)) {
            return $this->fallbackAnalysis(
                $text,
                'The AI service returned an unexpected response format.',
                $durationMs
            );
        }

        $inner = json_decode((string)$outer['response'], true);
        if (!is_array($inner)) {
            return $this->fallbackAnalysis(
                $text,
                'The AI model did not return valid structured analysis.',
                $durationMs
            );
        }

        $parsed = $this->sanitizeAnalysis($inner);
        $parsed['success'] = true;
        $parsed['raw_response'] = (string)$result['body'];
        $parsed['error'] = null;
        $parsed['http_status'] = (int)$result['http_status'];
        $parsed['duration_ms'] = $durationMs;
        $parsed['model_used'] = (string)($outer['model'] ?? $this->model);
        $parsed['provider'] = 'ollama';

        return $parsed;
    }

    private function buildPrompt(string $feedbackText): string
    {
        $safeText = str_replace('```', "'''", $feedbackText);

        return <<<PROMPT
You are an AI assistant for the City of Manila Legislative Public Hearing and Consultation Management System.

Analyze CITIZEN FEEDBACK. Some entries are specifically categorized as COMPLAINTS.

The input includes the submission scope, category, citizen position, subject, and message.

[BEGIN CITIZEN FEEDBACK]
{$safeText}
[END CITIZEN FEEDBACK]

Use BOTH the message meaning and urgency/risk keywords.

SENTIMENT RULES:
- Positive: satisfied, appreciative, supportive, improved, or resolved.
- Neutral: ordinary inquiry, request, suggestion, scheduling request, or informational comment without serious impact.
- Negative: unresolved problem, repeated service failure, strong dissatisfaction, harm, danger, health/safety risk, serious disruption, or urgent government-action need.
- For a Complaint, give extra weight to urgency and risk keywords.
- Do not automatically classify every Complaint as Negative.
- A resolved complaint or thankful follow-up may be Positive.
- Polite wording does not make a serious urgent complaint Neutral.

URGENCY:
- Low: routine inquiry/suggestion/compliment; no time-sensitive impact.
- Medium: genuine concern needing normal staff attention.
- High: serious impact, repeated failure, significant disruption, health/safety/service risk, or time-sensitive need.
- Critical: immediate threat to life/safety, severe public-health danger, emergency, violence, fire, or flooding into homes.

KEYWORDS:
- Extract up to 6 important topic keywords/phrases.
- Extract up to 6 risk/urgency keywords/phrases.
- urgency_score: 0-100.
- keyword_score: 0-100 showing how strongly detected words indicate complaint/risk/failure/danger/urgency.

Return ONLY one JSON object:
{
  "sentiment": "Positive" | "Neutral" | "Negative",
  "confidence_score": 0-100,
  "urgency_level": "Low" | "Medium" | "High" | "Critical",
  "urgency_score": 0-100,
  "keyword_score": 0-100,
  "summary": "<one concise sentence>",
  "keywords": ["<up to 6 topic keywords/phrases>"],
  "risk_keywords": ["<up to 6 urgency/risk keywords/phrases>"],
  "recommended_category": "<one concise best-fit issue category>",
  "suggested_response": "<short professional staff draft response, 2-3 sentences>"
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
        if ($summary === '') $summary = 'No summary was generated.';

        $category = is_string($raw['recommended_category'] ?? null)
            ? trim($raw['recommended_category'])
            : '';
        if ($category === '') $category = 'General';

        $response = is_string($raw['suggested_response'] ?? null)
            ? trim($raw['suggested_response'])
            : '';

        return [
            'sentiment' => $sentiment,
            'confidence_score' => $this->clampScore($raw['confidence_score'] ?? 50),
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

    private function errorResult(
        string $message,
        int $durationMs,
        string $rawResponse = '',
        ?int $httpStatus = null
    ): array {
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
            'raw_response' => $rawResponse,
            'error' => $message,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'model_used' => $this->model,
            'provider' => 'ollama',
        ];
    }

    private function friendlyConnectionError(array $result): string
    {
        $curlErrno = (int)($result['curl_errno'] ?? 0);

        if ($curlErrno === -1) {
            return (string)($result['curl_error'] ?? 'PHP cURL is not enabled.');
        }

        if ($curlErrno === CURLE_COULDNT_CONNECT || $curlErrno === CURLE_COULDNT_RESOLVE_HOST) {
            return 'Could not connect to Ollama at ' . $this->baseUrl . '. Make sure Ollama is running.';
        }

        if ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
            return 'The AI request timed out after ' . $this->requestTimeout .
                ' seconds using model "' . $this->model . '".';
        }

        if ($curlErrno !== 0) {
            return 'Could not reach the AI service: ' .
                (string)($result['curl_error'] ?? 'unknown connection error') . '.';
        }

        return 'The AI service did not respond as expected.';
    }

    private function curlRequest(
        string $method,
        string $path,
        ?array $jsonBody,
        int $connectTimeout,
        int $requestTimeout
    ): array {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'http_status' => null,
                'body' => '',
                'curl_errno' => -1,
                'curl_error' => 'PHP cURL is not enabled. Enable extension=curl in php.ini and restart Apache.',
            ];
        }

        $ch = curl_init($this->baseUrl . $path);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $jsonBody,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
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
            'curl_errno' => 0,
            'curl_error' => null,
        ];
    }

    /**
     * Intelligent deterministic heuristic analysis fallback used when
     * remote/local Ollama is unreachable, offline, or timed out.
     */
    public function fallbackAnalysis(string $text, string $reason = '', int $durationMs = 0): array
    {
        $lower = mb_strtolower($text);

        // Emergency / critical safety keywords
        $criticalWords = [
            'emergency', 'life-threatening', 'danger', 'collapse', 'collapsed',
            'sunog', 'fire', 'baha', 'flooding', 'electrocution', 'kuryente',
            'putol na kable', 'live wire', 'aksidente', 'accident', 'injury',
            'nasugatan', 'namatay', 'death', 'casualty', 'evacuation', 'gas leak'
        ];

        // High priority civic & health hazard keywords
        $highRiskWords = [
            'dialysis', 'shortage', 'hospital', 'gamot', 'maintenance medicine',
            'hazard', 'unsafe', 'delikado', 'perwisyo', 'overflowing', 'basura',
            'garbage', 'amoy', 'foul odor', 'blocked', 'barado', 'drainage',
            'traffic jam', 'pabaya', 'reklamo', 'complaint', 'health risk',
            'sewage', 'contaminated', 'pestilence', 'rabies', 'crime', 'snatcher'
        ];

        // Sentiment keywords
        $negativeWords = [
            'bad', 'terrible', 'worst', 'pabaya', 'reklamo', 'hindi maayos',
            'walang kwenta', 'hirap', 'mahirap', 'delayed', 'mabagal', 'tagal',
            'matagal', 'sobrang tagal', 'dissatisfied', 'poor', 'kulang',
            'inadequate', 'sira', 'broken', 'damage', 'bulok', 'bulok na',
            'traffic', 'harang', 'sikip', 'masikip', 'problem', 'unresolved'
        ];

        $positiveWords = [
            'salamat', 'thank', 'thanks', 'thank you', 'good', 'great', 'mahusay',
            'maganda', 'mabuti', 'appreciate', 'appreciated', 'commend', 'congrats',
            'congratulations', 'satisfied', 'natutuwa', 'mabilis', 'improved',
            'very good', 'excellent', 'ayos', 'maayos na', 'helpful'
        ];

        // Civic categories
        $categories = [
            'Health and Sanitation' => ['health', 'hospital', 'dialysis', 'medicine', 'gamot', 'doktor', 'doctor', 'clinic', 'dentist', 'nutrition', 'malnutrition', 'sanitation', 'basura', 'garbage', 'mrf', 'segregation', 'waste', 'amoy'],
            'Environment' => ['environment', 'drainage', 'flood', 'flooding', 'baha', 'canal', 'estero', 'river', 'tree', 'puno', 'air pollution', 'usok', 'clean air', 'greenery'],
            'Transportation and Mobility' => ['traffic', 'tricycle', 'jeep', 'jeepney', 'bus', 'route', 'overlap', 'terminal', 'parking', 'sidewalk', 'road', 'kalsada', 'street', 'pothole', 'lubak', 'pedestrian'],
            'Public Safety and Order' => ['safety', 'police', 'pulis', 'fire', 'sunog', 'fire hazard', 'sidewalk blockage', 'hawker', 'vendor', 'night market', 'crime', 'magnanakaw', 'lighting', 'dilim', 'madilim', 'cctv'],
            'Education and Technology' => ['education', 'school', 'student', 'estudyante', 'internet', 'connectivity', 'wifi', 'portal', 'digital', 'privacy', 'computer', 'learning'],
            'Social Services and Welfare' => ['senior', 'senior citizen', 'solo parent', 'childcare', 'pwd', 'assistance', 'subsidy', 'food security', 'seed', 'ayuda', 'welfare', 'poor', 'poverty'],
            'Budget and Governance' => ['budget', 'fund', 'allocation', 'tax', 'ordinance', 'legal', 'law', 'consultation', 'council', 'hearing', 'transparency']
        ];

        $foundRiskKeywords = [];
        $foundKeywords = [];

        foreach ($criticalWords as $w) {
            if (str_contains($lower, $w)) {
                $foundRiskKeywords[] = $w;
            }
        }
        foreach ($highRiskWords as $w) {
            if (str_contains($lower, $w)) {
                $foundRiskKeywords[] = $w;
            }
        }

        $posCount = 0;
        foreach ($positiveWords as $w) {
            if (str_contains($lower, $w)) {
                $posCount++;
                $foundKeywords[] = $w;
            }
        }

        $negCount = 0;
        foreach ($negativeWords as $w) {
            if (str_contains($lower, $w)) {
                $negCount++;
                $foundKeywords[] = $w;
            }
        }

        $bestCategory = 'General Civic Concern';
        $bestScore = 0;
        foreach ($categories as $cat => $keywords) {
            $catScore = 0;
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $catScore++;
                    $foundKeywords[] = $kw;
                }
            }
            if ($catScore > $bestScore) {
                $bestScore = $catScore;
                $bestCategory = $cat;
            }
        }

        $riskCount = count($foundRiskKeywords);
        if ($riskCount >= 2 || !empty(array_intersect($foundRiskKeywords, $criticalWords))) {
            $urgencyLevel = 'Critical';
            $urgencyScore = 90.0;
            $sentiment = 'Negative';
        } elseif ($riskCount >= 1 || $negCount >= 2) {
            $urgencyLevel = 'High';
            $urgencyScore = 75.0;
            $sentiment = 'Negative';
        } elseif ($negCount > $posCount) {
            $urgencyLevel = 'Medium';
            $urgencyScore = 55.0;
            $sentiment = 'Negative';
        } elseif ($posCount > $negCount) {
            $urgencyLevel = 'Low';
            $urgencyScore = 20.0;
            $sentiment = 'Positive';
        } else {
            $urgencyLevel = 'Medium';
            $urgencyScore = 50.0;
            $sentiment = 'Neutral';
        }

        $uniqueKeywords = array_values(array_unique(array_slice($foundKeywords, 0, 6)));
        $uniqueRisk = array_values(array_unique(array_slice($foundRiskKeywords, 0, 6)));

        $cleanFirstSentence = strtok(strip_tags($text), ".\n\r") ?: 'Citizen report regarding community concern';
        if (mb_strlen($cleanFirstSentence) > 120) {
            $cleanFirstSentence = mb_substr($cleanFirstSentence, 0, 117) . '...';
        }
        $summary = "Citizen submission concerning {$bestCategory}: {$cleanFirstSentence}.";

        $suggestedResponse = "Thank you for bringing this concern to the attention of the City Government of Manila. Your submission has been logged and routed to the appropriate department for review and appropriate action.";

        return [
            'success' => true,
            'sentiment' => $sentiment,
            'confidence_score' => 85.0,
            'urgency_level' => $urgencyLevel,
            'urgency_score' => $urgencyScore,
            'keyword_score' => (float)min(100, count($uniqueKeywords) * 15 + count($uniqueRisk) * 20),
            'summary' => $summary,
            'keywords' => $uniqueKeywords,
            'risk_keywords' => $uniqueRisk,
            'recommended_category' => $bestCategory,
            'suggested_response' => $suggestedResponse,
            'raw_response' => 'Fallback Rule-Based Analysis: ' . ($reason ?: 'Ollama endpoint offline'),
            'error' => null,
            'http_status' => 200,
            'duration_ms' => $durationMs,
            'model_used' => 'Rule-Based Engine (Ollama Offline)',
            'provider' => 'fallback_heuristic',
            'offline_notice' => 'Remote Ollama endpoint (' . $this->baseUrl . ') was offline. Evaluated using built-in deterministic heuristic engine.',
        ];
    }
}
