<?php
/** Shared, bounded AI transport, adapted from upstream Wallos v5.10.4. Provider output is untrusted data. */
require_once __DIR__ . '/ssrf_helper.php';

function ai_load_settings($db, $userId)
{
    $stmt = $db->prepare('SELECT * FROM ai_settings WHERE user_id = :uid ORDER BY id DESC LIMIT 1');
    $stmt->bindValue(':uid', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $stmt->close();
    return $row ?: null;
}

function ai_settings_fingerprint($settings)
{
    $values = [];
    foreach (['id', 'user_id', 'type', 'enabled', 'model', 'api_key', 'url', 'run_schedule'] as $key) {
        $values[$key] = (string) ($settings[$key] ?? '');
    }
    return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
}

function ai_failure($i18n, $code = 'provider_error', $status = 0)
{
    $messages = [
        'provider_error' => ['ai_provider_error', 'AI provider request failed. Check your settings and retry.'],
        'invalid_response' => ['ai_response_invalid', 'AI returned an invalid response. Existing recommendations were kept.'],
        'data_changed' => ['ai_data_changed', 'Your AI settings or subscription data changed. Please retry.'],
        'invalid_host' => ['invalid_host', 'Invalid or blocked AI host.'],
        'invalid_api_key' => ['invalid_api_key', 'Invalid API key.'],
        'invalid_settings' => ['error', 'Invalid AI settings.'],
        'no_subscriptions' => ['error', 'No active subscriptions to analyze.'],
    ];
    [$key, $fallback] = $messages[$code] ?? $messages['provider_error'];
    $message = function_exists('translate') && isset($i18n[$key]) ? translate($key, $i18n) : $fallback;
    // Never expose curl errors, provider bodies, request URLs, headers or reflected keys.
    return ['success' => false, 'code' => $code, 'message' => $message] + ($status ? ['provider_status' => (int) $status] : []);
}

function ai_log_failure($type, $userId, $reason, $context = [])
{
    $type = in_array($type, ['chatgpt', 'gemini', 'openrouter', 'ollama', 'openai-compatible'], true) ? $type : 'unknown';
    $reason = preg_replace('/[^a-z_]/', '', (string) $reason);
    // Numeric diagnostics only: even an upstream correlation ID can contain reflected credentials.
    $detail = '';
    foreach (['http_status', 'curl_errno'] as $key) {
        if (isset($context[$key])) $detail .= ' ' . $key . '=' . (int) $context[$key];
    }
    error_log('[Wallos AI] provider=' . $type . ' user_id=' . (int) $userId . ' reason=' . $reason . $detail);
}

function ai_get_text_content($content)
{
    if (is_string($content)) return $content;
    if (!is_array($content)) return null;
    $text = '';
    foreach ($content as $part) {
        if (is_array($part) && isset($part['text']) && is_string($part['text'])) $text .= $part['text'];
    }
    return $text !== '' ? $text : null;
}

/** Extract complete top-level JSON documents without being confused by braces inside strings. */
function ai_decode_json_values($content)
{
    if (!is_string($content) || strlen($content) > 2097152) return [];
    $values = [];
    $start = null;
    $stack = [];
    $quoted = false;
    $escaped = false;
    for ($i = 0, $length = strlen($content); $i < $length; $i++) {
        $c = $content[$i];
        if ($start === null) {
            if ($c === '{' || $c === '[') { $start = $i; $stack = [$c]; $quoted = false; $escaped = false; }
            continue;
        }
        if ($quoted) {
            if ($escaped) $escaped = false;
            elseif ($c === '\\') $escaped = true;
            elseif ($c === '"') $quoted = false;
            continue;
        }
        if ($c === '"') $quoted = true;
        elseif ($c === '{' || $c === '[') {
            $stack[] = $c;
            if (count($stack) > 128) return [];
        } elseif ($c === '}' || $c === ']') {
            $open = array_pop($stack);
            if (($open === '{' && $c !== '}') || ($open === '[' && $c !== ']')) { $start = null; $stack = []; continue; }
            if (!$stack) {
                $value = json_decode(substr($content, $start, $i - $start + 1), true, 128);
                if (json_last_error() === JSON_ERROR_NONE && is_array($value)) $values[] = $value;
                if (count($values) > 4096) return [];
                $start = null;
            }
        }
    }
    return $values;
}

function ai_extract_json($content)
{
    if (!is_string($content) || strlen($content) > 2097152) return null;
    $decoded = json_decode(trim($content), true, 128);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
    $values = ai_decode_json_values($content);
    // Ambiguous model output should never choose an arbitrary action payload.
    return count($values) === 1 ? $values[0] : null;
}

function ai_unwrap_provider_response($value)
{
    for ($i = 0; $i < 4 && isset($value['summary']) && is_array($value['summary']); $i++) $value = $value['summary'];
    return $value;
}

function ai_decode_provider_response($reply)
{
    if (!is_string($reply) || strlen($reply) > 2097152) return null;
    $decoded = json_decode($reply, true, 128);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return ai_unwrap_provider_response($decoded);
    $documents = ai_decode_json_values($reply);
    $completion = null;
    $streamed = '';
    $geminiStream = '';
    $isSse = preg_match('/^data:/m', $reply) === 1;
    foreach ($documents as $document) {
        $candidate = ai_unwrap_provider_response($document);
        if (isset($candidate['error'])) return $candidate;
        if (isset($candidate['choices'][0]['message']['content']) || (!$isSse && isset($candidate['candidates'][0]['content']['parts']))) {
            $completion = $candidate;
        }
        if ($isSse) $geminiStream .= ai_get_text_content($candidate['candidates'][0]['content']['parts'] ?? null) ?? '';
        if (strlen($geminiStream) > 2097152) return null;
        $delta = ai_get_text_content($candidate['choices'][0]['delta']['content'] ?? null);
        if ($delta !== null) $streamed .= $delta;
        // Ollama NDJSON may arrive even when stream=false was requested.
        if (isset($candidate['response']) && is_string($candidate['response'])) $streamed .= $candidate['response'];
        if (strlen($streamed) > 2097152) return null;
    }
    if ($completion !== null) return $completion;
    if ($geminiStream !== '') return ['candidates' => [['content' => ['parts' => [['text' => $geminiStream]]]]]];
    return $streamed !== '' ? ['choices' => [['message' => ['content' => $streamed]]]] : null;
}

/** Validate every destination, including fixed providers, before pinning the resolved address. */
function ai_resolve_url($url, $db, $userId)
{
    if (!is_string($url) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
    $parts = parse_url($url);
    if (!$parts || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;
    return is_url_safe_for_ssrf($url, $db, $userId);
}

function ai_validate_settings($settings, $db, $i18n, $userId, $requireModel = true)
{
    if ((int) $userId <= 0 || !is_array($settings) || (isset($settings['user_id']) && (int) $settings['user_id'] !== (int) $userId)) return ai_failure($i18n, 'invalid_settings');
    $type = $settings['type'] ?? '';
    $model = $settings['model'] ?? '';
    $key = $settings['api_key'] ?? '';
    if (!is_string($type) || !in_array($type, ['chatgpt', 'gemini', 'openrouter', 'ollama', 'openai-compatible'], true)
        || !is_string($model) || !mb_check_encoding($model, 'UTF-8') || strlen($model) > 512 || preg_match('/[\x00-\x1f\x7f]/', $model)
        || ($requireModel && trim($model) === '') || !is_string($key) || strlen($key) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $key)) return ai_failure($i18n, 'invalid_settings');
    if (in_array($type, ['chatgpt', 'gemini', 'openrouter'], true) && trim($key) === '') return ai_failure($i18n, 'invalid_api_key');
    if (in_array($type, ['ollama', 'openai-compatible'], true)) {
        $url = $settings['url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        if (!$parts || isset($parts['query']) || !ai_resolve_url($url, $db, $userId)) return ai_failure($i18n, 'invalid_host');
    }
    return ['success' => true];
}

/** Bounded cURL transport shared by completions and model discovery. */
function ai_http_request($url, $headers, $payload, $db, $i18n, $userId, $type, $timeout = 180)
{
    $target = ai_resolve_url($url, $db, $userId);
    if (!$target) return ai_failure($i18n, 'invalid_host');
    $body = '';
    $tooLarge = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => max(1, min(300, (int) $timeout)),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . (str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'])],
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body, &$tooLarge) {
            if (strlen($body) + strlen($chunk) > 2097152) { $tooLarge = true; return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    $ok = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($ok === false || $tooLarge || $status < 200 || $status >= 300) {
        ai_log_failure($type, $userId, $tooLarge ? 'response_too_large' : 'transport_error', ['http_status' => $status, 'curl_errno' => $errno]);
        return ai_failure($i18n, 'provider_error', $status);
    }
    return ['success' => true, 'body' => $body];
}

function ai_build_completion_request($settings, $prompt)
{
    $type = $settings['type'];
    $key = $settings['api_key'] ?? '';
    $model = $settings['model'];
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $payload = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'stream' => false];
    if ($type === 'ollama') {
        $url = rtrim($settings['url'], '/') . '/api/generate';
        $payload = ['model' => $model, 'prompt' => $prompt, 'stream' => false];
    } elseif ($type === 'gemini') {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(preg_replace('~^models/~', '', $model)) . ':generateContent';
        // Keep keys out of URLs, proxy access logs and exception messages.
        $headers[] = 'x-goog-api-key: ' . $key;
        $payload = ['contents' => [['parts' => [['text' => $prompt]]]]];
    } else {
        $url = match ($type) {
            'chatgpt' => 'https://api.openai.com/v1/chat/completions',
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            default => rtrim($settings['url'], '/') . '/chat/completions',
        };
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
    }
    return ['url' => $url, 'headers' => $headers, 'payload' => $payload];
}

/** Official-compatible entry point. No DB transaction is held while waiting on the network. */
function ai_complete($settings, $prompt, $db, $i18n, $userId)
{
    $valid = ai_validate_settings($settings, $db, $i18n, $userId);
    if (!$valid['success']) return $valid;
    if (empty($settings['enabled']) || !is_string($prompt) || !mb_check_encoding($prompt, 'UTF-8') || $prompt === '' || strlen($prompt) > 1048576) return ai_failure($i18n, 'invalid_settings');
    $type = $settings['type'];
    $request = ai_build_completion_request($settings, $prompt);
    $response = ai_http_request($request['url'], $request['headers'], $request['payload'], $db, $i18n, $userId, $type);
    if (!$response['success']) return $response;
    $value = ai_decode_provider_response($response['body']);
    if (!is_array($value) || isset($value['error'])) {
        ai_log_failure($type, $userId, 'invalid_response');
        return ai_failure($i18n, isset($value['error']) ? 'provider_error' : 'invalid_response');
    }
    $content = match ($type) {
        'gemini' => ai_get_text_content($value['candidates'][0]['content']['parts'] ?? $value['choices'][0]['message']['content'] ?? null),
        'ollama' => ai_get_text_content($value['response'] ?? $value['choices'][0]['message']['content'] ?? null),
        default => ai_get_text_content($value['choices'][0]['message']['content'] ?? null),
    };
    if (!is_string($content) || trim($content) === '') return ai_failure($i18n, 'invalid_response');
    return ['success' => true, 'content' => $content];
}
