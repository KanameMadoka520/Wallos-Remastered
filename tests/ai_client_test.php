<?php
require_once __DIR__ . '/../includes/ai_client.php';
$checks = 0;
function expect_ai($condition, $description) { global $checks; if (!$condition) throw new RuntimeException($description); $checks++; }

expect_ai(ai_get_text_content([['type' => 'text', 'text' => 'one'], ['type' => 'image_url'], ['text' => 'two']]) === 'onetwo', 'Multipart text');
expect_ai(ai_get_text_content(12) === null, 'Reject nontext content');
$payload = [['title' => 'Brace { in "name"', 'description' => 'Keep [this] safely \\ intact', 'savings' => '1 USD/month']];
$json = json_encode($payload);
expect_ai(ai_extract_json('```json' . "\n" . $json . "\n```") === $payload, 'Code fenced JSON');
expect_ai(ai_extract_json('Helpful preface: ' . $json . ' End.') === $payload, 'JSON amid prose');
expect_ai(ai_extract_json('[] {}') === null, 'Ambiguous JSON rejected');
expect_ai(ai_extract_json('[{"truncated":true}') === null, 'Truncated JSON rejected');
expect_ai(ai_extract_json(str_repeat('[', 130) . str_repeat(']', 130)) === null, 'Deep JSON rejected');
expect_ai(ai_extract_json(str_repeat('x', 2097153)) === null, 'Size limit');
$full = ['choices' => [['message' => ['content' => [['text' => '['], ['text' => ']']]]]]];
expect_ai(ai_decode_provider_response(json_encode(['summary' => $full])) === $full, 'Gateway summary');
$stream = 'event: message' . "\n" . 'data: ' . json_encode(['choices' => [['delta' => ['content' => '[{"title":']]]]) . "\n\n";
$stream .= 'data: ' . json_encode(['choices' => [['delta' => ['content' => '"hello"}]']]]]) . "\n\n" . 'data: [DONE]';
expect_ai(ai_decode_provider_response($stream)['choices'][0]['message']['content'] === '[{"title":"hello"}]', 'OpenAI SSE delta concatenation');
$stream = json_encode(['response' => '[']) . "\n" . json_encode(['response' => ']', 'done' => true]);
expect_ai(ai_decode_provider_response($stream)['choices'][0]['message']['content'] === '[]', 'Ollama NDJSON concatenation');
expect_ai(isset(ai_decode_provider_response('data: {"error":{"message":"secret"}}')['error']), 'Stream error propagation');
expect_ai(!str_contains(json_encode(ai_failure([], 'provider_error', 401)), 'secret'), 'Safe error');
$settings = ['id' => 1, 'user_id' => 1, 'type' => 'ollama', 'model' => 'model', 'url' => 'https://example.com', 'api_key' => '', 'enabled' => 1, 'run_schedule' => 'weekly'];
$changed = $settings; $changed['last_successful_run'] = '2026-11-03';
expect_ai(ai_settings_fingerprint($settings) === ai_settings_fingerprint($changed), 'Run timestamps do not change config fingerprint');
$changed['model'] = 'other';
expect_ai(ai_settings_fingerprint($settings) !== ai_settings_fingerprint($changed), 'Model changes fingerprint');
expect_ai(ai_validate_settings($settings + ['extra' => 1], null, [], 2)['success'] === false, 'Settings owner checked before network');
foreach (['http://user:password@example.com', 'http://example.com/#fragment', "http://example.com/\r\nheader", 'file:///etc/passwd', 'gopher://example.com'] as $url) expect_ai(ai_resolve_url($url, null, 1) === false, 'Unsafe URL rejected');
$bad = $settings; $bad['model'] = "model\nheader";
expect_ai(ai_validate_settings($bad, null, [], 1)['success'] === false, 'Model controls rejected');
$bad = $settings; $bad['api_key'] = "key\r\nAuthorization: evil";
expect_ai(ai_validate_settings($bad, null, [], 1)['success'] === false, 'Key header injection rejected');
foreach (['chatgpt', 'gemini', 'openrouter', 'ollama', 'openai-compatible'] as $provider) {
    $setting = array_replace($settings, ['type' => $provider, 'api_key' => 'fixture-secret', 'url' => 'https://gateway.example/v1']);
    $request = ai_build_completion_request($setting, 'fixture prompt');
    expect_ai(!str_contains($request['url'], 'fixture-secret'), $provider . ' secret absent from URL');
    expect_ai($provider === 'gemini' ? isset($request['payload']['contents']) : isset($request['payload']['model']), $provider . ' request shape');
    expect_ai($provider !== 'ollama' || !str_contains(implode(' ', $request['headers']), 'fixture-secret'), 'Ollama ignores keys');
}
$geminiSse = 'data: {"candidates":[{"content":{"parts":[{"text":"["}]}}]}' . "\n\n" . 'data: {"candidates":[{"content":{"parts":[{"text":"]"}]}}]}';
expect_ai(ai_decode_provider_response($geminiSse)['candidates'][0]['content']['parts'][0]['text'] === '[]', 'Gemini SSE parts concatenation');
echo "AI client: $checks checks passed\n";
