#!/usr/bin/env python3
"""Offline manifest/secret preflight. Never deploys, prints secrets or claims host approval."""
import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import stat

FILES = ('application.env', 'database-owner-password', 'redis.conf', 'tls.crt', 'tls.key', 'gateway.htpasswd', 'host.conf')

def inspect(directory, images, host, local=False, port=443):
    root = Path(directory)
    checks = []
    def add(code, ok): checks.append({'code': code, 'ok': bool(ok)})
    add('EXACT_HOST', bool(re.fullmatch(r'[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?', host)) and host not in ('localhost', '127.0.0.1'))
    add('PINNED_RELEASE_IMAGES', len(images) == 3 and all(re.fullmatch(r'sha256:[a-f0-9]{64}' if local else r'[^\s@]+@sha256:[a-f0-9]{64}', i) for i in images))
    add('SECRET_DIRECTORY_PRIVATE', root.is_dir() and not root.is_symlink() and stat.S_IMODE(root.stat().st_mode) & 0o077 == 0)
    for name in FILES:
        p = root / name
        # Native Compose ignores requested secret uid/mode: enforce host permissions.
        add('SECRET_' + name.upper().replace('.', '_').replace('-', '_'), p.is_file() and not p.is_symlink() and p.stat().st_size > 0 and stat.S_IMODE(p.stat().st_mode) & 0o007 == 0 and stat.S_IMODE(p.stat().st_mode) & 0o022 == 0)
    env = {}
    p = root / 'application.env'
    if p.is_file() and not p.is_symlink():
        for line in p.read_text().splitlines():
            if '=' in line and not line.startswith('#'):
                k, v = line.split('=', 1)
                env[k] = v
    origin = host + (f':{port}' if port != 443 else '')
    expected = {'APP_ENV':'staging','APP_DEBUG':'false','APP_URL':f'https://{origin}','APP_ALLOWED_HOSTS':host,'DB_USERNAME':'cts_runtime','SESSION_SECURE_COOKIE':'true','SESSION_ENCRYPT':'true','SESSION_DRIVER':'redis','SESSION_HTTP_ONLY':'true','SESSION_SAME_SITE':'lax','SANCTUM_STATEFUL_DOMAINS':origin,'QUEUE_CONNECTION':'redis','QUEUE_FAILED_DRIVER':'database-uuids'}
    add('EXACT_APPLICATION_SETTINGS', all(env.get(k) == v for k,v in expected.items()) and not env.get('SESSION_DOMAIN') and not env.get('DB_URL'))
    try:
        proxies = env['TRUSTED_PROXIES'].split(',')
        ok = len(proxies) == 1 and ipaddress.ip_address(proxies[0]).is_private
    except (KeyError, ValueError): ok = False
    add('EXACT_PRIVATE_PROXY_IP', ok)
    add('INDEPENDENT_SECRETS_PRESENT', all(len(env.get(k, '')) >= 32 for k in ('APP_KEY','DB_PASSWORD','REDIS_PASSWORD')))
    add('NO_EXTERNAL_MAIL_IN_SIMULATION', env.get('MAIL_HOST') in ('mail','mailpit'))
    return checks

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--port',type=int,default=443);p.add_argument('--local-image-ids',action='store_true',help='Only for disposable local rehearsal; never remote readiness');p.add_argument('--secrets',required=True);p.add_argument('--host',required=True);p.add_argument('--image',action='append',default=[])
    a=p.parse_args()
    try:checks=inspect(a.secrets,a.image,a.host,a.local_image_ids,a.port)
    except OSError:checks=[{'code':'PREFLIGHT_FILE_ACCESS','ok':False}]
    print(json.dumps({'ok':all(c['ok'] for c in checks),'checks':checks,'scope':'offline_local_simulation_not_remote_deployment_approval'},indent=2))
    return 0 if all(c['ok'] for c in checks) else 1
if __name__=='__main__':raise SystemExit(main())
