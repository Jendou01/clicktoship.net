#!/usr/bin/env python3
"""Demo-only offline policy. Does not import or modify staging preflight."""
import hashlib
import ipaddress
import json
import re
import stat
import subprocess
from pathlib import Path

HOST = 'staging.zeeship.cloud'
ACK = 'SYNTHETIC_ONLY_PERMANENT_DATA_LOSS_ACCEPTED'
SERVICES = {'api', 'web', 'gateway', 'postgres', 'redis', 'critical', 'background', 'scheduler', 'migrate', 'mailpit'}
FILES = ('application.env', 'migration.env', 'database-owner-password', 'redis.conf', 'tls.crt', 'tls.key', 'gateway.htpasswd', 'host.conf')

def run(args, timeout=180, **kwargs):
    result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False, timeout=timeout, **kwargs)
    if result.returncode:
        error = ValueError('DEMO_COMMAND_REJECTED')
        error.stderr = result.stderr  # Private diagnostic data; never stringify it.
        raise error
    return result.stdout

def digest(path):
    h = hashlib.sha256()
    with Path(path).open('rb') as source:
        for block in iter(lambda: source.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()

def private(path, directory=False):
    p = Path(path)
    return not p.is_symlink() and (p.is_dir() if directory else p.is_file()) and p.stat().st_uid == 0 and not stat.S_IMODE(p.stat().st_mode) & (0o077 if directory else 0o027)

def environment(path):
    out = {}
    for line in Path(path).read_text().splitlines():
        if not line or line.startswith('#'):
            continue
        k, v = line.split('=', 1)
        if k in out or not re.fullmatch('[A-Z_]+', k) or any(c in v for c in '\r\n\x00'):
            raise ValueError('DEMO_ENV_REJECTED')
        out[k] = v
    return out

def validate_env(e, instance):
    expected = {
        'APP_NAME':'ClickToShip_DEMO', 'APP_ENV':'demo', 'APP_DEBUG':'false',
        'CTS_DISPOSABLE_DEMO':'true', 'CTS_DEMO_ACK':ACK, 'CTS_DEMO_INSTANCE':instance,
        'APP_URL':'https://'+HOST, 'APP_ALLOWED_HOSTS':HOST, 'TRUSTED_PROXIES':'172.30.73.10',
        'DB_CONNECTION':'pgsql','DB_HOST':'postgres','DB_PORT':'5432','DB_DATABASE':'clicktoship_demo','DB_USERNAME':'cts_runtime',
        'REDIS_CLIENT':'predis','REDIS_HOST':'redis','REDIS_PREFIX':instance+':',
        'CACHE_STORE':'redis','QUEUE_CONNECTION':'redis','SESSION_DRIVER':'redis',
        'SESSION_ENCRYPT':'true','SESSION_SECURE_COOKIE':'true','SESSION_HTTP_ONLY':'true',
        'SESSION_SAME_SITE':'lax','SESSION_COOKIE':instance+'_session','SANCTUM_STATEFUL_DOMAINS':HOST,
        'MAIL_MAILER':'smtp','MAIL_HOST':'mailpit','MAIL_PORT':'1025',
        'MAIL_FROM_ADDRESS':'noreply@example.invalid','LOG_CHANNEL':'stderr','QUEUE_FAILED_DRIVER':'database-uuids',
    }
    # Exact allowlist excludes DB_URL, external credentials, alternate transports
    # and every unreviewed application toggle. Only generated values may differ.
    return (re.fullmatch(r'cts-demo-[a-f0-9]{12}', instance) is not None
            and set(e) == set(expected) | {'APP_KEY','DB_PASSWORD','REDIS_PASSWORD'}
            and all(e.get(k) == v for k, v in expected.items())
            and re.fullmatch(r'base64:[A-Za-z0-9+/]{43}=', e.get('APP_KEY','')) is not None
            and all(re.fullmatch(r'[a-f0-9]{64}',e.get(k,'')) for k in ('DB_PASSWORD','REDIS_PASSWORD'))
            and len({e['APP_KEY'],e['DB_PASSWORD'],e['REDIS_PASSWORD']}) == 3)

def validate_compose(c, instance, images, public=False, directory=None):
    if c.get('name') != instance or set(c.get('services',{})) != SERVICES:
        return False
    if set(c.get('volumes',{})) != {'database_data','redis_data','private_data'}:
        return False
    for name,v in c['volumes'].items():
        opts = v.get('driver_opts',{})
        if v.get('name')!=instance+'_'+name or v.get('external') or v.get('driver') != 'local' or opts.get('type') != 'tmpfs' or opts.get('device') != 'tmpfs' or 'nosuid' not in opts.get('o','') or 'nodev' not in opts.get('o',''):
            return False
    if set(c.get('networks',{})) != {'ingress','private'} or not c['networks']['private'].get('internal') or any(n.get('external') for n in c['networks'].values()):
        return False
    if c['networks']['private'].get('ipam',{}).get('config') != [{'subnet':'172.30.73.0/24'}]:
        return False
    if any(v.get('name')!=instance+'_'+name or v.get('driver') not in (None,'bridge') for name,v in c['networks'].items()):
        return False
    commands={'postgres':['postgres','-c','log_statement=none','-c','log_min_error_statement=panic','-c','log_error_verbosity=terse','-c','log_parameter_max_length_on_error=0'],
        'redis':['redis-server','/run/secrets/redis_configuration'],
        'migrate':['php','artisan','migrate','--force','--no-interaction'],
        'critical':['php','artisan','horizon'],'background':['php','artisan','horizon'],
        'scheduler':['php','artisan','schedule:work']}
    secret_sources={
        'postgres':{'database_owner_password'},'redis':{'redis_configuration'},
        'gateway':{'tls_certificate','tls_key','gateway_passwords','host_rule'},
        'migrate':{'migration_env'},'web':set(),'mailpit':set(),
    }
    for name,s in c['services'].items():
        if name=='postgres' and s.get('healthcheck',{}).get('test')!=['CMD-SHELL','pg_isready -h 127.0.0.1 -U cts_owner -d clicktoship_demo']:
            return False
        if s.get('privileged') or s.get('network_mode') or s.get('pid') or s.get('devices') or s.get('cap_add') or s.get('env_file') or s.get('build'):
            return False
        if s.get('entrypoint') is not None or s.get('command')!=commands.get(name):
            return False
        if {x['source'] for x in s.get('secrets',[])}!=secret_sources.get(name,{'application_env'}):
            return False
        if 'no-new-privileges:true' not in s.get('security_opt',[]) or not s.get('mem_limit') or not s.get('pids_limit'):
            return False
        expected_image = images['api' if name in {'api','migrate','critical','background','scheduler'} else name]
        if s.get('image') != expected_image or set(s.get('networks',{})) != ({'private','ingress'} if name == 'gateway' else {'private'}):
            return False
        ports=s.get('ports',[])
        if name == 'gateway':
            if len(ports)!=1 or str(ports[0].get('published'))!=('443' if public else '18443') or ports[0].get('target')!=8443 or ports[0].get('host_ip')!=('2.24.48.221' if public else '127.0.0.1') or ports[0].get('protocol')!='tcp':
                return False
            if s['networks']['private'].get('ipv4_address')!='172.30.73.10':
                return False
        elif ports:
            return False
        for v in s.get('volumes',[]):
            if v.get('type')!='volume' or v.get('source') not in c['volumes']:
                return False
        if name not in {'postgres'} and (s.get('read_only') is not True or 'ALL' not in s.get('cap_drop',[])):
            return False
        if name not in {'postgres'} and str(s.get('user'))!=('999:999' if name=='redis' else '10001:10001'):
            return False
    if directory is not None:
        expected={'migration_env':'migration.env','application_env':'application.env',
            'database_owner_password':'database-owner-password','redis_configuration':'redis.conf',
            'tls_certificate':'tls.crt','tls_key':'tls.key','gateway_passwords':'gateway.htpasswd','host_rule':'host.conf'}
        if set(c.get('secrets',{}))!=set(expected) or any(
                v.get('external') or v.get('file')!=str(directory/expected[k]) for k,v in c['secrets'].items()):
            return False
    return True

def verify_review(manifest, signature, public_key, fingerprint):
    if not re.fullmatch('[a-f0-9]{64}',fingerprint) or digest(public_key)!=fingerprint:
        raise ValueError('TRUSTED_REVIEW_KEY_MISMATCH')
    run(['openssl','dgst','-sha256','-verify',str(public_key),'-signature',str(signature),str(manifest)])
    m=json.loads(Path(manifest).read_text())
    if set(m) != {'schema','profile','git_sha','ci_run_id','reviewers','archive_sha256','images'} or m['schema']!=1 or m['profile']!='disposable-remote-demo' or not re.fullmatch('[a-f0-9]{40}',m['git_sha']) or not isinstance(m['ci_run_id'],int) or m['ci_run_id']<=0:
        raise ValueError('RELEASE_MANIFEST_REJECTED')
    if not isinstance(m['reviewers'],list) or len(m['reviewers'])!=2 or not all(isinstance(x,str) and re.fullmatch('[A-Za-z0-9_-]{1,64}',x) for x in m['reviewers']) or len(set(m['reviewers']))!=2:
        raise ValueError('INDEPENDENT_REVIEW_REQUIRED')
    if set(m['images']) != {'api','web','gateway'} or len(set(m['images'].values()))!=3 or not all(re.fullmatch(r'sha256:[a-f0-9]{64}',x) for x in m['images'].values()) or not re.fullmatch('[a-f0-9]{64}',m['archive_sha256']):
        raise ValueError('IMMUTABLE_CONTENT_IDS_REQUIRED')
    return m
