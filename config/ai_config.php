<?php
/**
 * config/ai_config.php
 * ------------------------------------------------------------------
 * Configuration for the AI sentiment analysis feature. Kept in its own
 * file (separate from config/config.php) so AI settings are easy to
 * find, change, or disable without touching core app configuration.
 *
 * SWITCHING PROVIDERS LATER (Ollama -> OpenAI/Gemini/etc.):
 * Change AI_PROVIDER below and implement a new class that satisfies
 * includes/AI/AIServiceInterface.php (see OllamaService.php for the
 * reference implementation), then register it in
 * includes/AI/AIServiceFactory.php. Nothing else in the app needs to
 * change — every caller talks to the interface, never to Ollama
 * directly.
 * ------------------------------------------------------------------
 */

// ---- Master switch -----------------------------------------------------
// Set to false to disable all AI analysis app-wide (feedback still saves
// normally either way; this only turns the AI enhancement on/off).
define('AI_ENABLED', true);

// ---- Provider selection --------------------------------------------------
// Currently only 'ollama' is implemented. See AIServiceFactory.php.
define('AI_PROVIDER', 'ollama');

// ---- Ollama connection ---------------------------------------------------
define('OLLAMA_BASE_URL', 'http://localhost:11434');

// MODEL SELECTION — pick based on your hardware. Smaller model = much
// faster, works on low-spec laptops, but slightly less nuanced analysis.
// After changing this, run: ollama pull <model-name>
//
//   'llama3.2:1b'   <- DEFAULT. ~1.3GB, runs well on 4-8GB RAM, no GPU
//                      needed. Best choice for low-spec / older laptops.
//   'qwen2.5:0.5b'  <- Even lighter (~400MB) if 1b is still too slow —
//                      very low-spec / very old hardware.
//   'llama3.2:3b'   <- ~2GB, better quality, needs ~8GB+ RAM.
//   'llama3.1:8b'   <- Best quality, but needs 8-16GB+ RAM and/or a GPU.
//                      Will time out on low-spec hardware — this is what
//                      caused the original "timed out after 60 seconds" error.
define('OLLAMA_MODEL', 'llama3.2:1b');

// Generous timeouts: even a light model can be slow on very old hardware
// the first time it loads into memory. These are upper bounds, not
// expected typical times (a warm llama3.2:1b usually answers in 2-10s).
define('AI_CONNECT_TIMEOUT_SECONDS', 5);    // time to establish a connection to Ollama
define('AI_REQUEST_TIMEOUT_SECONDS', 120);  // time to wait for the full analysis response

// Caps how many tokens the model is allowed to generate per analysis.
// Our JSON response is short (sentiment/summary/keywords/etc.), so capping
// this speeds up generation significantly without losing anything useful —
// the single biggest lever for speed on slow hardware, alongside model size.
define('AI_MAX_RESPONSE_TOKENS', 400);

// Keeps the model loaded in memory between requests (in minutes) so
// repeated analyses don't pay the "cold load" cost every single time.
// Increase if you have RAM to spare and submit feedback frequently.
define('AI_MODEL_KEEP_ALIVE_MINUTES', 30);

// ---- Behavior ---------------------------------------------------------
// Sentiment values considered "negative" for flagging/notification purposes.
define('AI_NEGATIVE_SENTIMENT_VALUE', 'Negative');

// Where the human-readable AI debug log is written (in addition to the
// structured ai_request_logs database table).
define('AI_LOG_FILE', __DIR__ . '/../logs/ai.log');
