#!/usr/bin/env python3
"""Manual Linux demo lifecycle. Never changes firewall, DNS or retained projects."""
import argparse
import base64
import json
import os
import re
import secrets
import shutil
import sys
import urllib.request
import http.client
import socket
import ssl
import tarfile
import hashlib
from pathlib import PurePosixPath
from pathlib import Path
from policy import ACK, HOST, FILES, digest, environment, private, run, validate_compose, validate_env, verify_review
from resources import SOCKET, docker, docker_env, read_state, initialize, assert_fresh, capture, owned_action, receipt, inventory, write_private

ROOT = Path(__file__).resolve().parents[2]
COMPOSE = ROOT/'infrastructure/demo/compose.yml'
FIXED = {
    'postgres':'postgres:17-bookworm@sha256:858bb5e98eed02eb8cafbcdc007f9a65d1b613972028944a8ec2133ccaf80d27',
    'redis':'redis:7-bookworm@sha256:44960b3100c48159a66d28c18b0588c193492e25f7551d60e51dd464fc3eca5b',
    'mailpit':'axllent/mailpit@sha256:0790692be935ea4420d085694f7985b1f692ca6a8771ab0e0bd8ba75ac180498',
}

def protected_ram(path, operational=True):
    if sys.platform!='linux' or os.geteuid()!=0:
        raise ValueError('ROOT_LINUX_REQUIRED')
    resolved=path.resolve()
    if path.is_symlink() or not re.fullmatch('cts-demo-[a-f0-9]{12}',resolved.name) or resolved.parent!=Path('/run'):
        raise ValueError('NEW_DEDICATED_RUN_DIRECTORY_REQUIRED')
    if operational and Path('/proc/swaps').read_text().strip().count('\n'):
        raise ValueError('NO_ACTIVE_SWAP_REQUIRED')
    if operational and run(['findmnt','-n','-o','FSTYPE','-T','/run']).strip()!=b'tmpfs':
        raise ValueError('PROTECTED_RAM_REQUIRED')

def verify_target():
    if socket.gethostname().lower().rstrip('.') not in {'srv2104412','srv2104412.hstgr.cloud'}:
        raise ValueError('EXACT_AUTHORIZED_VPS_REQUIRED')
    addresses=json.loads(run(['ip','-j','-4','address','show']))
    if not any(info.get('local')=='2.24.48.221' for device in addresses for info in device.get('addr_info',[])):
        raise ValueError('EXACT_AUTHORIZED_VPS_IP_REQUIRED')

def tls(directory, ca_file=None):
    crt=directory/'tls.crt';key=directory/'tls.key'
    run(['openssl','x509','-in',str(crt),'-noout','-checkhost',HOST])
    run(['openssl','x509','-in',str(crt),'-noout','-checkend',str(21*86400)])
    # fullchain must verify to the host's normal CA roots; no insecure flags.
    run(['openssl','verify',*(['-CAfile',str(ca_file)] if ca_file else []),'-purpose','sslserver','-verify_hostname',HOST,'-untrusted',str(crt),str(crt)])
    certpub=run(['openssl','x509','-in',str(crt),'-pubkey','-noout'])
    keypub=run(['openssl','pkey','-in',str(key),'-pubout'])
    if certpub!=keypub:
        raise ValueError('TLS_KEY_PAIR_MISMATCH')

def approved(a):
    m=verify_review(a.manifest,a.signature,a.review_key,a.review_key_sha256)
    if run(['git','--no-optional-locks','-C',str(ROOT),'rev-parse','HEAD']).decode().strip()!=m['git_sha'] or run(['git','--no-optional-locks','-C',str(ROOT),'status','--porcelain']).strip():
        raise ValueError('EXACT_CLEAN_REVIEWED_CHECKOUT_REQUIRED')
    # Query GitHub over verified HTTPS rather than accepting a handwritten CI URL.
    url=f'https://api.github.com/repos/Jendou01/clicktoship.net/actions/runs/{m["ci_run_id"]}'
    req=urllib.request.Request(url,headers={'Accept':'application/vnd.github+json','User-Agent':'ClickToShip-demo-review'})
    with urllib.request.urlopen(req,timeout=20) as response:
        if response.geturl()!=url:
            raise ValueError('CI_REDIRECT_REJECTED')
        ci=json.load(response)
    if ci.get('head_sha')!=m['git_sha'] or ci.get('head_branch')!='main' or ci.get('event')!='push' or ci.get('name')!='Foundation CI' or ci.get('status')!='completed' or ci.get('conclusion')!='success' or ci.get('repository',{}).get('full_name')!='Jendou01/clicktoship.net':
        raise ValueError('SUCCESSFUL_EXACT_HOSTED_CI_REQUIRED')
    return m

def image_identity(m):
    for service,image in m['images'].items():
        info=json.loads(docker('image','inspect',image))[0]
        labels=info.get('Config',{}).get('Labels') or {}
        if info['Id']!=image or labels.get('org.opencontainers.image.revision')!=m['git_sha']:
            raise ValueError('IMAGE_IDENTITY_MISMATCH')
        if service=='web' and labels.get('org.clicktoship.profile')!='disposable-remote-demo':
            raise ValueError('DEMO_WEB_BUILD_REQUIRED')

def context(directory,m,public=None):
    state=read_state(directory)
    instance=state['instance']
    if public is None:
        public=state['published']
    # No inherited Compose/DB/Redis/proxy or remote Docker variables.
    env={**docker_env(),
         'CTS_PROJECT':instance,'CTS_SECRET_DIR':str(directory),'CTS_PROXY_IP':'172.30.73.10',
         'CTS_API_IMAGE':m['images']['api'],'CTS_WEB_IMAGE':m['images']['web'],'CTS_GATEWAY_IMAGE':m['images']['gateway']}
    command=['docker','--host',SOCKET,'compose','--env-file','/dev/null','--profile','maintenance','-f',str(COMPOSE),'-p',instance]
    if public:
        command+=['-f',str(ROOT/'infrastructure/demo/publish.yml')]
    def dc(*args,data=None):
        try:
            return run(command+list(args),env=env,input=data)
        except ValueError as error:
            if args and args[0]=='up':error.operation='compose_up'
            raise
    rendered=json.loads(dc('config','--format','json'))
    if not validate_compose(rendered,instance,{**m['images'],**FIXED},public,directory):
        raise ValueError('COMPOSE_BOUNDARY_REJECTED')
    return state,dc,rendered

def gateway_probe(directory, ca_file=None):
    class LocalTLS(http.client.HTTPSConnection):
        def connect(self):
            self.sock=ssl.create_default_context(cafile=str(ca_file) if ca_file else None).wrap_socket(socket.create_connection(('127.0.0.1',18443),timeout=15),server_hostname=HOST)
    credential=json.loads((directory/'reviewer-access.json').read_text())
    authorization='Basic '+base64.b64encode((credential['username']+':'+credential['password']).encode()).decode()
    for path,auth,expected in [('/en',False,401),('/api/v1/health/ready',False,401),('/api/v1/health/ready',True,200),('/en',True,200),('/ar',True,200),('/.env',True,404),('/horizon',True,404)]:
        connection=LocalTLS(HOST,timeout=15)
        try:
            connection.request('GET',path,headers={'Host':HOST,**({'Authorization':authorization} if auth else {})})
            response=connection.getresponse()
            if response.status!=expected:
                raise ValueError('PROTECTED_GATEWAY_ACCEPTANCE_REQUIRED')
            response.read()
        finally:
            connection.close()

def check(directory,m,ca_file=None):
    if not private(directory,True) or not all((directory/f).is_file() and not (directory/f).is_symlink() and not ((directory/f).stat().st_mode & 0o027) for f in FILES):
        raise ValueError('PRIVATE_SECRETS_REQUIRED')
    for f in FILES:
        info=(directory/f).stat()
        expected=999 if f=='redis.conf' else 10001
        if info.st_uid!=expected or info.st_gid!=expected:
            raise ValueError('SECRET_SERVICE_OWNERSHIP_REQUIRED')
    e=environment(directory/'application.env')
    if not validate_env(e,directory.name):
        raise ValueError('EXACT_DEMO_ENV_REQUIRED')
    migration=environment(directory/'migration.env')
    owner=(directory/'database-owner-password').read_text()
    if migration!={**e,'DB_USERNAME':'cts_owner','DB_PASSWORD':owner} or not re.fullmatch('[a-f0-9]{64}',owner) or owner in {e['DB_PASSWORD'],e['REDIS_PASSWORD']}:
        raise ValueError('INDEPENDENT_MIGRATOR_REQUIRED')
    expected=f'bind 0.0.0.0\nprotected-mode yes\nrequirepass {e["REDIS_PASSWORD"]}\nappendonly yes\ndir /data\nmaxmemory 256mb\nmaxmemory-policy noeviction\n'
    if (directory/'redis.conf').read_text()!=expected or (directory/'host.conf').read_text()!=f'if ($host != {HOST}) {{ return 444; }}\n':
        raise ValueError('REDIS_OR_HOST_POLICY_REJECTED')
    hashes=(directory/'gateway.htpasswd').read_text().splitlines()
    if not hashes or any(not re.fullmatch(r'[a-z][a-z0-9_-]{2,40}:\$6\$[./A-Za-z0-9]{1,16}\$[./A-Za-z0-9]{86}',line) for line in hashes):
        raise ValueError('GATEWAY_AUTHENTICATION_REQUIRED')
    tls(directory,ca_file);image_identity(m)
    return context(directory,m)

def prepare(directory,m,certificate,tls_key,ca_file=None):
    image_identity(m)
    os.umask(0o077);directory.mkdir(mode=0o700)
    template=(ROOT/'infrastructure/demo/application.env.example').read_text().replace('=INSTANCE','='+directory.name)
    password=secrets.token_hex(32);owner=secrets.token_hex(32);redis=secrets.token_hex(32)
    app=template.replace('APP_KEY=','APP_KEY=base64:'+base64.b64encode(secrets.token_bytes(32)).decode()).replace('DB_PASSWORD=','DB_PASSWORD='+password).replace('REDIS_PASSWORD=','REDIS_PASSWORD='+redis)
    (directory/'application.env').write_text(app)
    (directory/'migration.env').write_text(app.replace('DB_USERNAME=cts_runtime','DB_USERNAME=cts_owner').replace('DB_PASSWORD='+password,'DB_PASSWORD='+owner))
    (directory/'database-owner-password').write_text(owner)
    (directory/'redis.conf').write_text(f'bind 0.0.0.0\nprotected-mode yes\nrequirepass {redis}\nappendonly yes\ndir /data\nmaxmemory 256mb\nmaxmemory-policy noeviction\n')
    (directory/'host.conf').write_text(f'if ($host != {HOST}) {{ return 444; }}\n')
    gate=secrets.token_urlsafe(36)
    hashed=run(['openssl','passwd','-6','-stdin'],input=gate.encode()).decode().strip()
    (directory/'gateway.htpasswd').write_text('reviewer:'+hashed+'\n')
    (directory/'reviewer-access.json').write_text(json.dumps({'username':'reviewer','password':gate}))
    for source,name in [(certificate,'tls.crt'),(tls_key,'tls.key')]:
        if not source.is_file() or source.is_symlink():
            raise ValueError('EXPLICIT_TLS_FILE_REQUIRED')
        shutil.copyfile(source,directory/name)
    (directory/'instance.json').write_text(json.dumps({'instance':directory.name,'profile':'disposable-remote-demo','published':False}))
    for f in FILES:
        (directory/f).chmod(0o440);os.chown(directory/f,999 if f=='redis.conf' else 10001,999 if f=='redis.conf' else 10001)
    _,_,rendered=check(directory,m,ca_file)
    initialize(directory,{name:service['image'] for name,service in rendered['services'].items()})

def verify_archive(path,m):
    if not path or path.is_symlink() or not path.is_file() or digest(path)!=m['archive_sha256']:
        raise ValueError('APPROVED_ARCHIVE_REQUIRED')
    with tarfile.open(path,'r:*') as archive:
        members={}
        for entry in archive:
            name=PurePosixPath(entry.name)
            if name.is_absolute() or '..' in name.parts or not (entry.isfile() or entry.isdir()) or entry.name in members:
                raise ValueError('UNSAFE_IMAGE_ARCHIVE')
            members[entry.name]=entry
        manifest=members.get('manifest.json')
        if not manifest or manifest.size>65536:
            raise ValueError('DOCKER_ARCHIVE_MANIFEST_REQUIRED')
        entries=json.load(archive.extractfile(manifest))
        if not isinstance(entries,list) or len(entries)!=3:
            raise ValueError('EXACT_THREE_ARCHIVE_IMAGES_REQUIRED')
        seen=set()
        for entry in entries:
            config=members.get(entry.get('Config'))
            if not config or config.size>4*1024*1024:
                raise ValueError('IMAGE_CONFIG_REQUIRED')
            image='sha256:'+hashlib.sha256(archive.extractfile(config).read()).hexdigest()
            service=next((name for name,value in m['images'].items() if value==image),None)
            if not service or service in seen or entry.get('RepoTags')!=[f'clicktoship-demo-{service}:{m["git_sha"]}']:
                raise ValueError('UNREVIEWED_ARCHIVE_IMAGE_OR_TAG')
            if not entry.get('Layers') or any(layer not in members for layer in entry['Layers']):
                raise ValueError('IMAGE_LAYERS_REQUIRED')
            seen.add(service)

def start_instance(directory,m,ca_file=None):
    state,dc,_=check(directory,m,ca_file)
    if state['published']:
        raise ValueError('FRESH_PRIVATE_START_REQUIRED')
    assert_fresh(directory)
    for network_id in docker('network','ls','-q').decode().split():
        network=json.loads(docker('network','inspect',network_id))[0]
        for cfg in network.get('IPAM',{}).get('Config') or []:
            subnet=cfg.get('Subnet')
            if subnet and ':' not in subnet and __import__('ipaddress').ip_network(subnet).overlaps(__import__('ipaddress').ip_network('172.30.73.0/24')):
                raise ValueError('DEMO_SUBNET_CONFLICT')
    try:
        dc('up','-d','--wait','postgres','redis','mailpit');capture(directory)
        e=environment(directory/'application.env')
        sql="CREATE ROLE cts_runtime LOGIN PASSWORD '"+e['DB_PASSWORD']+"' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;\nCREATE ROLE cts_finance_runtime NOLOGIN;\n"
        dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_demo','-v','ON_ERROR_STOP=1',data=sql.encode())
        dc('run','--rm','--no-deps','-T','migrate')
        dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_demo','-v','ON_ERROR_STOP=1',data=(ROOT/'infrastructure/deployment/runtime-grants.sql').read_bytes())
        for command in ['schema:verify','security:check-config','demo:check']:
            dc('run','--rm','--no-deps','-T','api','php','artisan',command)
        dc('up','-d','--wait','api','web','critical','background','scheduler');capture(directory)
        dc('up','-d','--wait','gateway');capture(directory)
        gateway_probe(directory,ca_file)
    except Exception:
        # Capture only canonical owned resources. An unknown resource aborts
        # cleanup rather than broadening deletion to matching labels.
        capture(directory)
        owned_action(directory,'stop')
        raise

def refresh_gateway(directory,m,ca_file=None,public=None):
    state,dc,_=check(directory,m,ca_file)
    r=receipt(directory)
    if inventory(directory,r['images'])!=r['resources']:
        raise ValueError('RESOURCE_RECEIPT_MISMATCH_NO_RECREATE')
    if public is not None:
        _,dc,_=context(directory,m,public)
    try:
        dc('up','-d','--no-deps','--force-recreate','--wait','gateway')
        capture(directory,replace_gateway=True)
        if public is not None:
            write_private(directory/'instance.json',{**state,'published':public})
    except Exception:
        capture(directory,replace_gateway=True)
        owned_action(directory,'stop')
        raise

def main():
    p=argparse.ArgumentParser()
    p.add_argument('action',choices=['prepare','verify','start','publish','refresh-gateway','stop','teardown','load'])
    p.add_argument('--directory',required=True,type=Path)
    p.add_argument('--manifest',type=Path);p.add_argument('--signature',type=Path)
    p.add_argument('--review-key',type=Path);p.add_argument('--review-key-sha256')
    p.add_argument('--archive',type=Path);p.add_argument('--certificate',type=Path);p.add_argument('--tls-key',type=Path)
    p.add_argument('--ack',required=True);p.add_argument('--approve-public-gateway',action='store_true')
    a=p.parse_args()
    if a.ack!=ACK:
        raise ValueError('EXPLICIT_DATA_LOSS_ACK_REQUIRED')
    directory=a.directory;protected_ram(directory,operational=a.action not in {'stop','teardown'});verify_target()
    if a.action in {'stop','teardown'}:
        # Emergency closure is independent of expired TLS, missing secrets,
        # unavailable images, a dirty source checkout and GitHub outages.
        if not private(directory,True):
            raise ValueError('PRIVATE_INSTANCE_DIRECTORY_REQUIRED')
        owned_action(directory,a.action)
    else:
        if not all([a.manifest,a.signature,a.review_key,a.review_key_sha256]):
            raise ValueError('SIGNED_RELEASE_APPROVAL_REQUIRED')
        m=approved(a)
        if a.action=='load':
            verify_archive(a.archive,m)
            docker('load','-i',str(a.archive),timeout=600);image_identity(m)
        elif a.action=='prepare':
            if directory.exists() or not a.certificate or not a.tls_key:
                raise ValueError('FRESH_INSTANCE_AND_TLS_REQUIRED')
            prepare(directory,m,a.certificate,a.tls_key)
        elif a.action=='start':
            start_instance(directory,m)
        elif a.action=='publish':
            state,dc,_=check(directory,m)
            if not a.approve_public_gateway or state['published']:
                raise ValueError('SEPARATE_PUBLIC_GATEWAY_APPROVAL_REQUIRED')
            for command in ['schema:verify','security:check-config','demo:check']:
                dc('exec','-T','api','php','artisan',command)
            gateway_probe(directory)
            refresh_gateway(directory,m,public=True)
        elif a.action=='refresh-gateway':
            refresh_gateway(directory,m)
        else:
            check(directory,m)
    print(json.dumps({'code':'DEMO_'+a.action.upper()+'_COMPLETED','runtime_acceptance':False,'production_approval':False}))

if __name__=='__main__':
    try:
        main()
    except Exception as error:
        # Only our bounded static codes are safe to display; never raw child logs.
        code=str(error) if isinstance(error,ValueError) and re.fullmatch('[A-Z_]{3,100}',str(error)) else 'DEMO_OPERATION_BLOCKED'
        print(json.dumps({'code':code,'ok':False}),file=sys.stderr)
        sys.exit(1)
