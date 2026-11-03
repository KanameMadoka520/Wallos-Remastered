<?php
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$model = $data['model'] ?? 'models';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents('/tmp/ai-fixture-started-' . preg_replace('/[^a-z_-]/', '', $model), (string) microtime(true));
header('Content-Type: application/json');
if (str_ends_with($path, '/models') || str_ends_with($path, '/api/tags')) {
    $rows = [['id' => 'fixture-ai', 'name' => 'Fixture AI']];
    if (str_contains($path, '/badmodels/')) $rows = array_merge($rows, [
        ['id' => ['nested'], 'name' => 'Invalid'], ['id' => 'bad-name', 'name' => ['nested']],
        ['id' => "bad\nmodel", 'name' => 'Invalid'], ['id' => 'bad-control-name', 'name' => "Bad\nname"],
    ]);
    echo json_encode(['data' => $rows, 'models' => [['name' => 'fixture-ai']]]); return;
}
if ($path === '/redirect-target') { file_put_contents('/tmp/ai-fixture-redirect-followed', '1'); echo '{}'; return; }
if ($model === 'http-error') { http_response_code(401); echo json_encode(['error' => ['message' => 'Reflected ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '') . ' secret']]); return; }
if ($model === 'large') { echo str_repeat('x', 2097153); return; }
if ($model === 'redirect') { header('Location: http://127.0.0.1:18191/redirect-target', true, 302); return; }
if ($model === 'slow') sleep(3);
$prompt = $data['messages'][0]['content'] ?? $data['prompt'] ?? '';
file_put_contents('/tmp/ai-fixture-last-prompt', $prompt);
$recommendations = [['title' => 'Fixture recommendation', 'description' => 'Review overlapping subscriptions before renewing.', 'savings' => '1 USD/month']];
$content = json_encode($recommendations);
if ($model === 'invalid') $content = json_encode(['title' => 'Not a list']);
if ($model === 'empty') $content = '[]';
if ($model === 'extra') $content = 'Preface: ' . $content . ' done.';
if ($model === 'sse') {
    header('Content-Type: text/event-stream');
    foreach (str_split($content, 17) as $part) echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => $part]]]]) . "\n\n";
    echo 'data: [DONE]'; return;
}
if (str_ends_with($path, '/api/generate')) { echo json_encode(['response' => $content, 'done' => true]); return; }
$response = ['choices' => [['message' => ['content' => [['text' => substr($content, 0, 10)], ['text' => substr($content, 10)]]]]]];
if ($model === 'summary') $response = ['summary' => $response];
echo json_encode($response);
