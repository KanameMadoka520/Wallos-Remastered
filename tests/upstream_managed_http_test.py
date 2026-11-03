#!/usr/bin/env python3
"""For wallos-upstream-managed-test only: SSO-only and managed SMTP/URL integration."""
import http.cookiejar, json, os, urllib.request, urllib.error, urllib.parse
from html.parser import HTMLParser
base=os.environ.get('WALLOS_MANAGED_TEST_URL','http://127.0.0.1:18285')
with open(os.environ['WALLOS_MANAGED_FIXTURE_FILE']) as f:fixture=json.load(f)
assert fixture['username']=='agent-ui-test'
class Fields(HTMLParser):
    def __init__(self): super().__init__();self.fields={}
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag in ('input','select') and 'id' in a:self.fields[a['id']]=a
checks=0

def check(ok,label):
    global checks
    assert ok,label
    checks+=1;print('[OK]',label)

def request(path,data=None,account=1,anonymous=False):
    headers={}
    if not anonymous:
        s=fixture['sessions'][str(account)];headers={'Cookie':'PHPSESSID='+s['id'],'X-CSRF-Token':s['csrf']}
    if data is not None: headers['Content-Type']='application/json';data=json.dumps(data).encode()
    try:r=urllib.request.urlopen(urllib.request.Request(base+path,data=data,headers=headers),timeout=20)
    except urllib.error.HTTPError as e:r=e
    return r.status,r.read().decode(),r.geturl()

status,html,_=request('/admin.php');check(status==200 and 'smtpaddress' in html,'isolated admin session is valid')
parser=Fields();parser.feed(html)
for field in ('smtpaddress','smtpport','encryptionnone','encryptiontls','encryptionssl','smtpusername','smtppassword','fromemail','serverurl'):
    # Public URL uses the pre-existing camel-case DOM id.
    if field=='serverurl':field=next(k for k in parser.fields if k.lower()=='serverurl')
    check('disabled' in parser.fields[field],field+' is controlled by the deployment')
check(parser.fields['smtppassword'].get('value')=='','managed SMTP password is never inserted into HTML')
check('fixture-managed-secret-not-production' not in html,'rendered admin document contains no deployment SMTP password')
status,body,_=request('/endpoints/admin/savesmtpsettings.php',{'smtpaddress':'127.0.0.1','smtpport':'25','encryption':'none','smtpusername':'attacker','smtppassword':'attacker','fromemail':'attacker@example.invalid'})
check(status==200 and json.loads(body)['success'],'managed SMTP ignores forged replacements and validates effective settings')
status,body,_=request('/endpoints/admin/saveopenregistrations.php',{'open_registrations':0,'max_users':20,'require_email_validation':0,'server_url':'https://attacker.example.invalid','disable_login':0,'invite_only_registration':0})
check(status==200 and json.loads(body)['success'],'managed URL survives a registration-policy save')
status,html,_=request('/admin.php');p=Fields();p.feed(html)
check(p.fields['smtpaddress'].get('value')=='smtp.gmail.com','forged SMTP save did not change managed host')
check(any(a.get('value')=='https://wallos.example.invalid' for a in p.fields.values()),'forged registration save did not change managed public URL')
status,body,_=request('/endpoints/notifications/testemailnotifications.php',{'instance_smtp':True,'smtpaddress':'127.0.0.1','smtpport':25},account=2)
check(status==403,'ordinary account is denied access to instance SMTP testing')
status,body,_=request('/endpoints/notifications/testemailnotifications.php',{},account=2)
check(status==200 and json.loads(body)['success'] is False,'ordinary empty SMTP form never triggers a secret-backed send')
jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
r=client.open(base+'/login.php',timeout=20);loginhtml=r.read().decode()
check('id="username"' not in loginhtml and 'id.example.invalid/authorize' in loginhtml,'SSO-only login page hides local password fields')
r=client.open(urllib.request.Request(base+'/login.php',data=urllib.parse.urlencode({'username':fixture['username'],'password':fixture['password']}).encode()),timeout=20);r.read()
r=client.open(base+'/agent.php',timeout=20);r.read()
check('/login.php' in r.geturl(),'direct correct-password POST cannot bypass SSO-only server policy')
print(f'PASS: {checks} managed deployment/SSO HTTP checks.')
