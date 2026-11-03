<?php
// Disposable fixture only. Run on 127.0.0.1:18192 with four PHP CLI workers.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (str_starts_with($path, '/ntfy-')) {
    if ($path === '/ntfy-slow') sleep(17);
    if ($path === '/ntfy-error') { http_response_code(503); echo 'Untrusted upstream body ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? ''); return; }
    if ($path === '/ntfy-redirect') { header('Location: http://127.0.0.1:18192/ntfy-success', true, 302); return; }
    http_response_code(204);
    return;
}
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$model = $data['model'] ?? 'success';
if ($model === 'http-error') { http_response_code(401); echo json_encode(['error' => ['message' => $_SERVER['HTTP_AUTHORIZATION'] ?? '']]); return; }
if ($model === 'slow') sleep(3);
$prompt = $data['messages'][0]['content'] ?? $data['prompt'] ?? '';
$position = strrpos($prompt, 'Category data:');
$categories = [];
// Remove a legacy prompt's optional leading literal newline only; never
// change escaped sequences within category names.
if ($position !== false) {
    $raw = ltrim(substr($prompt, $position + strlen('Category data:')));
    if (str_starts_with($raw, '\\n')) $raw = substr($raw, 2);
    $categories = json_decode(trim($raw), true);
}
$translated = array_map(function ($row) use ($model) {
    return ['id' => $row['id'], 'name' => $model === 'literal'
        ? '<img src=x onerror="window.aiCategoryInjected=1"> & \\cloud ' . $row['id']
        : '译名 ' . $row['name']];
}, $categories ?: []);
switch ($model) {
    case 'empty': $translated = []; break;
    case 'missing': array_pop($translated); break;
    case 'duplicate': if (count($translated)>1) $translated[1]['id']=$translated[0]['id']; break;
    case 'foreign': if ($translated) $translated[0]['id']=200; break;
    case 'extra': if ($translated) $translated[0]['action']='delete'; break;
    case 'long': if ($translated) $translated[0]['name']=str_repeat('字',101); break;
    case 'control': if ($translated) $translated[0]['name']="injected\ncontrol"; break;
}
$content = $model === 'invalid' ? 'Unstructured result' : json_encode($translated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo json_encode(['choices' => [['message' => ['content' => $content]]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
