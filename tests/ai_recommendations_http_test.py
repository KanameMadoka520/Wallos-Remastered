#!/usr/bin/env python3
"""Real HTTP AI checks. Requires the disposable upstream fixture and local mock provider.

Serve tests/fixtures/ai_provider_router.php on the fixture's 127.0.0.1:18191
(PHP_CLI_SERVER_WORKERS=4). WALLOS_TEST_CONTAINER must name that disposable
container for real weekly/monthly CLI cron checks. Never use a real AI service or production DB.
"""
import concurrent.futures
import json
import os
import sqlite3
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

base = os.environ.get('WALLOS_UPSTREAM_TEST_URL', 'http://127.0.0.1:18284').rstrip('/')
with open(os.environ['WALLOS_UPSTREAM_FIXTURE_FILE']) as stream:
    fixture = json.load(stream)
path = os.environ['WALLOS_FEATURE_TEST_DB']
container = os.environ['WALLOS_TEST_CONTAINER']
assert container and all(character.isalnum() or character in '_.-' for character in container), 'Invalid fixture container name'
identity = subprocess.run(['docker', 'exec', container, 'php', '-r', '$d=new SQLite3("/var/www/html/db/wallos.db"); echo $d->querySingle("SELECT username FROM user WHERE id=1");'], check=True, capture_output=True, text=True).stdout
assert identity == 'agent-ui-test', 'Refuse CLI execution outside the disposable fixture container'
assert fixture['username'] == 'agent-ui-test', 'Refuse non-fixture credentials'
db = sqlite3.connect(path, timeout=15)
db.row_factory = sqlite3.Row
assert db.execute('SELECT username FROM user WHERE id=1').fetchone()[0] == 'agent-ui-test', 'Refuse non-fixture DB'
assert db.execute('SELECT username FROM user WHERE id=2').fetchone()[0] == 'upstream-test-2', 'Require the disposable two-account fixture'
checks = 0
secret = 'fixture-ai-secret-never-return'
provider = 'http://127.0.0.1:18191/v1'


def check(ok, message):
    global checks
    assert ok, message
    checks += 1
    print('[OK]', message, flush=True)


def request(endpoint, payload=None, account=1, authenticated=True, csrf=True):
    headers = {'Content-Type': 'application/json'}
    if authenticated:
        session = fixture['sessions'][str(account)]
        headers['Cookie'] = 'PHPSESSID=' + session['id']
        if csrf:
            headers['X-CSRF-Token'] = session['csrf']
    data = json.dumps(payload if payload is not None else {}).encode()
    req = urllib.request.Request(base + endpoint, data=data, headers=headers)
    try:
        response = urllib.request.urlopen(req, timeout=25)
    except urllib.error.HTTPError as error:
        response = error
    raw = response.read().decode()
    assert secret not in raw, 'Provider reflection leaked an API key'
    return response.status, json.loads(raw)


def save(model='success', provider_type='openai-compatible', account=1, schedule='weekly'):
    return request('/endpoints/ai/save_settings.php', {
        'ai_enabled': True, 'ai_type': provider_type, 'api_key': secret,
        'ollama_host': provider, 'model': model, 'ai_run_schedule': schedule,
    }, account=account)


def cron(schedule):
    completed = subprocess.run(['docker', 'exec', container, 'php', '/var/www/html/endpoints/cronjobs/generaterecommendations.php', schedule], capture_output=True, text=True, timeout=30)
    assert completed.returncode == 0, 'Fixture cron process failed'
    assert secret not in completed.stdout + completed.stderr, 'Cron diagnostics leaked a key'
    return json.loads(completed.stdout)


def rows(table, uid=1):
    return [dict(row) for row in db.execute('SELECT * FROM ' + table + ' WHERE user_id=? ORDER BY id', (uid,))]


def generate():
    return request('/endpoints/ai/generate_recommendations.php')


original_settings = {uid: rows('ai_settings', uid) for uid in (1, 2)}
original_recommendations = {uid: rows('ai_recommendations', uid) for uid in (1, 2)}
original_admin = dict(db.execute('SELECT local_webhook_notifications_allowlist,allow_standard_users_local_webhooks FROM admin LIMIT 1').fetchone())
subscription_id = None
cron_subscription_id = None
trigger_name = 'fixture_ai_fail_' + uuid.uuid4().hex
try:
    # Explicit fixture-only permission. Standard accounts remain forbidden from local services.
    allowlist = [value.strip() for value in original_admin['local_webhook_notifications_allowlist'].split(',') if value.strip()]
    allowlist.append('127.0.0.1:18191')
    db.execute('UPDATE admin SET local_webhook_notifications_allowlist=?,allow_standard_users_local_webhooks=0', (','.join(allowlist),))
    # Create isolated input without changing a previously existing subscription.
    db.execute("INSERT INTO subscriptions(name,price,currency_id,cycle,frequency,payer_user_id,category_id,user_id,next_payment,start_date) VALUES('AI HTTP fixture',12,100,3,1,100,100,1,'2026-12-01','2026-01-01')")
    subscription_id = db.execute('SELECT last_insert_rowid()').fetchone()[0]
    db.execute("INSERT INTO ai_recommendations(user_id,type,title,description,savings) VALUES(2,'subscription','Account two marker','Keep this','')")
    db.commit()
    other_recommendations = rows('ai_recommendations', 2)

    status, body = request('/endpoints/ai/generate_recommendations.php', authenticated=False)
    check(status in (400, 401) and body.get('success') is False, 'recommendations require authentication/CSRF and return JSON')
    status, body = request('/endpoints/ai/generate_recommendations.php', csrf=False)
    check(status == 400 and body.get('success') is False, 'recommendations reject missing CSRF')
    status, body = save(account=2)
    check(body.get('success') is False and body.get('code') == 'invalid_host', 'ordinary account cannot use admin-only private provider allowlist')
    status, body = save()
    check(status == 200 and body.get('success'), 'validated fixture AI settings save')
    setting_before = rows('ai_settings')
    db.execute(f"CREATE TRIGGER {trigger_name} BEFORE INSERT ON ai_settings WHEN NEW.model='reject-insert' BEGIN SELECT RAISE(ABORT,'fixture insert failure'); END")
    db.commit()
    status, body = save('reject-insert')
    check(body.get('success') is False and rows('ai_settings') == setting_before, 'settings INSERT failure rolls DELETE back and preserves original key/model')
    db.execute('DROP TRIGGER ' + trigger_name)
    db.commit()
    for invalid in ({'model': []}, {'api_key': 'key\r\nInjected: value'}, {'ai_run_schedule': 'every-second'}):
        payload = {'ai_enabled': True, 'ai_type': 'openai-compatible', 'api_key': secret, 'ollama_host': provider, 'model': 'success', 'ai_run_schedule': 'weekly'}
        payload.update(invalid)
        status, body = request('/endpoints/ai/save_settings.php', payload)
        check(body.get('success') is False and rows('ai_settings') == setting_before, 'invalid setting rejected without changing saved configuration')
    status, body = request('/endpoints/ai/fetch_models.php', {'type': 'openai-compatible', 'api_key': secret, 'ollama_host': provider})
    check(body.get('success') and body['models'][0]['id'] == 'fixture-ai', 'model discovery uses shared pinned bounded transport')
    status, body = request('/endpoints/ai/fetch_models.php', {'type': 'openai-compatible', 'api_key': secret, 'ollama_host': provider + '/badmodels'})
    check(body.get('success') and len(body['models']) == 1 and body['models'][0]['id'] == 'fixture-ai', 'malformed external model IDs/names are discarded without PHP warnings')
    status, body = generate()
    check(status == 200 and body.get('success') and body['recommendations'][0]['title'] == 'Fixture recommendation', 'real HTTP multipart OpenAI-compatible completion persists valid recommendations')
    check(rows('ai_settings')[0]['last_successful_run'] is not None, 'manual successful generation updates schedule timestamp')
    check(rows('ai_recommendations', 2) == other_recommendations, 'generation never replaces another account recommendations')
    for model in ('invalid', 'empty', 'http-error', 'large', 'redirect'):
        status, body = save(model)
        assert body.get('success'), body
        before = rows('ai_recommendations')
        status, body = generate()
        check(body.get('success') is False and rows('ai_recommendations') == before, model + ' safely preserves existing recommendation rows')
        if model == 'http-error':
            check(body.get('provider_status') == 401 and 'Reflected' not in body.get('message', ''), 'HTTP provider errors expose only safe diagnostics')
        if model == 'redirect':
            check(body.get('provider_status') == 302, 'redirect is not followed')
    for model in ('summary', 'extra', 'sse'):
        status, body = save(model)
        assert body.get('success'), body
        status, body = generate()
        check(body.get('success'), model + ' completion is parsed and strictly validated')
    status, body = save('success', 'ollama')
    assert body.get('success'), body
    check(rows('ai_settings')[0]['api_key'] == '', 'Ollama does not retain an unused API key')
    status, body = generate()
    check(body.get('success'), 'Ollama native completion uses the same recommendation workflow')

    status, body = save('slow')
    assert body.get('success'), body
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
        pending = executor.submit(generate)
        time.sleep(0.6)
        started = time.monotonic()
        # A second writer can acquire SQLite and the same browser session during the network request.
        db.execute('BEGIN IMMEDIATE')
        db.execute('UPDATE subscriptions SET price=13 WHERE id=?', (subscription_id,))
        db.commit()
        status, body = save('success')
        check(body.get('success') and time.monotonic() - started < 2.0, 'provider wait holds neither the session lock nor a database write lock')
        status, body = pending.result()
        check(body.get('success') is False and body.get('code') == 'data_changed', 'stale completion cannot overwrite newer subscription/configuration data')
    check(rows('ai_recommendations', 2) == other_recommendations, 'all failure and concurrency paths preserve the other account')

    # Scheduled jobs are an explicit multi-account operation. A broken first provider must not
    # prevent a later eligible account from generating its own recommendations.
    db.execute("INSERT INTO subscriptions(name,price,currency_id,cycle,frequency,payer_user_id,category_id,user_id,next_payment,start_date) VALUES('AI cron second account',10,200,3,1,200,200,2,'2026-12-01','2026-01-01')")
    cron_subscription_id = db.execute('SELECT last_insert_rowid()').fetchone()[0]
    db.execute('UPDATE admin SET allow_standard_users_local_webhooks=1')
    db.commit()
    status, body = save('http-error')
    assert body.get('success'), body
    status, body = save('success', account=2)
    assert body.get('success'), body
    failed_recommendations = rows('ai_recommendations', 1)
    failed_timestamp = rows('ai_settings', 1)[0]['last_successful_run']
    body = cron('weekly')
    check(body.get('processed') == 2 and body.get('successes') == 1 and body['failures'][0]['user_id'] == 1, 'real CLI weekly cron continues to the next account after a provider error')
    check(rows('ai_recommendations', 2)[0]['title'] == 'Fixture recommendation' and rows('ai_settings', 2)[0]['last_successful_run'] is not None, 'CLI cron updates successful account recommendations and run timestamp')
    check(rows('ai_recommendations', 1) == failed_recommendations and rows('ai_settings', 1)[0]['last_successful_run'] == failed_timestamp, 'CLI provider failure preserves that account recommendations and run timestamp')
    status, body = save('success')
    assert body.get('success'), body
    status, body = save('success', account=2, schedule='monthly')
    assert body.get('success'), body
    weekly_rows = rows('ai_recommendations', 1)
    weekly_timestamp = rows('ai_settings', 1)[0]['last_successful_run']
    body = cron('monthly')
    check(body.get('processed') == 1 and body.get('successes') == 1 and not body.get('failures'), 'real CLI monthly cron selects only monthly accounts')
    check(rows('ai_recommendations', 1) == weekly_rows and rows('ai_settings', 1)[0]['last_successful_run'] == weekly_timestamp, 'monthly run leaves weekly account recommendation rows and timestamp untouched')
    monthly_rows = rows('ai_recommendations', 2)
    monthly_timestamp = rows('ai_settings', 2)[0]['last_successful_run']
    body = cron('weekly')
    check(body.get('processed') == 1 and body.get('successes') == 1 and not body.get('failures'), 'real CLI weekly cron selects only weekly accounts')
    check(rows('ai_settings', 1)[0]['last_successful_run'] is not None and rows('ai_recommendations', 2) == monthly_rows and rows('ai_settings', 2)[0]['last_successful_run'] == monthly_timestamp, 'weekly run updates its account and preserves the monthly account')
finally:
    db.execute('DROP TRIGGER IF EXISTS ' + trigger_name)
    for table, saved in (('ai_settings', original_settings), ('ai_recommendations', original_recommendations)):
        for uid in (1, 2):
            db.execute('DELETE FROM ' + table + ' WHERE user_id=?', (uid,))
            for row in saved[uid]:
                columns = list(row)
                db.execute('INSERT INTO ' + table + '(' + ','.join(columns) + ') VALUES(' + ','.join('?' for _ in columns) + ')', tuple(row.values()))
    for created_id in (subscription_id, cron_subscription_id):
        if created_id:
            db.execute('DELETE FROM subscriptions WHERE id=?', (created_id,))
    db.execute('UPDATE admin SET local_webhook_notifications_allowlist=?,allow_standard_users_local_webhooks=?', (original_admin['local_webhook_notifications_allowlist'], original_admin['allow_standard_users_local_webhooks']))
    db.commit()
    db.close()
print(f'PASS: {checks} real HTTP AI checks.')
