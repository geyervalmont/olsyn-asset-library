import sys,json,secrets,hashlib,httpx
from nucleus_drive.native import NativeSession,CAPABILITIES
from nucleus_drive.backend import RemoteError
CAPABILITIES.update(add_user_to_group=0,remove_user_from_group=0)
c=json.load(sys.stdin);a=NativeSession('nucleus.olsyn.com');a.login('omniverse',c['password'])
name='materials-check-'+secrets.token_hex(4);password=secrets.token_urlsafe(32)
created=False
try:
 a.credentials('Profiles.add',{'version':1,'username':name,'token':a.token});created=True
 activation=a.credentials('Tokens.generate',{'version':1,'username':name,'admin_token':a.token})
 a.credentials('Credentials.reset',{'version':1,'username':name,'new_password':password,'token':activation['access_token']})
 a.call('add_user_to_group',username=name,group_name='users')
 u=NativeSession('nucleus.olsyn.com');u.login(name,password)
 names=[e['name'] for e in u.list('/Libraries/Materials')]
 assert 'by-id' in names, 'Missing stable library namespace'
 print(json.dumps({'user_listing':names}))
 p='/Libraries/Materials/'+c['key']
 with httpx.Client(timeout=30) as h:
  r=h.get(u.download_url(p))
  assert r.status_code == 200, 'Download failed'
  assert hashlib.sha256(r.content).hexdigest() == c['sha256'], 'Checksum mismatch'
  print(json.dumps({'user_download_status':r.status_code,'bytes':len(r.content),'sha256':hashlib.sha256(r.content).hexdigest()}))
 try:
  u.mkdir('/Libraries/Materials/_write_check')
  raise AssertionError('Unexpected writable library')
 except RemoteError as e:
  assert str(e) == 'Nucleus: MOUNT_EXISTS_UNDER_PATH', 'Unexpected failure instead of read-only rejection'
  print(json.dumps({'user_write_blocked':True,'status':e.status,'reason':str(e)}))
finally:
 if created:
  a.credentials('Profiles.set_enabled',{'version':1,'username':name,'token':a.token,'enabled':False})
  print(json.dumps({'test_account_disabled':name}))
