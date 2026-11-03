#!/usr/bin/env python3
"""Enables/disables 2FA ONLY in the disposable upstream fixture, then restores it."""
import base64,hashlib,hmac,http.cookiejar,json,os,struct,time,urllib.request,urllib.parse,urllib.error
base=os.environ.get('WALLOS_UPSTREAM_TEST_URL','http://127.0.0.1:18284')
with open(os.environ['WALLOS_UPSTREAM_FIXTURE_FILE']) as f:fixture=json.load(f)
with open(os.environ['WALLOS_AGENT_TEST_KEY_FILE']) as f:key=json.load(f)['token']
session=fixture['sessions']['1'];checks=0;backups=[];enabled=False

def check(ok,label):
    global checks
    assert ok,label
    checks+=1;print('[OK]',label)

def api(path,data,auth=True):
    headers={'Content-Type':'application/json'}
    if auth: headers.update(Cookie='PHPSESSID='+session['id'],**{'X-CSRF-Token':session['csrf']})
    if path.startswith('/api/'):headers['Authorization']='Bearer '+key
    try:r=urllib.request.urlopen(urllib.request.Request(base+path,data=json.dumps(data).encode(),headers=headers),timeout=20)
    except urllib.error.HTTPError as e:r=e
    assert r.status==200,'HTTP request rejected: '+path
    return json.loads(r.read())

def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def login(c):
    c.open(base+'/login.php',timeout=20).read()
    r=c.open(urllib.request.Request(base+'/login.php',data=urllib.parse.urlencode({'username':fixture['username'],'password':fixture['password']}).encode()),timeout=20)
    r.read();return r.geturl()
def totp(c,code):
    r=c.open(urllib.request.Request(base+'/totp.php',data=urllib.parse.urlencode({'one-time-code':code}).encode()),timeout=20);r.read();return r.geturl()
def code(secret):
    raw=base64.b32decode(secret+'='*((8-len(secret)%8)%8));digest=hmac.new(raw,struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest();offset=digest[-1]&15
    return str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)

identity=api('/api/agent/v1/index.php',{'tool':'wallos_context','arguments':{}})
assert identity['result']['account']['username']=='agent-ui-test' and fixture['username']=='agent-ui-test','Refusing 2FA writes outside disposable account.'
check(True,'disposable fixture identity checked before changing 2FA')
try:
    generated=api('/endpoints/user/enable_totp.php',{'action':'generate'})
    secret=generated['secret']
    current=code(secret);invalid=str((int(current)+1)%1000000).zfill(6)
    check(api('/endpoints/user/enable_totp.php',{'action':'verify','totpSecret':secret,'totpCode':invalid})['success'] is False,'wrong enrollment code leaves 2FA disabled')
    current=code(secret)
    enrolled=api('/endpoints/user/enable_totp.php',{'action':'verify','totpSecret':secret,'totpCode':current})
    check(enrolled['success'],'standard six-digit authenticator can enroll')
    enabled=True;backups=enrolled['backupCodes']
    check(len(backups)==10 and len(set(backups))==10,'enrollment returns ten distinct recovery codes')
    c=client();check('/totp.php' in login(c),'password alone now stops at the second factor')
    check('/totp.php' in totp(c,invalid),'incorrect second factor cannot establish a login')
    check('/totp.php' in totp(c,current),'enrollment code cannot be replayed as first login')
    check('/totp.php' not in totp(c,backups[0]),'one recovery code completes the login')
    r=c.open(base+'/agent.php',timeout=20);r.read();check('/agent.php' in r.geturl(),'completed second factor grants the authenticated application session')
    other=client();check('/totp.php' in login(other),'a second fresh login still requires 2FA')
    check('/totp.php' in totp(other,backups[0]),'consumed recovery code cannot be replayed')
    check('/totp.php' not in totp(other,backups[1]),'another unused recovery code remains usable')
    check(api('/endpoints/user/disable_totp.php',{'totpCode':backups[2]})['success'],'authenticated account can disable 2FA with an unused recovery code')
    enabled=False
    check('/totp.php' not in login(client()),'after disabling, normal password login works again')
    print(f'PASS: {checks} 2FA HTTP lifecycle/replay checks.')
finally:
    if enabled and len(backups)>3:
        api('/endpoints/user/disable_totp.php',{'totpCode':backups[3]})
