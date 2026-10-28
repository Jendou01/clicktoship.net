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
from pathlib import Path
from policy import ACK, HOST, FILES, digest, environment, private, run, validate_compose, validate_env, verify_review

ROOT = Path(__file__).resolve().parents[2]
COMPOSE = ROOT/'infrastructure/demo/compose.yml'
FIXED = {
    'postgres':'postgres:17-bookworm@sha256:858bb5e98eed02eb8cafbcdc007f9a65d1b613972028944a8ec2133ccaf80d27',
    'redis':'redis:7-bookworm@sha256:44960b3100c48159a66d28c18b0588c193492e25f7551d60e51dd464fc3eca5b',
    'mailpit':'axllent/mailpit@sha256:0790692be935ea4420d085694f7985b1f692ca6a8771ab0e0bd8ba75ac180498',
}

def protected_ram(path):
    if sys.platform!='linux' or os.geteuid()!=0 or Path('/proc/swaps').read_text().strip().count('\n'):
        raise ValueError('ROOT_LINUX_WITHOUT_SWAP_REQUIRED')
    resolved=path.resolve()
    if path.is_symlink() or not str(resolved).startswith('/run/cts-demo-') or resolved.parent!=Path('/run'):
        raise ValueError('NEW_DEDICATED_RUN_DIRECTORY_REQUIRED')
    if run(['findmnt','-n','-o','FSTYPE','-T','/run']).strip()!=b'tmpfs':
        raise ValueError('PROTECTED_RAM_REQUIRED')

def verify_target():
    if socket.gethostname().lower().rstrip('.') not in {'srv2104412','srv2104412.hstgr.cloud'}:
        raise ValueError('EXACT_AUTHORIZED_VPS_REQUIRED')
    addresses=json.loads(run(['ip','-j','-4','address','show']))
    if not any(info.get('local')=='2.24.48.221' for device in addresses for info in device.get('addr_info',[])):
        raise ValueError('EXACT_AUTHORIZED_VPS_IP_REQUIRED')

def tls(directory):
    crt=directory/'tls.crt';key=directory/'tls.key'
    run(['openssl','x509','-in',str(crt),'-noout','-checkhost',HOST])
    run(['openssl','x509','-in',str(crt),'-noout','-checkend',str(21*86400)])
    # fullchain must verify to the host's normal CA roots; no insecure flags.
    run(['openssl','verify','-purpose','sslserver','-verify_hostname',HOST,'-untrusted',str(crt),str(crt)])
    certpub=run(['openssl','x509','-in',str(crt),'-pubkey','-noout'])
    keypub=run(['openssl','pkey','-in',str(key),'-pubout'])
    if certpub!=keypub:
        raise ValueError('TLS_KEY_PAIR_MISMATCH')

def approved(a):
    m=verify_review(a.manifest,a.signature,a.review_key,a.review_key_sha256)
    if run(['git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip()!=m['git_sha'] or run(['git','-C',str(ROOT),'status','--porcelain']).strip():
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
        info=json.loads(run(['docker','image','inspect',image]))[0]
        labels=info.get('Config',{}).get('Labels') or {}
        if info['Id']!=image or labels.get('org.opencontainers.image.revision')!=m['git_sha']:
            raise ValueError('IMAGE_IDENTITY_MISMATCH')
        if service=='web' and labels.get('org.clicktoship.profile')!='disposable-remote-demo':
            raise ValueError('DEMO_WEB_BUILD_REQUIRED')

def context(directory,m,public=None):
    state=json.loads((directory/'instance.json').read_text())
    instance=state['instance']
    if set(state)!={'instance','profile','published'} or state['instance']!=directory.name or state['profile']!='disposable-remote-demo' or not isinstance(state['published'],bool) or not re.fullmatch('cts-demo-[a-f0-9]{12}',instance):
        raise ValueError('INSTANCE_REJECTED')
    if public is None:
        public=state['published']
    # No inherited Compose/DB/Redis/proxy or remote Docker variables.
    env={'PATH':os.environ['PATH'],'HOME':'/root','DOCKER_HOST':'unix:///var/run/docker.sock',
         'CTS_PROJECT':instance,'CTS_SECRET_DIR':str(directory),'CTS_PROXY_IP':'172.30.73.10',
         'CTS_API_IMAGE':m['images']['api'],'CTS_WEB_IMAGE':m['images']['web'],'CTS_GATEWAY_IMAGE':m['images']['gateway']}
    command=['docker','compose','--env-file','/dev/null','--profile','maintenance','-f',str(COMPOSE),'-p',instance]
    if public:
        command+=['-f',str(ROOT/'infrastructure/demo/publish.yml')]
    def dc(*args,data=None):
        return run(command+list(args),env=env,input=data)
    rendered=json.loads(dc('config','--format','json'))
    if not validate_compose(rendered,instance,{**m['images'],**FIXED},public):
        raise ValueError('COMPOSE_BOUNDARY_REJECTED')
    return state,dc,rendered

def gateway_probe(directory):
    class LocalTLS(http.client.HTTPSConnection):
        def connect(self):
            self.sock=ssl.create_default_context().wrap_socket(socket.create_connection(('127.0.0.1',18443),timeout=15),server_hostname=HOST)
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

def check(directory,m):
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
    tls(directory);image_identity(m)
    return context(directory,m)

def main():
    p=argparse.ArgumentParser()
    p.add_argument('action',choices=['prepare','verify','start','publish','stop','teardown','load'])
    p.add_argument('--directory',required=True,type=Path)
    p.add_argument('--manifest',required=True,type=Path);p.add_argument('--signature',required=True,type=Path)
    p.add_argument('--review-key',required=True,type=Path);p.add_argument('--review-key-sha256',required=True)
    p.add_argument('--archive',type=Path);p.add_argument('--certificate',type=Path);p.add_argument('--tls-key',type=Path)
    p.add_argument('--ack',required=True)
    p.add_argument('--approve-public-gateway',action='store_true')
    a=p.parse_args()
    if a.ack!=ACK:
        raise ValueError('EXPLICIT_DATA_LOSS_ACK_REQUIRED')
    directory=a.directory;protected_ram(directory);verify_target()
    m=approved(a)
    if a.action=='load':
        if not a.archive or a.archive.is_symlink() or digest(a.archive)!=m['archive_sha256']:
            raise ValueError('APPROVED_ARCHIVE_REQUIRED')
        run(['docker','load','-i',str(a.archive)]);image_identity(m)
    elif a.action=='prepare':
        if directory.exists() or not re.fullmatch('cts-demo-[a-f0-9]{12}',directory.name) or not a.certificate or not a.tls_key:
            raise ValueError('FRESH_INSTANCE_AND_TLS_REQUIRED')
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
        for source,name in [(a.certificate,'tls.crt'),(a.tls_key,'tls.key')]:
            if not source.is_file() or source.is_symlink():
                raise ValueError('EXPLICIT_TLS_FILE_REQUIRED')
            shutil.copyfile(source,directory/name)
        (directory/'instance.json').write_text(json.dumps({'instance':directory.name,'profile':'disposable-remote-demo','published':False}))
        for f in FILES:
            (directory/f).chmod(0o440);os.chown(directory/f,999 if f=='redis.conf' else 10001,999 if f=='redis.conf' else 10001)
        check(directory,m)
    else:
        state,dc,rendered=check(directory,m)
        if a.action=='start':
            if state['published']:
                raise ValueError('FRESH_PRIVATE_START_REQUIRED')
            existing=run(['docker','ps','-aq','--filter','label=com.docker.compose.project='+state['instance']]).strip()
            if existing:
                raise ValueError('FRESH_START_ONLY_STOP_OR_REVIEW_EXISTING_INSTANCE')
            if run(['docker','volume','ls','-q','--filter','label=com.docker.compose.project='+state['instance']]).strip():
                raise ValueError('RETAINED_PROJECT_VOLUMES_REJECTED')
            # Fail closed on subnet overlap with any retained Docker network.
            for network_id in run(['docker','network','ls','-q']).decode().split():
                network=json.loads(run(['docker','network','inspect',network_id]))[0]
                for cfg in network.get('IPAM',{}).get('Config') or []:
                    subnet=cfg.get('Subnet')
                    if subnet and ':' not in subnet and __import__('ipaddress').ip_network(subnet).overlaps(__import__('ipaddress').ip_network('172.30.73.0/24')):
                        raise ValueError('DEMO_SUBNET_CONFLICT')
            dc('up','-d','--wait','postgres','redis','mailpit')
            e=environment(directory/'application.env')
            sql="CREATE ROLE cts_runtime LOGIN PASSWORD '"+e['DB_PASSWORD']+"' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;\nCREATE ROLE cts_finance_runtime NOLOGIN;\n"
            dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_demo','-v','ON_ERROR_STOP=1',data=sql.encode())
            dc('run','--rm','--no-deps','-T','migrate')
            dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_demo','-v','ON_ERROR_STOP=1',data=(ROOT/'infrastructure/deployment/runtime-grants.sql').read_bytes())
            for command in ['schema:verify','security:check-config','demo:check']:
                dc('run','--rm','--no-deps','-T','api','php','artisan',command)
            dc('up','-d','--wait','api','web','critical','background','scheduler')
            # Gateway is deliberately last, after actual runtime checks.
            dc('up','-d','--wait','gateway')
            gateway_probe(directory)
        elif a.action=='publish':
            if not a.approve_public_gateway or state['published']:
                raise ValueError('SEPARATE_PUBLIC_GATEWAY_APPROVAL_REQUIRED')
            for command in ['schema:verify','security:check-config','demo:check']:
                dc('exec','-T','api','php','artisan',command)
            gateway_probe(directory)
            _,public_dc,_=context(directory,m,True)
            try:
                public_dc('up','-d','--no-deps','--force-recreate','--wait','gateway')
                (directory/'instance.json').write_text(json.dumps({**state,'published':True}))
            except Exception:
                public_dc('stop','gateway')
                raise
        elif a.action=='stop':
            dc('stop')
        elif a.action=='teardown':
            # Only exact project-owned resources; no global prune, retained mounts,
            # arbitrary paths or external volumes. Wrong labels abort.
            for kind in ['container','volume','network']:
                ids=run(['docker',kind,'ls',*(['-a'] if kind=='container' else []),'-q','--filter','label=com.docker.compose.project='+state['instance']]).decode().split()
                for resource in ids:
                    item=json.loads(run(['docker',kind,'inspect',resource]))[0]
                    labels=item.get('Labels') or item.get('Config',{}).get('Labels') or {}
                    if labels.get('com.docker.compose.project')!=state['instance']:
                        raise ValueError('RESOURCE_OWNERSHIP_REJECTED')
                    if kind=='volume' and ((item.get('Options') or {}).get('type')!='tmpfs' or item.get('Driver')!='local'):
                        raise ValueError('RETAINED_VOLUME_TEARDOWN_REJECTED')
            dc('down','--volumes')
    print(json.dumps({'code':'DEMO_'+a.action.upper()+'_COMPLETED','runtime_acceptance':False,'production_approval':False}))

if __name__=='__main__':
    try:
        main()
    except Exception:
        # Subprocess/env/SQL/HTTP errors may contain credentials. Never echo them.
        print(json.dumps({'code':'DEMO_OPERATION_BLOCKED','ok':False}),file=sys.stderr)
        sys.exit(1)
