#!/usr/bin/env python3
"""Fault injection restricted to an explicitly supplied disposable integration DB."""
import json, os, sqlite3, urllib.request, urllib.error, urllib.parse, uuid, datetime
base=os.environ.get('WALLOS_UPSTREAM_TEST_URL','http://127.0.0.1:18284').rstrip('/')
with open(os.environ['WALLOS_UPSTREAM_FIXTURE_FILE']) as f: fixture=json.load(f)
with open(os.environ['WALLOS_AGENT_TEST_KEY_FILE']) as f: token=json.load(f)['token']
path=os.environ['WALLOS_FEATURE_TEST_DB']
checks=0

def request(endpoint, payload, form=False):
    session=fixture['sessions']['1']
    headers={'Cookie':'PHPSESSID='+session['id'],'X-CSRF-Token':session['csrf']}
    if endpoint.startswith('/api/agent/'): headers['Authorization']='Bearer '+token
    headers['Content-Type']='application/x-www-form-urlencoded' if form else 'application/json'
    data=(urllib.parse.urlencode(payload,doseq=True) if form else json.dumps(payload)).encode()
    try: response=urllib.request.urlopen(urllib.request.Request(base+endpoint,data=data,headers=headers),timeout=20)
    except urllib.error.HTTPError as error: response=error
    return response.status,json.loads(response.read())

def tool(name,arguments=None):
    status,body=request('/api/agent/v1/index.php',{'tool':name,'arguments':arguments or {}})
    assert status==200 and body.get('success'),(name,status,body)
    return body['result']

def check(test,label):
    global checks
    assert test,label
    checks+=1
    print('[OK]',label)

assert fixture['username']=='agent-ui-test'
assert tool('wallos_context')['account']['username']=='agent-ui-test','Refuse writes outside disposable fixture'
db=sqlite3.connect(path,timeout=15)
assert db.execute('SELECT username FROM user WHERE id=1').fetchone()==('agent-ui-test',),'Refuse non-fixture DB'
suffix=uuid.uuid4().hex
category=tool('wallos_references_save',{'kind':'categories','data':{'name':'Atomic category '+suffix},'request_id':'atomic-ref-'+suffix})['reference']['id']
today=datetime.date.today().isoformat()
name='Atomic clone '+suffix
values={'name':name,'price':3,'currency_id':100,'frequency':1,'cycle':3,'next_payment':today,'start_date':today,'payment_method_id':100,'payer_ids':[100],'category_ids':[100,category]}
source=tool('wallos_subscriptions_create',{'data':values,'request_id':'atomic-source-'+suffix})['subscription']['id']
created_name='Atomic create '+suffix
trigger='isolated_relation_failure_'+suffix
# Values below are UUID-derived local fixture labels and integer IDs.
sql=f'''CREATE TRIGGER {trigger} BEFORE INSERT ON subscription_categories
WHEN NEW.category_id={int(category)} AND EXISTS (
SELECT 1 FROM subscriptions s WHERE s.id=NEW.subscription_id
AND ((s.name='{name}' AND s.id!={int(source)}) OR s.name='{created_name}'))
BEGIN SELECT RAISE(ABORT,'isolated secondary relation failure'); END'''
try:
    db.execute(sql);db.commit()
    status,body=request('/endpoints/subscription/clone.php',{'id':source})
    check(status==400 and body.get('success') is False,'clone reports a late secondary-relation failure')
    check(db.execute('SELECT count(*) FROM subscriptions WHERE name=?',(name,)).fetchone()[0]==1,'clone failure rolls back its initial subscription row')
    values.update(name=created_name,notes='',url='',**{'logo-url':'','notify_days_before':1,'replacement_subscription_id':0})
    values['payer_ids[]']=values.pop('payer_ids');values['category_ids[]']=values.pop('category_ids')
    status,body=request('/endpoints/subscription/add.php',values,form=True)
    check(body.get('status')=='Error','browser create reports a late secondary-relation failure')
    check(db.execute('SELECT count(*) FROM subscriptions WHERE name=?',(created_name,)).fetchone()[0]==0,'browser create failure rolls back the subscription row')
    check(db.execute('SELECT count(*) FROM subscription_payment_records p WHERE NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.id=p.subscription_id)').fetchone()[0]==0,'late create failure leaves no orphan initial payment')
    check(db.execute('SELECT count(*) FROM subscription_payers r WHERE NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.id=r.subscription_id)').fetchone()[0]==0,'failed create/clone leave no orphan relation rows')
finally:
    db.execute('DROP TRIGGER IF EXISTS '+trigger);db.commit();db.close()
print(f'PASS: {checks} real HTTP relation atomicity checks.')
