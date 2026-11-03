#!/usr/bin/env python3
"""Only run against a disposable two-account fixture; serve ai_category_provider_router.php on its :18192."""
import concurrent.futures
import html
import json
import os
import sqlite3
import time
import urllib.error
import urllib.request
import uuid

base = os.environ.get('WALLOS_UPSTREAM_TEST_URL', 'http://127.0.0.1:18284').rstrip('/')
with open(os.environ['WALLOS_UPSTREAM_FIXTURE_FILE']) as stream:
    fixture = json.load(stream)
assert fixture['username'] == 'agent-ui-test', 'Refuse non-fixture credentials'
db = sqlite3.connect(os.environ['WALLOS_FEATURE_TEST_DB'], timeout=15)
db.row_factory = sqlite3.Row
assert db.execute('SELECT username FROM user WHERE id=1').fetchone()[0] == 'agent-ui-test', 'Refuse non-fixture DB'
assert db.execute('SELECT username FROM user WHERE id=2').fetchone()[0] == 'upstream-test-2', 'Require two disposable accounts'
endpoint = '/endpoints/ai/translate_categories.php'
provider = 'http://127.0.0.1:18192/v1'
secret = 'fixture-category-key-do-not-reflect'
checks = 0


def check(ok, label):
    global checks
    assert ok, label
    checks += 1
    print('[OK]', label, flush=True)


def request(path=endpoint, data=None, uid=1, auth=True, csrf=True, method='POST'):
    headers = {'Content-Type': 'application/json'}
    if auth:
        session = fixture['sessions'][str(uid)]
        headers['Cookie'] = 'PHPSESSID=' + session['id']
        if csrf:
            headers['X-CSRF-Token'] = session['csrf']
    raw = None if method == 'GET' else json.dumps(data if data is not None else {}).encode()
    req = urllib.request.Request(base+path, data=raw, headers=headers, method=method)
    try:
        response = urllib.request.urlopen(req, timeout=25)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read().decode()
    assert secret not in body, 'Reflected credential in response'
    return response.status, json.loads(body)


def categories(uid=1):
    return [dict(row) for row in db.execute('SELECT * FROM categories WHERE user_id=? ORDER BY id', (uid,))]


def save(model='success'):
    status, body = request('/endpoints/ai/save_settings.php', {'ai_enabled': True, 'ai_type': 'openai-compatible',
        'api_key': secret, 'ollama_host': provider, 'model': model, 'ai_run_schedule': 'manual'})
    assert status == 200 and body.get('success'), body


def preview():
    status, body = request(data={'action': 'preview'})
    assert status == 200 and body.get('success'), body
    return body


original_settings = [dict(row) for row in db.execute('SELECT * FROM ai_settings WHERE user_id=1')]
original_categories = categories()
other_categories = categories(2)
original_admin = dict(db.execute('SELECT local_webhook_notifications_allowlist,allow_standard_users_local_webhooks FROM admin LIMIT 1').fetchone())
original_privacy = db.execute('SELECT screenshot_privacy_mode FROM settings WHERE user_id=1').fetchone()[0]
original_language = db.execute('SELECT language FROM user WHERE id=1').fetchone()[0]
created = []
trigger = 'fixture_category_failure_' + uuid.uuid4().hex
try:
    allowlist = [x.strip() for x in original_admin['local_webhook_notifications_allowlist'].split(',') if x.strip()]
    allowlist.append('127.0.0.1:18192')
    db.execute('UPDATE admin SET local_webhook_notifications_allowlist=?,allow_standard_users_local_webhooks=0', (','.join(allowlist),))
    db.execute('UPDATE settings SET screenshot_privacy_mode=0 WHERE user_id=1')
    db.execute("UPDATE user SET language='zh_cn' WHERE id=1")
    for name in ('CATEGORY-PRIVATE-'+uuid.uuid4().hex+' & Cloud', 'Literal <tag> & \\path'):
        db.execute('INSERT INTO categories(name,user_id) VALUES(?,1)', (html.escape(name, quote=True),))
        created.append(db.execute('SELECT last_insert_rowid()').fetchone()[0])
    db.commit()
    save()
    before = categories()
    status, body = request(data={'action': 'preview'}, auth=False)
    check(status in (400,401) and body.get('success') is False, 'translation requires authenticated browser session and JSON failure')
    status, body = request(data={'action': 'preview'}, csrf=False)
    check(status == 400 and body.get('code') == 'invalid_csrf', 'translation requires valid CSRF')
    status, body = request(method='GET')
    check(status == 405, 'translation rejects GET requests')
    result = preview()
    expected_ids = [row['id'] for row in before if row['id'] != 1]
    check([item['id'] for item in result['translations']] == expected_ids and categories() == before, 'preview returns exact owned ID set without mutating names')
    check(result['language'] == 'zh_cn' and len(result['token']) == 64, 'preview is bound to the saved target language and server token')
    status, body = request(data={'action': 'apply', 'token': result['token'], 'translations': [{'id': 200, 'name': 'injected'}]})
    check(status == 400 and categories() == before, 'caller cannot supply replacement translations in apply')
    status, body = request(data={'action': 'apply', 'token': result['token']}, uid=2)
    check(status == 409 and body.get('code') == 'preview_expired' and categories(2) == other_categories, 'preview cannot be applied by another account/session')
    status, body = request(data={'action': 'apply', 'token': '0'*64})
    check(status == 409 and categories() == before, 'forged preview token changes nothing')
    status, body = request(data={'action': 'apply', 'token': result['token']})
    check(status == 200 and body.get('success') and categories() != before, 'explicit apply persists all validated category names')
    applied = categories()
    status, body = request(data={'action': 'apply', 'token': result['token']})
    check(body.get('success') and categories() == applied, 'retry after lost response is idempotent')
    check(categories(2) == other_categories and len(applied) == len(before), 'apply leaves other accounts and category count unchanged')
    for model in ('invalid','empty','missing','duplicate','foreign','extra','long','control','http-error'):
        save(model)
        before = categories()
        status, body = request(data={'action': 'preview'})
        check(body.get('success') is False and categories() == before, model + ' AI response preserves every original category')
    save()
    for change, restore in (
        ('UPDATE categories SET name="Concurrent rename" WHERE id=?', 'UPDATE categories SET name=? WHERE id=?'),
        ('UPDATE categories SET "order"=987 WHERE id=?', 'UPDATE categories SET "order"=? WHERE id=?'),
    ):
        result = preview()
        row = dict(db.execute('SELECT * FROM categories WHERE id=?', (created[0],)).fetchone())
        db.execute(change, (created[0],)); db.commit()
        status, body = request(data={'action': 'apply', 'token': result['token']})
        check(status == 409 and body.get('code') == 'data_changed', 'concurrent category rename/order invalidates the entire preview')
        value = row['name'] if 'name=' in change else row['order']
        db.execute(restore, (value,created[0])); db.commit()
    result = preview()
    db.execute("UPDATE user SET language='en' WHERE id=1"); db.commit()
    status, body = request(data={'action': 'apply', 'token': result['token']})
    check(status == 409 and body.get('code') == 'data_changed', 'changing account language invalidates preview')
    db.execute("UPDATE user SET language='zh_cn' WHERE id=1"); db.commit()
    result = preview()
    save('literal')
    status, body = request(data={'action': 'apply', 'token': result['token']})
    check(status == 409 and body.get('code') == 'data_changed', 'changing model configuration invalidates preview')
    result = preview()
    db.execute('UPDATE settings SET screenshot_privacy_mode=1 WHERE user_id=1'); db.commit()
    for data in ({'action':'preview'},{'action':'apply','token':result['token']}):
        status, body = request(data=data)
        check(status == 403 and body.get('code') == 'screenshot_privacy_mode_blocked', 'screenshot privacy blocks raw preview and apply')
    db.execute('UPDATE settings SET screenshot_privacy_mode=0 WHERE user_id=1'); db.commit()
    result = preview()
    before = categories()
    db.execute(f'CREATE TRIGGER {trigger} BEFORE UPDATE ON categories WHEN NEW.id={created[-1]} BEGIN SELECT RAISE(ABORT,"fixture translation failure"); END'); db.commit()
    status, body = request(data={'action':'apply','token':result['token']})
    check(body.get('success') is False and categories() == before, 'HTTP write failure rolls back every earlier translated row')
    db.execute('DROP TRIGGER '+trigger); db.commit()
    result = preview()
    status, body = request(data={'action':'apply','token':result['token']})
    check(body.get('success') and all('<img' not in row['name'] for row in categories()), 'HTML-looking AI output is escaped once on storage')
    check(all(html.unescape(row['name']).startswith('<img') for row in categories() if row['id'] != 1), 'HTML-looking labels round-trip as literal text')
    save('slow')
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
        pending = executor.submit(request, endpoint, {'action':'preview'})
        time.sleep(.6)
        start=time.monotonic()
        status, body = request(data={'action':'apply','token':'0'*64})
        check(status == 409 and time.monotonic()-start < 2, 'AI provider wait does not hold the browser session lock')
        db.execute('UPDATE categories SET name="Changed while waiting" WHERE id=?',(created[0],)); db.commit()
        status, body = pending.result()
        check(status == 409 and body.get('code') == 'data_changed', 'stale in-flight completion cannot become an applicable preview')
    for topic, expected in (('ntfy-success',True),('ntfy-error',False),('ntfy-redirect',False)):
        status, body=request('/endpoints/notifications/testntfynotifications.php', {'host':'http://127.0.0.1:18192','topic':topic,'ignore_ssl':False,'headers':'Authorization: '+secret})
        check(status == 200 and body.get('success') is expected, topic+' reports actual 2xx acceptance and suppresses upstream body')
    start=time.monotonic()
    status,body=request('/endpoints/notifications/testntfynotifications.php', {'host':'http://127.0.0.1:18192','topic':'ntfy-slow','ignore_ssl':False})
    elapsed=time.monotonic()-start
    check(status == 200 and body.get('success') is False and 13 < elapsed < 23, 'slow ntfy test fails within its 15-second total timeout')
    check(categories(2) == other_categories, 'all translation failure paths preserve the foreign account')
finally:
    db.execute('DROP TRIGGER IF EXISTS '+trigger)
    for row in original_categories:
        db.execute('UPDATE categories SET name=?,"order"=? WHERE id=? AND user_id=1',(row['name'],row['order'],row['id']))
    for category_id in created:
        db.execute('DELETE FROM categories WHERE id=? AND user_id=1',(category_id,))
    db.execute('DELETE FROM ai_settings WHERE user_id=1')
    for row in original_settings:
        columns=list(row)
        db.execute('INSERT INTO ai_settings('+','.join(columns)+') VALUES('+','.join('?' for _ in columns)+')',tuple(row.values()))
    db.execute('UPDATE admin SET local_webhook_notifications_allowlist=?,allow_standard_users_local_webhooks=?',tuple(original_admin.values()))
    db.execute('UPDATE settings SET screenshot_privacy_mode=? WHERE user_id=1',(original_privacy,))
    db.execute('UPDATE user SET language=? WHERE id=1',(original_language,))
    db.commit(); db.close()
print(f'PASS: {checks} real HTTP category translation and ntfy checks.')
