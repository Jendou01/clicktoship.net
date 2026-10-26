#!/usr/bin/env python3
"""Fresh isolated loopback-only release rehearsal. Never operates on a retained project."""
import argparse
import base64
import hashlib
import http.cookiejar
import urllib.parse
import json
import os
import re
from pathlib import Path
import secrets
import shutil
import ssl
import subprocess
import tempfile
import tarfile
import time
import urllib.error
import urllib.request
import uuid

ROOT=Path(__file__).resolve().parents[2]

def run(args, data=None, timeout=180, check=True, env=None):
    r=subprocess.run(args,cwd=ROOT,input=data,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout,env=env)
    if check and r.returncode:
        # Child commands may contain private diagnostics. Do not print them.
        raise RuntimeError('REHEARSAL_COMMAND_FAILED:'+args[0]+':'+str(r.returncode))
    return r

def main():
    p=argparse.ArgumentParser();p.add_argument('--output',required=True);p.add_argument('--port',type=int,default=18443)
    a=p.parse_args();os.umask(0o077)
    project='cts17-'+uuid.uuid4().hex[:10];report={'scope':'disposable_loopback_simulation_not_remote_staging','project':project,'checks':{}}
    with tempfile.TemporaryDirectory(prefix='cts17-') as temporary:
        root=Path(temporary);root.chmod(0o700);secure=root/'secrets';secure.mkdir(mode=0o700)
        images={s:run(['docker','image','inspect',f'clicktoship-{s}:phase17','--format','{{.Id}}']).stdout.decode().strip() for s in ('api','web','gateway')}
        env=dict(os.environ,CTS_PROJECT=project,CTS_SECRET_DIR=str(secure),CTS_HTTPS_PORT=str(a.port),CTS_API_IMAGE=images['api'],CTS_WEB_IMAGE=images['web'],CTS_GATEWAY_IMAGE=images['gateway'])
        compose=['docker','compose','-f','infrastructure/deployment/compose.yml','--project-name',project]
        def dc(*args,data=None,check=True,timeout=180):
            print('STEP',args[0],flush=True)
            result=run(compose+list(args),data,timeout,False,env)
            if check and result.returncode:
                diagnostic=result.stderr.decode(errors='replace')[-2500:]
                for value in (password,owner,redis,gate):diagnostic=diagnostic.replace(value,'[redacted]')
                raise RuntimeError('REHEARSAL_STEP_FAILED:'+str(args[0])+':'+diagnostic)
            return result
        password=secrets.token_hex(32);owner=secrets.token_hex(32);redis=secrets.token_hex(32);gate=secrets.token_hex(32)
        origin=f'staging.example.test:{a.port}'
        app=(ROOT/'infrastructure/deployment/environment.example').read_text().replace('APP_KEY=','APP_KEY=base64:'+base64.b64encode(secrets.token_bytes(32)).decode()).replace('DB_PASSWORD=','DB_PASSWORD='+password).replace('REDIS_PASSWORD=','REDIS_PASSWORD='+redis).replace('staging.example.test',origin).replace('APP_ALLOWED_HOSTS='+origin,'APP_ALLOWED_HOSTS=staging.example.test').replace('REQUIRES_PRIVATE_APPROVED_SMTP','mail')
        (secure/'migration.env').write_text(app.replace('DB_USERNAME=cts_runtime','DB_USERNAME=cts_owner').replace('DB_PASSWORD='+password,'DB_PASSWORD='+owner));(secure/'application.env').write_text(app);(secure/'database-owner-password').write_text(owner)
        (secure/'redis.conf').write_text(f'bind 0.0.0.0\nprotected-mode yes\nrequirepass {redis}\nappendonly yes\ndir /data\nmaxmemory 256mb\nmaxmemory-policy noeviction\n')
        digest=run(['openssl','passwd','-6','-stdin'],data=gate.encode()).stdout.decode().strip()
        (secure/'gateway.htpasswd').write_text('owner:'+digest+'\n')
        (secure/'host.conf').write_text('if ($host != staging.example.test) { return 444; }\n')
        run(['openssl','req','-x509','-newkey','rsa:3072','-sha256','-nodes','-days','2','-subj','/CN=staging.example.test','-addext','subjectAltName=DNS:staging.example.test,IP:127.0.0.1','-keyout',str(secure/'tls.key'),'-out',str(secure/'tls.crt')])
        # Compose bind secrets preserve owner/mode. Trust the generated CA before changing file ownership.
        cert=root/'trust.crt';cert.write_bytes((secure/'tls.crt').read_bytes())
        files=list(secure.iterdir())
        for file in files:file.chmod(0o440)
        offline=run(['python3','scripts/deployment/preflight.py','--secrets',str(secure),'--host','staging.example.test','--port',str(a.port),'--local-image-ids']+sum([['--image',image] for image in images.values()],[]))
        report['offline_preflight']=json.loads(offline.stdout)
        run(['docker','run','--rm','--user','0','--entrypoint','chown','-v',f'{secure}:/secrets',images['api'],'10001:10001']+['/secrets/'+f.name for f in files])
        run(['docker','run','--rm','--user','0','--entrypoint','chown','-v',f'{secure}:/secrets',images['api'],'999:999','/secrets/redis.conf'])
        try:
            rendered=json.loads(dc('config','--format','json').stdout)
            assert rendered['networks']['private']['internal'] is True
            for name,service in rendered['services'].items():
                if name=='gateway':
                    assert len(service['ports'])==1 and service['ports'][0]['host_ip']=='127.0.0.1'
                else:assert not service.get('ports')
                if name not in ('postgres',):
                    assert service['read_only'] is True
                    assert 'ALL' in service['cap_drop']
            report['checks']['rendered_private_network_nonroot_readonly_limits']=True
            dc('up','-d','--wait','postgres','redis')
            sql="CREATE ROLE cts_runtime LOGIN PASSWORD '"+password+"' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;\nCREATE ROLE cts_finance_runtime NOLOGIN;\n"
            dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_staging','-v','ON_ERROR_STOP=1',data=sql.encode())
            # Separate one-shot migrator has the owner's credentials, never API/worker runtime.
            dc('run','--rm','--no-deps','-T','migrate')
            dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_staging','-v','ON_ERROR_STOP=1',data=(ROOT/'infrastructure/deployment/runtime-grants.sql').read_bytes())
            dc('run','--rm','--no-deps','-T','api','php','artisan','schema:verify')
            pre=dc('run','--rm','--no-deps','-T','api','php','artisan','security:check-config')
            report['configuration']=json.loads(pre.stdout);assert report['configuration']['ok']
            dc('up','-d','--wait',timeout=240)
            report['checks']['fresh_migrations_restricted_runtime']=True
            dc('exec','-T','api','php','artisan','config:cache')
            cached=json.loads(dc('exec','-T','api','php','artisan','security:check-config').stdout)
            assert cached['ok']
            report['checks']['cached_configuration_preflight']=True
            jar=http.cookiejar.CookieJar();ctx=ssl.create_default_context(cafile=str(cert));opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPSHandler(context=ctx),urllib.request.HTTPCookieProcessor(jar))
            auth='Basic '+base64.b64encode(('owner:'+gate).encode()).decode()
            def request(path,authorized=True,host=origin,payload=None):
                headers={'Host':host,'Accept':'application/json','Origin':'https://'+origin,**({'Authorization':auth} if authorized else {})}
                token=next((c.value for c in jar if c.name=='XSRF-TOKEN'),None)
                if token:headers['X-XSRF-TOKEN']=urllib.parse.unquote(token)
                if payload is not None:headers['Content-Type']='application/json'
                r=urllib.request.Request(f'https://127.0.0.1:{a.port}'+path,headers=headers,data=json.dumps(payload).encode() if payload is not None else None)
                try:return opener.open(r,timeout=15)
                except urllib.error.HTTPError as e:return e
            assert request('/en',False).status==401
            assert request('/api/v1/health/ready',False).status==401
            assert request('/api/v1/health/ready').status==200
            try:
                request('/en',host='evil.example.test')
            except (OSError,urllib.error.URLError):pass
            else:raise RuntimeError('UNTRUSTED_HOST_ACCEPTED')
            for lang in ('en','ar'):
                r=request('/'+lang);body=r.read();assert r.status==200 and len(body)>1000
                asset=re.search(rb'src="(/_next/static/[^"]+)"',body)
                assert asset and request(asset.group(1).decode(),False).status==401
                assert r.headers['Strict-Transport-Security']=='max-age=31536000'
            for path in ('/horizon','/.env','/storage/private'):
                assert request(path).status==404
            assert request('/api/v1/shipments').status==401
            # Real registration through TLS + gateway + CSRF, no synthetic bypass of email verification.
            assert request('/sanctum/csrf-cookie').status==204
            synthetic='review-'+secrets.token_hex(8)+'@example.test'
            userpass='Ab1!'+secrets.token_hex(20)
            registration=request('/api/v1/auth/register',payload={'name':'Synthetic staging reviewer','email':synthetic,'password':userpass,'password_confirmation':userpass,'locale':'en'})
            assert registration.status==201
            assert json.loads(registration.read())['data']['user']['email_verified'] is False
            report['checks']['tls_csrf_registration_unverified_account']=True
            report['checks']['tls_hostname_auth_gate_en_ar_private_routes']=True
            # Chromium trusts only the generated local certificate in an independent NSS home.
            home=root/'browser-home';nss=home/'.pki/nssdb';nss.mkdir(parents=True)
            run(['certutil','-N','-d','sql:'+str(nss),'--empty-password'])
            run(['certutil','-A','-d','sql:'+str(nss),'-n','cts-local-test','-t','C,,','-i',str(cert)])
            fixture=root/'browser.json';fixture.write_text(json.dumps({'url':'https://'+origin,'password':gate,'home':str(home)}))
            browser=run(['node','scripts/deployment/browser-smoke.cjs',str(fixture)],timeout=120,check=False)
            if browser.returncode:raise RuntimeError(browser.stderr.decode(errors='replace')[-500:])
            report['browser']=json.loads(browser.stdout)
            report['checks']['protected_tls_browser_en_ar_desktop_mobile']=True
            # Prove actual runtime cannot mutate monetary projections or schema.
            denied="SET ROLE cts_runtime; UPDATE wallet_balances SET posted=1;"
            assert dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_staging','-v','ON_ERROR_STOP=1',data=denied.encode(),check=False).returncode!=0
            assert dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_staging','-v','ON_ERROR_STOP=1',data=b'SET ROLE cts_runtime; CREATE TABLE forbidden(id int);',check=False).returncode!=0
            report['checks']['runtime_money_and_ddl_denied']=True
            # Existing application zero-wallet provisioning works without monetary-column grants.
            probe=b'''<?php require '/app/vendor/autoload.php'; $app=require '/app/bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
            if(!app()->environment('staging') || Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName()!=='clicktoship_staging')exit(9);
            $m=(string)Illuminate\\Support\\Str::uuid();Illuminate\\Support\\Facades\\DB::table('merchants')->insert(['id'=>$m,'name'=>'Synthetic local zero-wallet','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
            $w=app(App\\Modules\\WalletBilling\\Application\\Ledger::class)->provision($m);
            $b=Illuminate\\Support\\Facades\\DB::table('wallet_balances')->where('wallet_id',$w)->first();
            if((string)$b->posted!=='0'||(string)$b->reserved!=='0')exit(8);$r=app(App\\Modules\\WalletBilling\\Application\\Reconciliation::class)->run($m,$w);if(!$r['matches'])exit(7);echo 'ZERO_WALLET_OK';'''
            assert dc('exec','-T','api','php',data=probe).stdout.strip()==b'ZERO_WALLET_OK'
            report['checks']['zero_wallet_provisioning_without_amount_writes']=True
            # Graceful restart, unchanged retained database, health after fresh FPM/worker startup.
            dc('restart','api','critical','background','scheduler')
            restart_statuses={}
            deadline=time.monotonic()+45
            while time.monotonic()<deadline:
                try:
                    response=request('/api/v1/health/ready');status=str(response.status)
                    restart_statuses[status]=restart_statuses.get(status,0)+1
                    if response.status==200:break
                except (OSError,urllib.error.URLError):pass
                time.sleep(.25)
            else:raise RuntimeError('RESTART_READINESS_FAILED:'+json.dumps(restart_statuses))
            report['restart_http_status_counts']=restart_statuses
            dc('exec','-T','critical','php','artisan','horizon:status')
            report['checks']['graceful_restart_horizon_readiness']=True
            # Independent release rollback: recreate the exact recorded artifact without schema down.
            dc('up','-d','--wait','--force-recreate','api','web','gateway',timeout=180)
            assert request('/api/v1/health/ready').status==200
            report['checks']['same_artifact_recreate_no_schema_downgrade']=True
            # Consistent local encrypted backup: writers quiesced, escrow key separate from ciphertext.
            objects=root/'objects';objects.mkdir();(objects/'synthetic.txt').write_text('SYNTHETIC LOCAL BACKUP OBJECT ONLY')
            encryption=b"<?php require '/app/vendor/autoload.php';$app=require '/app/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();echo Illuminate\\Support\\Facades\\Crypt::encryptString('synthetic-restored-object');"
            (objects/'key-check.enc').write_bytes(dc('exec','-T','api','php',data=encryption).stdout)
            dc('stop','api','critical','background','scheduler')
            backup=root/'backup';key=root/'escrow.key';recipient=root/'recipient.crt'
            run(['openssl','req','-x509','-newkey','rsa:3072','-sha256','-nodes','-days','2','-subj','/CN=local-backup-recipient','-keyout',str(key),'-out',str(recipient)])
            container=project+'-postgres-1'
            run(['python3','scripts/deployment/backup.py','--rehearsal-container',container,'--objects',str(objects),'--recipient-certificate',str(recipient),'--output',str(backup)])
            decrypted=root/'restored.dump'
            run(['openssl','cms','-decrypt','-binary','-inform','DER','-in',str(backup/'database.dump.enc'),'-recip',str(recipient),'-inkey',str(key),'-out',str(decrypted)])
            for name in ('objects.tar','objects-manifest.json'):
                run(['openssl','cms','-decrypt','-binary','-inform','DER','-in',str(backup/(name+'.enc')),'-recip',str(recipient),'-inkey',str(key),'-out',str(root/name)])
            restored_objects=root/'restored-objects';restored_objects.mkdir()
            with tarfile.open(root/'objects.tar') as archive:
                assert all(m.isfile() or m.isdir() for m in archive.getmembers())
                archive.extractall(restored_objects,filter='data')
            manifest=json.loads((root/'objects-manifest.json').read_text())
            assert all(hashlib.sha256((restored_objects/name).read_bytes()).hexdigest()==digest for name,digest in manifest.items())
            report['checks']['encrypted_object_manifest_restore']=True
            # GCM rejects corrupted ciphertext; no unauthenticated decrypted result is used.
            corrupt=bytearray((backup/'database.dump.enc').read_bytes());corrupt[-5]^=1
            (root/'corrupted.enc').write_bytes(corrupt)
            assert run(['openssl','cms','-decrypt','-binary','-inform','DER','-in',str(root/'corrupted.enc'),'-recip',str(recipient),'-inkey',str(key),'-out',str(root/'rejected.dump')],check=False).returncode!=0
            dc('exec','-T','postgres','createdb','-U','cts_owner','cts17_restore')
            dc('exec','-T','postgres','pg_restore','-U','cts_owner','-d','cts17_restore','--no-owner','--no-acl','--exit-on-error',data=decrypted.read_bytes())
            count=dc('exec','-T','postgres','psql','-U','cts_owner','-d','cts17_restore','-Atc','SELECT count(*) FROM migrations').stdout.decode().strip()
            assert count=='24'
            dc('exec','-T','postgres','psql','-U','cts_owner','-d','cts17_restore','-v','ON_ERROR_STOP=1',data=(ROOT/'infrastructure/deployment/runtime-grants.sql').read_bytes())
            dc('run','--rm','--no-deps','-T','-e','DB_DATABASE=cts17_restore','api','php','artisan','schema:verify')
            ciphertext=(restored_objects/'key-check.enc').read_text()
            verification=("<?php require '/app/vendor/autoload.php';$app=require '/app/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$c="+json.dumps(ciphertext)+";if(Illuminate\\Support\\Facades\\Crypt::decryptString($c)!=='synthetic-restored-object')exit(8);try{(new Illuminate\\Encryption\\Encrypter(random_bytes(32),'AES-256-CBC'))->decryptString($c);exit(9);}catch(Illuminate\\Contracts\\Encryption\\DecryptException $e){}echo 'RESTORED_KEY_OK';").encode()
            assert dc('run','--rm','--no-deps','-T','api','php',data=verification).stdout.strip()==b'RESTORED_KEY_OK'
            public_execute=dc('exec','-T','postgres','psql','-U','cts_owner','-d','cts17_restore','-Atc',"SELECT count(*) FROM pg_proc p WHERE proname='finance_command' AND EXISTS (SELECT 1 FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE a.grantee=0 AND a.privilege_type='EXECUTE')").stdout.decode().strip()
            assert public_execute=='0'
            report['checks']['restored_finance_function_not_publicly_executable']=True
            report['checks']['restored_schema_key_preserved_wrong_key_rejected']=True
            report['checks']['authenticated_encrypted_backup_restore_24_migrations']=True
            report['checks']['backup_ciphertext_tampering_rejected']=True
            report['images']=images
            report['offsite_backup_verified']=False
            report['remote_deployment_authorized']=False
        except BaseException:
            # Persist bounded synthetic-only diagnostics, redacted before any evidence output.
            diagnostics={}
            for service in ('api','critical','background','gateway'):
                r=run(['docker','logs','--tail','12',project+'-'+service+'-1'],check=False)
                text=(r.stdout+r.stderr).decode(errors='replace')[-2000:]
                for value in (password,owner,redis,gate):text=text.replace(value,'[redacted]')
                diagnostics[service]=text
            report['failure_diagnostics']=diagnostics
            raise
        finally:
            dc('down','--remove-orphans',check=False)
            for volume in ('database_data','redis_data','private_data'):
                run(['docker','volume','rm',project+'_'+volume],check=False)
            # Restore ownership only on this generated, ephemeral directory for cleanup.
            run(['docker','run','--rm','--user','0','--entrypoint','chown','-v',f'{secure}:/secrets',images['api'],'-R',f'{os.getuid()}:{os.getgid()}','/secrets'],check=False)
            Path(a.output).write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({'code':'LOCAL_RELEASE_REHEARSAL_PASSED','checks':len(report['checks']),'remote_deployment':False}))
if __name__=='__main__':main()
