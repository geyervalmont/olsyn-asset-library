import sys,json
from nucleus_drive.native import NativeSession,CAPABILITIES
from nucleus_drive.backend import RemoteError
CAPABILITIES.update(mount=0,unmount=0,get_mount_info=0)
c=json.load(sys.stdin)
s=NativeSession('nucleus.olsyn.com');s.login('omniverse',c['password'])
try:
    s.stat('/Libraries/')
    raise SystemExit('Libraries exists; inspect before modifying')
except RemoteError as e:
    if e.status != 404: raise
s.mkdir('/Libraries')
s.call('set_acl_v2',path_and_acls=[{'path_at_version':{'path':'/Libraries/'},'acl':{'gm':['read','write','admin'],'users':['read']}}])
options={'service':'s3','host':'nucleus-materials.opal.svc.cluster.local:8080','bucket':'materials','region':'ap-southeast-2','secure':False,'access_key_id':c['access'],'secret_access_key':c['secret'],'redirection':''}
print(json.dumps(s.call('mount',uri='/Libraries/Materials',resolver='omniverse_resolver_s3',options=json.dumps(options))))
print(json.dumps([{'name':e['name'],'type':e['type']} for e in s.list('/Libraries/Materials')]))
