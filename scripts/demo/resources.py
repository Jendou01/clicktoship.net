"""Exact local-Docker ownership receipts. Cleanup never depends on TLS or GitHub."""
import json
import os
import re
import stat
from pathlib import Path
from policy import SERVICES, run

SOCKET = 'unix:///var/run/docker.sock'

def docker_env():
    # Do not inherit DOCKER_CONTEXT/DOCKER_HOST/TLS_CERT_PATH or Compose overrides.
    return {'PATH': os.environ['PATH'], 'HOME': '/root', 'DOCKER_HOST': SOCKET}

def docker(*args, **kwargs):
    return run(['docker', '--host', SOCKET, *args], env=docker_env(), **kwargs)

def read_state(directory):
    p=directory/'instance.json'
    if p.is_symlink() or not p.is_file() or p.stat().st_uid!=0 or stat.S_IMODE(p.stat().st_mode)!=0o600:
        raise ValueError('PRIVATE_INSTANCE_RECEIPT_REQUIRED')
    s=json.loads(p.read_text())
    if (set(s)!={'instance','profile','published'} or s['instance']!=directory.name
            or s['profile']!='disposable-remote-demo' or type(s['published']) is not bool
            or not re.fullmatch('cts-demo-[a-f0-9]{12}',s['instance'])):
        raise ValueError('INSTANCE_REJECTED')
    return s

def write_private(path, data):
    temporary=path.with_suffix('.new')
    # Exclusive creation rejects a preexisting/symlink staging path.
    with temporary.open('x') as target:
        temporary.chmod(0o600)
        json.dump(data,target,sort_keys=True)
        target.flush();os.fsync(target.fileno())
    temporary.replace(path)

def initialize(directory, images):
    write_private(directory/'resources.json',{'schema':1,'instance':directory.name,
        'images':images,'resources':{},'started':False,'torn_down':False})

def receipt(directory):
    read_state(directory)
    p=directory/'resources.json'
    if p.is_symlink() or not p.is_file() or p.stat().st_uid!=0 or stat.S_IMODE(p.stat().st_mode)!=0o600:
        raise ValueError('PRIVATE_RESOURCE_RECEIPT_REQUIRED')
    r=json.loads(p.read_text())
    if (set(r)!={'schema','instance','images','resources','started','torn_down'} or r['schema']!=1
            or r['instance']!=directory.name or set(r['images'])!=SERVICES
            or type(r['started']) is not bool or type(r['torn_down']) is not bool):
        raise ValueError('RESOURCE_RECEIPT_REJECTED')
    return r

def inventory(directory, images):
    instance=directory.name
    result={}
    for kind in ['container','volume','network']:
        ids=docker(kind,'ls',*(['-a'] if kind=='container' else []),'-q','--filter',
                   'label=com.docker.compose.project='+instance).decode().split()
        for resource in ids:
            item=json.loads(docker(kind,'inspect',resource))[0]
            labels=item.get('Labels') or item.get('Config',{}).get('Labels') or {}
            if labels.get('com.docker.compose.project')!=instance:
                raise ValueError('RESOURCE_OWNERSHIP_REJECTED')
            if kind=='container':
                service=labels.get('com.docker.compose.service')
                if (service not in SERVICES or service=='migrate'
                        or item['Name']!='/'+instance+'-'+service+'-1'
                        or item['Config']['Image']!=images[service]):
                    raise ValueError('UNEXPECTED_PROJECT_CONTAINER')
                mounts=[]
                for mount in item.get('Mounts',[]):
                    if mount['Type']=='volume':
                        if mount.get('Name') not in {instance+'_'+v for v in ['database_data','redis_data','private_data']}:
                            raise ValueError('RETAINED_CONTAINER_MOUNT_REJECTED')
                    elif mount['Type']=='bind':
                        source=Path(mount['Source'])
                        if source.parent!=directory or mount.get('RW') or source.is_symlink():
                            raise ValueError('RETAINED_CONTAINER_BIND_REJECTED')
                    elif mount['Type']!='tmpfs':
                        raise ValueError('UNREVIEWED_CONTAINER_MOUNT')
                    mounts.append({k:mount.get(k) for k in ['Type','Name','Source','Destination','RW']})
                result['container:'+service]={'id':item['Id'],'name':item['Name'],
                    'image':item['Image'],'mounts':sorted(mounts,key=lambda x:x['Destination'])}
            elif kind=='volume':
                name=item['Name'];options=item.get('Options') or {}
                if (name not in {instance+'_'+v for v in ['database_data','redis_data','private_data']}
                        or item.get('Driver')!='local' or options.get('type')!='tmpfs'
                        or options.get('device')!='tmpfs' or 'nosuid' not in options.get('o','')
                        or 'nodev' not in options.get('o','')):
                    raise ValueError('RETAINED_VOLUME_TEARDOWN_REJECTED')
                result['volume:'+name]={'name':name,'created':item.get('CreatedAt'),
                    'driver':item['Driver'],'options':options}
            else:
                name=item['Name']
                if name not in {instance+'_private',instance+'_ingress'}:
                    raise ValueError('UNEXPECTED_PROJECT_NETWORK')
                if name.endswith('_private') and (not item.get('Internal') or not any(
                        x.get('Subnet')=='172.30.73.0/24' for x in item.get('IPAM',{}).get('Config',[]))):
                    raise ValueError('PRIVATE_NETWORK_REJECTED')
                result['network:'+name]={'id':item['Id'],'name':name}
    return result

def assert_fresh(directory):
    r=receipt(directory)
    if r['started'] or r['resources'] or r['torn_down'] or inventory(directory,r['images']):
        raise ValueError('FRESH_DEMO_PROJECT_REQUIRED')
    # A foreign resource with the expected name but different labels is a conflict,
    # not a volume/network that Compose may silently reuse.
    for kind,suffixes in [('volume',['database_data','redis_data','private_data']),('network',['private','ingress'])]:
        names=set(docker(kind,'ls','--format','{{.Name}}').decode().splitlines())
        if any(directory.name+'_'+suffix in names for suffix in suffixes):
            raise ValueError('RETAINED_RESOURCE_NAME_CONFLICT')

def capture(directory, replace_gateway=False):
    r=receipt(directory)
    if r['torn_down']:
        raise ValueError('NEW_INSTANCE_REQUIRED_AFTER_TEARDOWN')
    current=inventory(directory,r['images'])
    for key,previous in r['resources'].items():
        if current.get(key)!=previous and not (replace_gateway and key=='container:gateway'):
            raise ValueError('RESOURCE_IDENTITY_CHANGED')
    r.update(resources=current,started=True)
    write_private(directory/'resources.json',r)

def owned_action(directory, action):
    r=receipt(directory)
    current=inventory(directory,r['images'])
    if current!=r['resources']:
        raise ValueError('RESOURCE_RECEIPT_MISMATCH_NO_DELETION')
    containers=[value['id'] for key,value in current.items() if key.startswith('container:')]
    if containers:
        docker('stop','-t','30',*containers,timeout=180)
    if action=='teardown':
        if containers:
            docker('rm',*containers)
        # Docker refuses to remove a volume/network used by a foreign container.
        # Never force removal or prune the daemon.
        for key,value in current.items():
            if key.startswith('volume:'):
                docker('volume','rm',value['name'])
            elif key.startswith('network:'):
                docker('network','rm',value['id'])
        r.update(resources={},torn_down=True)
        write_private(directory/'resources.json',r)
