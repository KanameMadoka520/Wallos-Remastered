#!/usr/bin/env python3
"""Mutates ONLY the disposable two-account upstream_integration_fixture.php database."""
import datetime, http.cookiejar, json, os, urllib.error, urllib.parse, urllib.request, uuid
base = os.environ.get('WALLOS_UPSTREAM_TEST_URL', 'http://127.0.0.1:18284').rstrip('/')
with open(os.environ['WALLOS_UPSTREAM_FIXTURE_FILE']) as f: fixture = json.load(f)
with open(os.environ['WALLOS_AGENT_TEST_KEY_FILE']) as f: token = json.load(f)['token']
checks = 0

def request(path, data=None, account=1, form=False, authenticated=True, method=None):
    headers = {}
    if authenticated:
        session = fixture['sessions'][str(account)]
        headers.update(Cookie='PHPSESSID=' + session['id'], **{'X-CSRF-Token': session['csrf']})
    if path.startswith('/api/'): headers['Authorization'] = 'Bearer ' + token
    if data is not None:
        headers['Content-Type'] = 'application/x-www-form-urlencoded' if form else 'application/json'
        data = (urllib.parse.urlencode(data) if form else json.dumps(data)).encode()
    req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    try: r = urllib.request.urlopen(req, timeout=20)
    except urllib.error.HTTPError as e: r = e
    body = r.read().decode()
    return r.status, body, r.geturl()

def check(ok, message):
    global checks
    assert ok, message
    checks += 1
    print('[OK]', message)

def tool(name, arguments=None):
    status, body, _ = request('/api/agent/v1/index.php', {'tool':name,'arguments':arguments or {}})
    assert status == 200, (name, status)
    parsed = json.loads(body)
    assert parsed.get('success'), (name, parsed)
    return parsed['result']

identity = tool('wallos_context')
assert identity['account']['username'] == 'agent-ui-test' and fixture['username'] == 'agent-ui-test', 'Refusing writes outside the disposable fixture.'
check(True, 'disposable account verified before mutations')
today = datetime.date.today().isoformat()
values = {'name':'Upstream HTTP fixture '+uuid.uuid4().hex,'price':'19.91','currency_id':'100', 'frequency':'1','cycle':'3','next_payment':today,'start_date':today,'payment_method_id':'100','payer_user_id':'100','category_id':'100','notes':'','url':'','logo-url':'','notify_days_before':'1','replacement_subscription_id':'0'}
endpoint = '/endpoints/subscription/add.php'
count_before = len(tool('wallos_subscriptions_list', {'limit':100})['subscriptions'])
for field in ('currency_id','category_id','payer_user_id','payment_method_id'):
    for invalid in ('200','-1','1 OR 1=1'):
        status, body, _ = request(endpoint, dict(values, **{field:invalid}), form=True)
        check(status == 400 and json.loads(body)['status'] == 'Error', field+' rejects foreign/invalid reference '+invalid)
foreign_rule = [{'rule_type':'first_n_cycles','price':1,'currency_id':200,'max_cycles':1}]
status, body, _ = request(endpoint, dict(values, subscription_price_rules_json=json.dumps(foreign_rule)), form=True)
check(status == 400, 'price rule rejects a currency belonging to another account')
check(len(tool('wallos_subscriptions_list', {'limit':100})['subscriptions']) == count_before, 'rejected writes leave the subscription count unchanged')
status, body, _ = request(endpoint, values, form=True)
check(status == 200 and json.loads(body)['status'] == 'Success', 'legacy browser form creates with valid owner references')
created = next(s for s in tool('wallos_subscriptions_list', {'limit':100})['subscriptions'] if s['name'] == values['name'])
status, body, _ = request(endpoint, dict(values, id=str(created['id']),price='21.92'), form=True)
check(status == 200 and json.loads(body)['status'] == 'Success', 'legacy browser form can edit an existing subscription')
status, body, _ = request(endpoint, dict(values, id=str(created['id']),inactive='1',replacement_subscription_id=str(created['id'])), form=True)
check(status == 400, 'an inactive subscription cannot replace itself')
status, body, _ = request('/endpoints/user/save_user.php', {'main_currency':'200','firstname':'Fixture','lastname':'','email':'fixture@example.invalid','avatar':'images/avatar.png','language':'en','user_timezone':'Asia/Shanghai'}, form=True)
check(status == 400, 'profile cannot adopt another account currency')
status, body, _ = request('/endpoints/notifications/testemailnotifications.php', {'instance_smtp':True,'smtpaddress':'127.0.0.1','smtpport':'25'}, account=2)
check(status == 403, 'ordinary user cannot request instance SMTP credentials through mail testing')
status, body, _ = request('/endpoints/notifications/testemailnotifications.php', {}, account=2)
check(status == 200 and json.loads(body)['success'] is False, 'personal SMTP test does not fall back to instance credentials')
status, body, _ = request('/endpoints/currency/fixer_api_key.php', {'provider':'2'}, form=True)
check(status == 200 and json.loads(body)['success'], 'Frankfurter can be selected without an API key')
status, body, _ = request('/settings.php')
check(status == 200 and 'value="2" selected' in body and 'frankfurter.dev' in body, 'settings render the selected keyless provider')
status, body, _ = request('/calendar.php')
check(status == 200 and 'calendar-subscription-price' in body and 'calendar-subscription-renewal-type' in body, 'calendar renders price and renewal mode alongside custom countdowns')
print(f'PASS: {checks} upstream HTTP security/compatibility checks.')
