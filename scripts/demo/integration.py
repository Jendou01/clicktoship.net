#!/usr/bin/env python3
"""Ephemeral private Linux CI exercise; explicitly refuses the actual VPS.

Invokes lifecycle cores, never the production CLI approval/target gates. Test CA
and test signing key are synthetic fixtures, not trusted release approval.
"""
import argparse
import base64
import hashlib
import hmac
import http.client
import json
import os
import re
import secrets
import shutil
import socket
import ssl
import struct
import subprocess
import tempfile
import time
import urllib.parse
from pathlib import Path
from control import (ROOT, FIXED, HOST, protected_ram, prepare, check, context,
                     start_instance, refresh_gateway, gateway_probe, verify_archive, image_identity)
from policy import run, digest, verify_review, environment, safe_compose_detail
from resources import SOCKET, docker_env, docker, owned_action, receipt, inventory, write_private
from package import build_images

def runner_only():
    if os.geteuid()!=0 or os.environ.get('GITHUB_ACTIONS')!='true':
        raise ValueError('ISOLATED_LINUX_CI_ROOT_REQUIRED')
    addresses=json.loads(run(['ip','-j','-4','address','show']))
    if socket.gethostname().lower().rstrip('.') in {'srv2104412','srv2104412.hstgr.cloud'} or any(
            x.get('local')=='2.24.48.221' for device in addresses for x in device.get('addr_info',[])):
        raise ValueError('INTEGRATION_REFUSES_REAL_VPS')

def certificate(root, prefix):
    key=root/(prefix+'.key');csr=root/(prefix+'.csr');leaf=root/(prefix+'.crt');chain=root/(prefix+'.chain')
    run(['openssl','req','-new','-newkey','rsa:3072','-nodes','-subj','/CN='+HOST,'-keyout',str(key),'-out',str(csr)])
    run(['openssl','x509','-req','-in',str(csr),'-CA',str(root/'ca.crt'),'-CAkey',str(root/'ca.key'),
         '-CAcreateserial','-out',str(leaf),'-days','30','-extfile',str(root/'server.ext')])
    chain.write_bytes(leaf.read_bytes()+(root/'ca.crt').read_bytes())
    return chain,key

class BrowserSession:
    """Real cookie/CSRF API client, physical loopback only with verified SNI."""
    def __init__(self, directory, ca):
        self.cookies={};self.ca=ca
        credential=json.loads((directory/'reviewer-access.json').read_text())
        self.authorization='Basic '+base64.b64encode((credential['username']+':'+credential['password']).encode()).decode()

    def request(self,path,data=None,expected=200,csrf=True):
        connection=http.client.HTTPSConnection(HOST,timeout=20)
        connection.sock=ssl.create_default_context(cafile=str(self.ca)).wrap_socket(
            socket.create_connection(('127.0.0.1',18443),timeout=20),server_hostname=HOST)
        headers={'Host':HOST,'Authorization':self.authorization,'Accept':'application/json',
                 'Origin':'https://'+HOST,'Referer':'https://'+HOST+'/en/dashboard',
                 'Cookie':'; '.join(k+'='+v for k,v in self.cookies.items())}
        if csrf:
            headers['X-XSRF-TOKEN']=urllib.parse.unquote(self.cookies.get('XSRF-TOKEN',''))
        if data is not None:
            headers['Content-Type']='application/json'
        try:
            connection.request('POST' if data is not None else 'GET',path,
                body=json.dumps(data).encode() if data is not None else None,headers=headers)
            response=connection.getresponse()
            for name,value in response.getheaders():
                if name.lower()=='set-cookie':
                    key,cookie=value.split(';',1)[0].split('=',1);self.cookies[key]=cookie
            body=response.read()
            if response.status!=expected:
                raise ValueError('API_EXPECTED_'+str(expected)+'_GOT_'+str(response.status))
            return json.loads(body)['data'] if body and expected<400 else {}
        finally:
            connection.close()

def otp(secret,step=None):
    key=base64.b32decode(secret+'='*((8-len(secret)%8)%8))
    result=hmac.new(key,struct.pack('>Q',step if step is not None else int(time.time())//30),hashlib.sha1).digest()
    offset=result[-1]&15
    return str((int.from_bytes(result[offset:offset+4],'big')&0x7fffffff)%1000000).zfill(6)

def mail_fragment(dc,email,field):
    # Web's existing Node runtime is on the private network; no inbox port is
    # published. Captured synthetic verification/invitation tokens never log.
    script="""const r=await fetch('http://mailpit:8025/api/v1/messages?limit=100');
const rows=(await r.json()).messages||[]; const out=[];
for(const row of rows) if(row.To.some(x=>x.Address===process.argv[1])) {
 const m=await(await fetch('http://mailpit:8025/api/v1/message/'+row.ID)).json();out.push(m.Text+' '+m.HTML);}
console.log(JSON.stringify(out));"""
    deadline=time.monotonic()+45
    while time.monotonic()<deadline:
        messages=json.loads(dc('exec','-T','web','node','--input-type=module','-e',script,email))
        match=re.search(r'#'+field+r'=([^\s<>"\']+)', ' '.join(messages))
        if match:
            return urllib.parse.unquote(match.group(1).replace('&amp;','&'))
        time.sleep(1)
    # Never emit mail bodies, tokens or raw worker logs. Only bounded exception
    # class names from the already-sanitized application logger aid diagnosis.
    worker=dc('ps','-q','background').decode().strip()
    logs=subprocess.run(['docker','--host',SOCKET,'logs',worker],env=docker_env(),
        stdout=subprocess.PIPE,stderr=subprocess.STDOUT,check=True,timeout=20).stdout.decode(errors='replace')
    types=sorted(set(re.findall(r'"exception_type":"([A-Za-z_\\\\]{1,160})"',logs)))
    print(json.dumps({'code':'PRIVATE_MAIL_DIAGNOSTIC','message_present':bool(messages),
                      'exception_types':types}),flush=True)
    raise ValueError('PRIVATE_MAIL_LINK_NOT_OBSERVED' if messages else 'PRIVATE_MAIL_DELIVERY_NOT_OBSERVED')

def register(directory,ca,dc,key):
    client=BrowserSession(directory,ca);client.request('/sanctum/csrf-cookie',expected=204)
    account={'email':'demo-'+key.lower()+'-'+secrets.token_hex(3)+'@example.invalid','password':'Ab1!'+secrets.token_urlsafe(24)}
    result=client.request('/api/v1/auth/register',{'name':'TEST DEMO '+key,'email':account['email'],
        'password':account['password'],'password_confirmation':account['password'],'locale':'en'},201)
    assert result['user']['email_verified'] is False
    assert result['user']['email']==account['email']
    account['id']=result['user']['id']
    verification=mail_fragment(dc,account['email'],'verification')
    client.request('/api/v1/auth/email/verify',{'verification':verification})
    return client,account

def diagnose(directory):
    """Bounded state only; never emit environment, health output or raw logs."""
    if not (directory/'resources.json').is_file():return
    r=receipt(directory)
    for name,item in inventory(directory,r['images']).items():
        if not name.startswith('container:'):continue
        info=json.loads(docker('inspect',item['id']))[0]
        raw=subprocess.run(['docker','--host',SOCKET,'logs',item['id']],env=docker_env(),
            stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=20).stdout.decode(errors='replace')
        state=info['State'];health=state.get('Health',{})
        raw+='\n'.join(x.get('Output','') for x in health.get('Log',[]))
        print(json.dumps({'code':'PRIVATE_CONTAINER_DIAGNOSTIC','service':name.split(':')[1],
            'exit':state['ExitCode'],'oom':state['OOMKilled'],'status':state['Status'],
            'health':health.get('Status'),
            'exception_types':sorted(set(re.findall(r'"exception_type":"([A-Za-z_\\\\]{1,160})"',raw))),
            'static_codes':sorted(set(re.findall(r'\b(?:SCHEMA|DISPOSABLE|DEMO)_[A-Z_]{3,80}\b',raw))),
            'failure_flags':[word for word in ['permission denied','read-only file system','out of memory','not found'] if word in raw.lower()]}),flush=True)

def exercise(root,metadata,output):
    checks={};directories=[];canary=None;canary_volume=None
    m={'schema':1,'profile':'disposable-remote-demo','git_sha':metadata['sha'],'ci_run_id':1,
       'reviewers':['synthetic-ci-builder','synthetic-ci-reviewer'],'images':metadata['images'],
       'archive_sha256':digest(root/'images.tar')}
    image_identity(m);verify_archive(root/'images.tar',m);checks['actual_archive_content_ids_and_tags']=True
    bad=root/'corrupt.tar';bad.write_bytes(b'not the approved archive')
    try:verify_archive(bad,m)
    except ValueError:checks['corrupt_archive_rejected_before_load']=True
    else:raise ValueError('CORRUPT_ARCHIVE_ACCEPTED')
    docker('load','-i',str(root/'images.tar'),timeout=600);image_identity(m)
    run(['openssl','req','-x509','-newkey','rsa:3072','-nodes','-days','32','-subj','/CN=CI-ONLY-Demo-CA',
         '-keyout',str(root/'ca.key'),'-out',str(root/'ca.crt')])
    (root/'server.ext').write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:'+HOST+'\n')
    crt,key=certificate(root,'first')
    # Cryptographically test the exact candidate bytes with an explicitly
    # ephemeral CI reviewer. Never claim these names/key are human approval.
    manifest=root/'manifest.json';manifest.write_text(json.dumps(m,sort_keys=True))
    run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:3072','-out',str(root/'review.key')])
    run(['openssl','pkey','-in',str(root/'review.key'),'-pubout','-out',str(root/'review.pub')])
    run(['openssl','dgst','-sha256','-sign',str(root/'review.key'),'-out',str(root/'manifest.sig'),str(manifest)])
    assert verify_review(manifest,root/'manifest.sig',root/'review.pub',digest(root/'review.pub'))==m
    checks['ephemeral_review_signature_fixture_not_human_approval']=True
    try:
        canary_volume='cts-demo-canary-'+secrets.token_hex(6)
        docker('volume','create',canary_volume)
        canary=docker('run','-d','--network','none','--name',canary_volume,
            '--entrypoint','sleep','-v',canary_volume+':/canary',FIXED['redis'],'1800').decode().strip()
        directory=Path('/run/cts-demo-'+secrets.token_hex(6));directories.append(directory)
        protected_ram(directory);prepare(directory,m,crt,key,root/'ca.crt')
        print('DEMO_IT_START',flush=True)
        start_instance(directory,m,root/'ca.crt');checks['actual_private_start_migrations_and_readiness']=True
        _,dc,_=check(directory,m,root/'ca.crt')
        log_policy=dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_demo','-Atc',
            "SELECT current_setting('log_statement')='none' AND current_setting('log_min_error_statement')='panic' AND current_setting('log_error_verbosity')='terse' AND current_setting('log_parameter_max_length_on_error')='0'")
        assert log_policy.strip()==b't';checks['postgres_secret_safe_logging_verified']=True
        object_probe=b"<?php require '/app/vendor/autoload.php';$app=require '/app/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$d=Illuminate\\Support\\Facades\\Storage::disk('local');$p='TEST DEMO synthetic storage probe';$d->put('demo-ci-encrypted-probe',Illuminate\\Support\\Facades\\Crypt::encryptString($p));$c=$d->get('demo-ci-encrypted-probe');if($c===$p||Illuminate\\Support\\Facades\\Crypt::decryptString($c)!==$p)exit(1);$d->delete('demo-ci-encrypted-probe');if($d->exists('demo-ci-encrypted-probe'))exit(2);"
        dc('exec','-T','api','php',data=object_probe)
        checks['fresh_nocopy_storage_encrypted_write_read_delete']=True
        for service in ['postgres','redis','api','web','critical','background','scheduler','mailpit']:
            info=json.loads(docker('inspect',dc('ps','-q',service).decode().strip()))[0]
            assert not info['HostConfig'].get('PortBindings')
        checks['database_redis_workers_and_mail_have_no_host_ports']=True
        dc('exec','-T','web','node','-e',"fetch('http://198.51.100.1',{signal:AbortSignal.timeout(1000)}).then(()=>process.exit(1)).catch(()=>process.exit(0))")
        checks['private_application_egress_denied_test_net_only']=True
        queue_probe=b'''<?php require '/app/vendor/autoload.php';$app=require '/app/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
Illuminate\\Support\\Facades\\Redis::del('demo-ci-critical','demo-ci-default');
dispatch(static function(){ Illuminate\\Support\\Facades\\Redis::set('demo-ci-critical','seen'); })->onQueue('critical');
dispatch(static function(){ Illuminate\\Support\\Facades\\Redis::set('demo-ci-default','seen'); })->onQueue('default');'''
        dc('exec','-T','api','sh','-c','umask 077; cat > /tmp/cts-demo-queue.php; php /tmp/cts-demo-queue.php; result=$?; rm /tmp/cts-demo-queue.php; exit "$result"',data=queue_probe)
        queue_read=b"<?php require '/app/vendor/autoload.php';$app=require '/app/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();echo json_encode([Illuminate\\Support\\Facades\\Redis::get('demo-ci-critical')==='seen',Illuminate\\Support\\Facades\\Redis::get('demo-ci-default')==='seen']);"
        deadline=time.monotonic()+45
        while not all(json.loads(dc('exec','-T','api','php',data=queue_read))):
            if time.monotonic()>deadline:raise ValueError('HORIZON_QUEUES_NOT_OBSERVED')
            time.sleep(1)
        checks['actual_critical_and_background_queue_consumption']=True
        clients={};accounts={}
        for name in ['owner','member','otherOwner','adminA','adminB']:
            clients[name],accounts[name]=register(directory,root/'ca.crt',dc,name)
        checks['five_normal_registrations_and_private_email_verifications']=True
        for name in ['owner','otherOwner']:
            accounts[name]['merchantName']='TEST DEMO '+name
            result=clients[name].request('/api/v1/merchants',{'name':accounts[name]['merchantName']},201)
            accounts[name]['merchant']=result['merchant']['id']
        clients['owner'].request('/api/v1/team/invitations',{'email':accounts['member']['email'],'role':'merchant.shipping_operator'},201)
        invitation=mail_fragment(dc,accounts['member']['email'],'invitation')
        clients['member'].request('/api/v1/auth/invitations/accept',{'token':invitation})
        clients['member'].request('/api/v1/merchants/select',{'merchant_id':accounts['owner']['merchant']})
        accounts['member']['merchantName']=accounts['owner']['merchantName']
        checks['normal_operational_invitation_acceptance']=True
        for name in ['adminA','adminB']:
            secret=clients[name].request('/api/v1/auth/mfa/enrollment',{'current_password':accounts[name]['password']},201)['secret']
            clients[name].request('/api/v1/auth/mfa/confirm',{'current_password':accounts[name]['password'],'code':otp(secret,int(time.time())//30-1)})
            accounts[name]['ciMfaSecret']=secret
        dc('exec','-T','api','php','artisan','platform:bootstrap',accounts['adminA']['id'],accounts['adminB']['id'])
        checks['normal_mfa_enrollment_and_two_user_bootstrap']=True
        owner=accounts['owner'];args=['exec','-T','api','php','artisan','demo:fixtures',owner['id'],owner['merchant']]
        one=json.loads(dc(*args));two=json.loads(dc(*args))
        assert one==two and len(set(one['shipment_ids']))==3
        checks['positive_fixtures_idempotent_three_drafts_zero_wallet']=True
        for sql in [b'SET ROLE cts_runtime; CREATE TABLE forbidden(id int);',b'SET ROLE cts_runtime; UPDATE wallet_balances SET posted=1;',b'SET ROLE cts_runtime; TRUNCATE users;']:
            try:dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_demo','-v','ON_ERROR_STOP=1',data=sql)
            except ValueError:pass
            else:raise ValueError('RUNTIME_PRIVILEGE_ESCALATION')
        balances=dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_demo','-Atc','SELECT posted||\':\'||reserved FROM wallet_balances').strip()
        assert balances==b'0:0';checks['zero_wallet_and_runtime_ddl_truncate_money_denials']=True
        try:dc('exec','-T','api','php','artisan','demo:fixtures',accounts['member']['id'],owner['merchant'])
        except ValueError:checks['non_owner_fixture_provisioning_denied']=True
        else:raise ValueError('OPERATOR_ESCALATED_FIXTURE_AUTHORITY')
        definitions=json.loads((ROOT/'infrastructure/demo/synthetic-rates.json').read_text())['definitions']
        replacements={}
        for definition in definitions:
            definition=json.loads(json.dumps(definition).replace('REPLACE_WITH_REVIEWED_DEMO_SERVICE_UUID',replacements.get('service','')).replace('REPLACE_WITH_REVIEWED_DEMO_TAX_UUID',replacements.get('tax','')))
            payload={**definition,'version':0,'effective_from':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(time.time()-3600)),
                'effective_to':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(time.time()+86400))}
            row=clients['adminA'].request('/api/v1/admin/pricing/definitions',payload,201)
            clients['adminB'].request('/api/v1/admin/pricing/definitions/'+row['id']+'/publish',{'version':row['version'],'state_version':row['state_version']})
            replacements[definition['kind']]=row['id']
        checks['synthetic_rates_normal_two_admin_publish']=True
        # Ownership/identity receipt mismatch must fail before any stop/remove.
        saved=(directory/'resources.json').read_bytes();r=receipt(directory)
        r['resources']['container:gateway']['id']=canary
        write_private(directory/'resources.json',r)
        try:owned_action(directory,'teardown')
        except ValueError:checks['forged_resource_receipt_cannot_delete_canary']=True
        else:raise ValueError('FORGED_RECEIPT_ACCEPTED')
        (directory/'resources.json').write_bytes(saved)
        dc('restart','api','web','critical','background','scheduler')
        deadline=time.monotonic()+60
        while True:
            try:gateway_probe(directory,root/'ca.crt');break
            except Exception:
                if time.monotonic()>deadline:raise
                time.sleep(2)
        capture=__import__('resources').capture;capture(directory)
        checks['application_workers_scheduler_restart_and_data_retained']=True
        crt2,key2=certificate(root,'second')
        for source,name in [(crt2,'tls.crt'),(key2,'tls.key')]:
            temporary=directory/(name+'.rotating');temporary.write_bytes(source.read_bytes());temporary.chmod(0o440);os.chown(temporary,10001,10001);temporary.replace(directory/name)
        refresh_gateway(directory,m,root/'ca.crt');gateway_probe(directory,root/'ca.crt')
        connection=ssl.create_default_context(cafile=str(root/'ca.crt')).wrap_socket(socket.create_connection(('127.0.0.1',18443)),server_hostname=HOST)
        assert hashlib.sha256(connection.getpeercert(binary_form=True)).digest()==hashlib.sha256(run(['openssl','x509','-in',str(crt2),'-outform','DER'])).digest();connection.close()
        checks['controlled_certificate_reissuance_and_gateway_recreation']=True
        old_hash=(directory/'gateway.htpasswd').read_bytes();old_access=(directory/'reviewer-access.json').read_bytes()
        revoked=BrowserSession(directory,root/'ca.crt');replacement=secrets.token_urlsafe(36)
        new_hash=run(['openssl','passwd','-6','-stdin'],input=replacement.encode()).decode().strip()
        temp=directory/'gateway.rotating';temp.write_text('reviewer:'+new_hash+'\n');temp.chmod(0o440);os.chown(temp,10001,10001);temp.replace(directory/'gateway.htpasswd')
        refresh_gateway(directory,m,root/'ca.crt');revoked.request('/en',expected=401)
        temp=directory/'gateway.rotating';temp.write_bytes(old_hash);temp.chmod(0o440);os.chown(temp,10001,10001);temp.replace(directory/'gateway.htpasswd')
        (directory/'reviewer-access.json').write_bytes(old_access);refresh_gateway(directory,m,root/'ca.crt')
        checks['actual_revoked_gateway_credential_denied']=True
        dc('restart','redis');dc('restart','critical','background')
        deadline=time.monotonic()+60
        while True:
            try:gateway_probe(directory,root/'ca.crt');break
            except Exception:
                if time.monotonic()>deadline:raise
                time.sleep(2)
        dc('exec','-T','api','sh','-c','umask 077; cat > /tmp/cts-demo-queue.php; php /tmp/cts-demo-queue.php; result=$?; rm /tmp/cts-demo-queue.php; exit "$result"',data=queue_probe)
        deadline=time.monotonic()+45
        while not all(json.loads(dc('exec','-T','api','php',data=queue_read))):
            if time.monotonic()>deadline:raise ValueError('POST_RESTART_QUEUES_NOT_OBSERVED')
            time.sleep(1)
        checks['redis_restart_and_explicit_worker_recovery']=True
        # Browser runs inside web's internal network namespace. Even a resolver
        # mistake cannot route to the real VPS. It maps canonical SNI to gateway.
        home=root/'browser-home';nss=home/'.pki/nssdb';nss.mkdir(parents=True)
        run(['certutil','-N','-d','sql:'+str(nss),'--empty-password'])
        run(['certutil','-A','-d','sql:'+str(nss),'-n','ci-demo-only','-t','C,,','-i',str(root/'ca.crt')])
        fixture=root/'browser.json';fixture.write_text(json.dumps({'gateway':json.loads((directory/'reviewer-access.json').read_text()),**accounts,'serviceId':replacements['service']}));fixture.chmod(0o600)
        web=json.loads(docker('inspect',dc('ps','-q','web').decode().strip()))[0]
        assert set(web['NetworkSettings']['Networks'])=={directory.name+'_private'}
        browser_env={'PATH':os.environ['PATH'],'HOME':str(home),'CTS_DEMO_ACK':'SYNTHETIC_ONLY_PERMANENT_DATA_LOSS_ACCEPTED',
            'PLAYWRIGHT_BROWSERS_PATH':os.environ['PLAYWRIGHT_BROWSERS_PATH']}
        browser_process=subprocess.run(['nsenter','--target',str(web['State']['Pid']),'--net','node',str(ROOT/'scripts/demo/integration-browser.cjs'),str(fixture)],
            env=browser_env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=180)
        browser=json.loads(browser_process.stdout)
        if browser_process.returncode:
            # The controlled child emits locations/static browser codes only.
            print(json.dumps(browser),flush=True)
            raise ValueError('PRIVATE_DEMO_BROWSER_ACCEPTANCE_FAILED')
        assert browser['ok'];checks['private_demo_browser_acceptance']=True
        scheduler=dc('ps','-q','scheduler').decode().strip()
        deadline=time.monotonic()+75
        while b'finance:outbox' not in docker('logs',scheduler):
            if time.monotonic()>deadline:raise ValueError('SCHEDULER_TICK_NOT_OBSERVED')
            time.sleep(2)
        checks['singleton_scheduler_actual_tick_observed']=True
        dc('restart','postgres')
        deadline=time.monotonic()+45
        while True:
            try:
                exists=dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_demo','-Atc',"SELECT to_regclass('public.users') IS NOT NULL").strip()
                break
            except ValueError:
                if time.monotonic()>deadline:raise
                time.sleep(1)
        if exists==b'f':
            BrowserSession(directory,root/'ca.crt').request('/api/v1/health/ready',expected=503)
            checks['volatile_postgres_restart_loss_fails_readiness_closed']=True
        else:
            gateway_probe(directory,root/'ca.crt');checks['postgres_restart_retained_until_teardown']=True
        # TLS/CI failure cannot prevent closing this exact instance.
        (directory/'tls.crt').unlink();owned_action(directory,'stop');owned_action(directory,'teardown')
        assert json.loads(docker('inspect',canary))[0]['State']['Running']
        assert json.loads(docker('volume','inspect',canary_volume))[0]['Name']==canary_volume
        checks['offline_no_certificate_stop_teardown_preserves_unrelated_resources']=True
        # Bound the repeatability test: earlier hosted runs exposed intermittent
        # startup failures. Every repetition uses new secrets/resources/schema.
        for attempt in range(3):
            fresh=Path('/run/cts-demo-'+secrets.token_hex(6));directories.append(fresh)
            prepare(fresh,m,crt2,key2,root/'ca.crt');start_instance(fresh,m,root/'ca.crt')
            _,fresh_dc,_=check(fresh,m,root/'ca.crt')
            count=fresh_dc('exec','-T','postgres','psql','-U','cts_owner','-d','clicktoship_demo','-Atc','SELECT count(*) FROM users').strip()
            assert count==b'0'
            owned_action(fresh,'teardown')
        checks['three_fresh_redeployments_have_no_previous_accounts']=True
    except Exception as error:
        if getattr(error,'operation',None)=='compose_up':
            values=[]
            for directory in directories:
                if (directory/'application.env').is_file():
                    env=environment(directory/'application.env')
                    values += [env[k] for k in ['APP_KEY','DB_PASSWORD','REDIS_PASSWORD']]
                    values += [env['APP_KEY'].removeprefix('base64:')]
                    for name in ['database-owner-password','gateway.htpasswd','tls.key','tls.crt']:
                        if (directory/name).is_file():values += (directory/name).read_text().splitlines()
                    values += list(json.loads((directory/'reviewer-access.json').read_text()).values())
            print(json.dumps({'code':'REDACTED_COMPOSE_UP_DIAGNOSTIC','detail':safe_compose_detail(
                (getattr(error,'stderr',None) or b'')+(getattr(error,'stdout',None) or b''),values)}),flush=True)
        detail=(getattr(error,'stderr',None) or b'').decode(errors='replace').lower()
        print(json.dumps({'code':'PRIVATE_COMMAND_DIAGNOSTIC','flags':[x for x in [
            'dependency failed to start','unhealthy','no space left on device','cannot allocate memory',
            'permission denied','not found','address already in use','already in use','failed to create task'] if x in detail],
            'unhealthy_services':sorted(set(re.findall(r'cts-demo-[a-f0-9]{12}-([a-z]+)-1[^\n]*unhealthy',detail)))}),flush=True)
        for directory in directories:
            diagnose(directory)
        raise
    finally:
        for directory in directories:
            if (directory/'resources.json').exists():
                owned_action(directory,'teardown')
            if directory.exists() and directory.resolve().parent==Path('/run') and re.fullmatch('cts-demo-[a-f0-9]{12}',directory.name):
                shutil.rmtree(directory)
        if canary:
            docker('rm','-f',canary)
        if canary_volume:
            docker('volume','rm',canary_volume)
        print(json.dumps({'code':'DEMO_IT_COMPLETED_CHECKS','checks':checks}),flush=True)
    result={'scope':'isolated_linux_demo_integration_not_host_approval','ok':True,'checks':checks,
            'browser_checks':browser['checks'],
            'git_sha':metadata['sha'],'image_content_ids':metadata['images'],
            'archive_sha256':m['archive_sha256'],
            'real_vps_accessed':False,'human_release_approval':False}
    output.write_text(json.dumps(result,indent=2)+'\n');output.chmod(0o644)
    print(json.dumps({'code':'DEMO_LIFECYCLE_INTEGRATION_PASSED','checks':len(checks)}))

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--build-only',action='store_true');p.add_argument('--directory',required=True,type=Path);p.add_argument('--output',type=Path)
    a=p.parse_args();runner_only();os.umask(0o077)
    if a.build_only:
        a.directory.mkdir(mode=0o700)
        sha=run(['git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip()
        refs,images=build_images(sha,verbose=True)
        docker('save','-o',str(a.directory/'images.tar'),*refs,timeout=600)
        (a.directory/'build.json').write_text(json.dumps({'sha':sha,'images':images}))
    else:
        exercise(a.directory,json.loads((a.directory/'build.json').read_text()),a.output)
