#!/usr/bin/env python3
"""Disposable cluster restore/fault rehearsal; never restores into normal PostgreSQL."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
COMPOSE = ['docker', 'compose', '-f', 'docker-compose.yml', '-f', 'infrastructure/docker/compose.e2e.yml']

def run(args, data=None, timeout=60, check=True):
    return subprocess.run(args, input=data, cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout, check=check)

def wait_ready(args):
    deadline = time.monotonic()+30
    while time.monotonic()<deadline:
        if run(args, timeout=5, check=False).returncode == 0:
            return
        time.sleep(.2)  # bounded readiness polling, not a workflow delay
    raise RuntimeError('QA_DEPENDENCY_NOT_READY')

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--output', required=True)
    a=parser.parse_args()
    # Refuse to interrupt a normal runtime; this command must start on disposable E2E.
    guard=json.loads(run(COMPOSE+['exec','-T','api','php','tests/Fixtures/phase16-state.php','guard']).stdout)
    if guard != {'environment':'testing','database':'clicktoship_e2e'}:
        raise RuntimeError('QA_E2E_SOURCE_REQUIRED')
    suffix=uuid.uuid4().hex[:10];net='cts-qa-'+suffix;pg='cts-restore-'+suffix;redis='cts-redis-'+suffix
    created=[];results={'scope':'disposable internal Docker network / synthetic E2E source only','production_dr_ready':False}
    started=time.monotonic()
    def api(command):
        return ['docker','run','--rm','-i','--network',net,'-v',str(ROOT/'apps/api')+':/app','-e','APP_ENV=testing','-e','APP_DEBUG=false','-e','DB_HOST='+pg,'-e','DB_DATABASE=clicktoship_restore_test','-e','DB_USERNAME=clicktoship','-e','DB_PASSWORD=','-e','DB_URL=','-e','REDIS_URL=','-e','REDIS_HOST='+redis,'-e','REDIS_PREFIX=phase16_restore_','clicktoship-api:dev']+command
    def sql(text):
        return run(['docker','exec','-i',pg,'psql','-U','clicktoship','-d','clicktoship_restore_test','-At','-v','ON_ERROR_STOP=1'],text.encode()).stdout.decode().strip()
    try:
        run(COMPOSE+['stop','api','worker','scheduler'])
        run(COMPOSE+['run','--rm','--no-deps','-T','api','php','tests/Fixtures/phase16-state.php','seed'])
        source=json.loads(run(COMPOSE+['run','--rm','--no-deps','-T','api','php','tests/Fixtures/phase16-state.php']).stdout)
        run(['docker','network','create','--internal',net]);created.append(('network',net))
        pg_image=run(['docker','image','inspect','postgres:17-bookworm','--format','{{index .RepoDigests 0}}']).stdout.decode().strip()
        redis_image=run(['docker','image','inspect','redis:7-bookworm','--format','{{index .RepoDigests 0}}']).stdout.decode().strip()
        run(['docker','run','-d','--name',pg,'--network',net,'-e','POSTGRES_USER=clicktoship','-e','POSTGRES_DB=clicktoship_restore_test','-e','POSTGRES_HOST_AUTH_METHOD=trust',pg_image]);created.append(('container',pg))
        run(['docker','run','-d','--name',redis,'--network',net,redis_image]);created.append(('container',redis))
        wait_ready(['docker','exec',pg,'pg_isready','-U','clicktoship'])
        wait_ready(['docker','exec',redis,'redis-cli','ping'])
        results['images']={'postgres':pg_image,'redis':redis_image}
        run(api(['php','artisan','migrate','--force','--no-interaction']),timeout=120)
        run(api(['php','artisan','schema:verify']))
        results['fresh_migrations_schema']=True
        with tempfile.TemporaryDirectory(prefix='clicktoship-qa-restore-') as directory:
            dump=Path(directory)/'synthetic.dump'
            dump.write_bytes(run(['docker','compose','exec','-T','postgres','pg_dump','-U','clicktoship','-d','clicktoship_e2e','-Fc']).stdout);dump.chmod(0o600)
            data=dump.read_bytes();results['backup_bytes']=len(data);results['backup_sha256']=hashlib.sha256(data).hexdigest()
            restore_started=time.monotonic()
            run(['docker','exec','-i',pg,'pg_restore','-U','clicktoship','-d','clicktoship_restore_test','--clean','--if-exists','--exit-on-error'],data,timeout=120)
            restored=json.loads(run(api(['php','tests/Fixtures/phase16-state.php'])).stdout)
            if source != restored:raise RuntimeError('QA_RESTORED_MANIFEST_MISMATCH')
            results.update({'tables_verified':len(source['tables']),'rows_verified':sum(t['rows'] for t in source['tables'].values()),'row_fingerprints_match':True,'decrypted_samples':restored['decrypted_samples'],'wrong_key_rejected':restored['wrong_key_rejected'],'balanced_ledger':restored['balanced_ledger'],'restore_and_verify_seconds':round(time.monotonic()-restore_started,3)})
        # Private synthetic encrypted file backup/restore; never an approved export.
        import shutil
        program="require 'vendor/autoload.php'; $a=require 'bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo Illuminate\\Support\\Facades\\Crypt::encryptString('QA_SYNTHETIC_STORAGE_ONLY');"
        cipher=run(api(['php','-r',program])).stdout
        with tempfile.TemporaryDirectory(prefix='clicktoship-qa-object-') as directory:
            source_path=Path(directory)/'source.enc';source_path.write_bytes(cipher);source_path.chmod(0o600)
            backup_path=Path(directory)/'backup.enc';restored_path=Path(directory)/'restored.enc'
            shutil.copyfile(source_path,backup_path);backup_path.chmod(0o600)
            shutil.copyfile(backup_path,restored_path);restored_path.chmod(0o600)
            if hashlib.sha256(source_path.read_bytes()).digest()!=hashlib.sha256(restored_path.read_bytes()).digest():raise RuntimeError('QA_STORAGE_MANIFEST_MISMATCH')
            check="require 'vendor/autoload.php'; $a=require 'bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo Illuminate\\Support\\Facades\\Crypt::decryptString(file_get_contents('php://stdin'))==='QA_SYNTHETIC_STORAGE_ONLY'?'ok':'bad';"
            if run(api(['php','-r',check]),restored_path.read_bytes()).stdout!=b'ok':raise RuntimeError('QA_STORAGE_KEY_ROUNDTRIP_FAILED')
        results['synthetic_object_backup_hash_and_decryption']=True
        # Terminate an exact isolated transaction; uncommitted data must roll back.
        sql('CREATE TABLE qa_rollback_probe(value integer);')
        process=subprocess.Popen(['docker','exec','-i',pg,'psql','-U','clicktoship','-d','clicktoship_restore_test','-v','ON_ERROR_STOP=1'],stdin=subprocess.PIPE,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        process.stdin.write(b"SET application_name='phase16_rollback_probe'; BEGIN; INSERT INTO qa_rollback_probe VALUES(1); SELECT pg_sleep(30); COMMIT;\n");process.stdin.close()
        deadline=time.monotonic()+10;pid=''
        while time.monotonic()<deadline:
            pid=sql("SELECT pid FROM pg_stat_activity WHERE application_name='phase16_rollback_probe' AND wait_event='PgSleep'")
            if pid:break
            time.sleep(.05)
        if not pid.isdigit():process.kill();raise RuntimeError('QA_TRANSACTION_BARRIER_NOT_REACHED')
        sql('SELECT pg_terminate_backend('+pid+');');process.wait(timeout=10)
        if sql('SELECT count(*) FROM qa_rollback_probe') != '0':raise RuntimeError('QA_ROLLBACK_FAILED')
        sql('DROP TABLE qa_rollback_probe;');results['terminated_transaction_rolled_back']=True
        # Fault only the private dependencies, never normal Redis/PostgreSQL.
        for name,ready in [(redis,['docker','exec',redis,'redis-cli','ping']),(pg,['docker','exec',pg,'pg_isready','-U','clicktoship'])]:
            run(['docker','stop','-t','1',name])
            failed=json.loads(run(api(['php','tests/Fixtures/phase16-state.php','health']),timeout=30).stdout)
            if failed['status']!=503:raise RuntimeError('QA_OUTAGE_NOT_FAIL_CLOSED')
            run(['docker','start',name]);wait_ready(ready)
            healthy=json.loads(run(api(['php','tests/Fixtures/phase16-state.php','health'])).stdout)
            if healthy['status']!=200:raise RuntimeError('QA_DEPENDENCY_DID_NOT_RECOVER')
        results['redis_and_postgres_outage_503_then_200']=True
        if json.loads(run(api(['php','tests/Fixtures/phase16-state.php'])).stdout)!=source:raise RuntimeError('QA_DURABLE_STATE_CHANGED_AFTER_OUTAGE')
        results['durable_state_unchanged_after_outages']=True
        results['rehearsal_seconds']=round(time.monotonic()-started,3)
        Path(a.output).write_text(json.dumps(results,indent=2)+'\n');print(json.dumps(results,indent=2))
    finally:
        for kind,name in reversed(created):
            run(['docker','rm','-f','-v',name] if kind=='container' else ['docker','network','rm',name],check=False)
        # Restore normal development profile, without migrations/resets or queue flushing.
        run(['bash','scripts/start.sh'],timeout=180)

if __name__=='__main__':main()
