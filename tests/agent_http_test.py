#!/usr/bin/env python3
"""Run ONLY against the disposable fixture from agent_api_test.php --init."""
import json, os, sys, urllib.request, urllib.error, uuid
base = os.environ.get('WALLOS_AGENT_TEST_URL', 'http://127.0.0.1:18283')
with open(os.environ.get('WALLOS_AGENT_TEST_KEY_FILE', '/tmp/wallos-agent-test-db/test-key.json')) as f:
    key = json.load(f)['token']
checks = 0

def request(path, body=None, auth=True, headers=None, method=None, raw=False):
    h = {'Content-Type':'application/json', 'Accept':'application/json, text/event-stream'}
    if auth: h['Authorization'] = 'Bearer ' + key
    h.update(headers or {})
    data = body.encode() if raw else json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(base + path, data=data, headers=h, method=method)
    try:
        response = urllib.request.urlopen(req)
    except urllib.error.HTTPError as e: response = e
    text = response.read().decode()
    return response.status, json.loads(text) if text else None, response.headers

def check(ok, name):
    global checks
    if not ok: raise AssertionError(name)
    checks += 1
    print('[OK]',name)

mcp = '/api/agent/mcp.php'
rest = '/api/agent/v1/index.php'
status, identity, _ = request(rest, {'tool':'wallos_context','arguments':{}})
if status != 200 or identity['result']['account']['username'] not in ('agent-test-1', 'agent-ui-test'):
    raise RuntimeError('Refusing mutations outside the disposable agent fixture account.')
status, body, h = request(mcp, {'jsonrpc':'2.0','id':1,'method':'initialize','params':{'protocolVersion':'2025-11-25','capabilities':{},'clientInfo':{'name':'fixture','version':'1'}}})
check(status == 200 and body['result']['protocolVersion']=='2025-11-25','MCP initialize negotiates supported version')
check('no-store' in h['Cache-Control'],'agent responses are not cacheable')
check(request(mcp, {'jsonrpc':'2.0','method':'notifications/initialized'})[0] == 202,'initialized notification returns empty 202')
status, body, h = request(mcp, {'jsonrpc':'2.0','id':'catalog','method':'tools/list'})
check(status == 200 and len(body['result']['tools']) == 18,'MCP tools catalog exposes schemas')
check(all(t['inputSchema']['type']=='object' and isinstance(t['inputSchema']['properties'],dict) for t in body['result']['tools']),'schemas always encode object properties')
status, body, h = request(mcp, {'jsonrpc':'2.0','id':3,'method':'tools/call','params':{'name':'wallos_context','arguments':{}}})
check(status == 200 and body['result']['structuredContent']['account']['id']==1,'MCP call returns structured account-scoped result')
check(request(mcp, auth=False)[0]==401,'no key cannot access endpoint')
check(request(mcp, headers={'Cookie':'PHPSESSID=test'},auth=False)[0]==401,'browser cookie cannot authorize agent access')
check(request(mcp, headers={'Authorization':'Bearer legacy-key'})[0]==401,'legacy API key rejected over HTTP')
check(request(mcp+'?api_key=forbidden')[0]==400,'query credentials rejected')
check(request(mcp, {'jsonrpc':'2.0','id':4,'method':'ping'}, headers={'Origin':'https://evil.invalid'})[0]==403,'untrusted browser origin rejected')
check(request(mcp)[0]==405,'stateless MCP GET explicitly returns 405')
check(request(mcp,method='DELETE')[0]==405,'unsupported DELETE returns 405')
check(request(mcp, {'jsonrpc':'2.0','id':4,'method':'ping'},headers={'MCP-Protocol-Version':'1900-01-01'})[0]==400,'unsupported protocol header rejected')
check(request(mcp,'{broken',raw=True)[1]['error']['code']==-32700,'malformed JSON uses JSON-RPC parse error')
check(request(mcp,[])[0]==400,'JSON-RPC batches rejected')
check(request(mcp,{'jsonrpc':'2.0','id':5,'method':'unknown'})[1]['error']['code']==-32601,'unknown method uses standard error')
check(request(mcp,{'jsonrpc':'2.0','id':5,'method':'tools/call','params':{'name':'wallos_subscriptions_get','arguments':{'id':999999}}})[1]['result']['isError'],'tool failures reported with isError')
check(request(mcp,{'jsonrpc':'2.0','method':'tools/call','params':{'name':'wallos_context'}})[0]==400,'tool call notification never executes')
check(request(mcp,{'jsonrpc':'2.0','id':5,'method':'ping'},headers={'Content-Type':'text/plain'})[0]==415,'wrong content type rejected')
check(request(mcp, ' '*262145, raw=True)[0]==413,'oversized body rejected')
check(request(rest)[1]['api_version']=='1','HTTP tool API discovery available')

def tool(name, args):
    return request(rest,{'tool':name,'arguments':args})

op = str(uuid.uuid4())
data = {'name':'HTTP fixture subscription','price':10,'currency_id':100,'start_date':'2026-11-01','next_payment':'2026-11-01','cycle':3,'frequency':1,'category_id':100,'payment_method_id':100,'payer_user_id':100}
status, body, _ = tool('wallos_subscriptions_create',{'data':data,'request_id':op})
check(status==200 and body['success'],'HTTP subscription create')
s = body['result']['subscription']
check(tool('wallos_subscriptions_create',{'data':data,'request_id':op})[1]['result']['replayed'],'HTTP write retry is idempotent')
status, body, _ = tool('wallos_subscriptions_update',{'id':s['id'],'expected_version':s['version'],'changes':{'price':11},'request_id':str(uuid.uuid4())})
check(status==200 and body['result']['subscription']['price']==11,'HTTP sparse update succeeds')
check(tool('wallos_subscriptions_update',{'id':s['id'],'expected_version':s['version'],'changes':{'price':12},'request_id':str(uuid.uuid4())})[0]==409,'HTTP stale update returns 409')

# Multiple PHP-FPM workers must serialize writes under the same SQLite lock.
from concurrent.futures import ThreadPoolExecutor
s = tool('wallos_subscriptions_get', {'id':s['id']})[1]['result']['subscription']
concurrent = {'id':s['id'],'expected_version':s['version'],'changes':{'price':13},'request_id':str(uuid.uuid4())}
with ThreadPoolExecutor(max_workers=2) as pool:
    outcomes = list(pool.map(lambda _: tool('wallos_subscriptions_update', concurrent), range(2)))
check(all(x[0]==200 for x in outcomes) and sum(bool(x[1]['result'].get('replayed')) for x in outcomes)==1, 'concurrent identical requests commit once and replay once')
s = tool('wallos_subscriptions_get', {'id':s['id']})[1]['result']['subscription']
race_args = [{'id':s['id'],'expected_version':s['version'],'changes':{'price':p},'request_id':str(uuid.uuid4())} for p in [14,15]]
with ThreadPoolExecutor(max_workers=2) as pool:
    outcomes = list(pool.map(lambda a: tool('wallos_subscriptions_update',a), race_args))
check(sorted(x[0] for x in outcomes)==[200,409], 'concurrent distinct writes cannot overwrite one another')
print(f'PASS: {checks} HTTP/MCP checks.')
