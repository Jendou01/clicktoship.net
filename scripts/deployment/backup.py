#!/usr/bin/env python3
"""Encrypt a consistent custom PG dump via an explicit private connection service.
No passwords in argv; key kept separately; no retention deletion or remote upload.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile

def main():
    p=argparse.ArgumentParser()
    source=p.add_mutually_exclusive_group(required=True);source.add_argument('--service');source.add_argument('--rehearsal-container');p.add_argument('--output',required=True)
    p.add_argument('--recipient-certificate',required=True);p.add_argument('--objects',required=True)
    a=p.parse_args();certificate=Path(a.recipient_certificate);dest=Path(a.output);objects=Path(a.objects)
    if not certificate.is_file() or certificate.is_symlink():raise SystemExit('BACKUP_RECIPIENT_CERTIFICATE_REQUIRED')
    if dest.exists() or not objects.is_dir():raise SystemExit('NEW_BACKUP_AND_QUIESCED_OBJECT_DIRECTORY_REQUIRED')
    if a.service:
        for name in ('PGSERVICEFILE','PGPASSFILE'):
            path=Path(os.environ[name]) if os.environ.get(name) else None
            if name=='PGSERVICEFILE' or path:
                if not path or not path.is_file() or path.is_symlink() or stat.S_IMODE(path.stat().st_mode)&0o077:
                    raise SystemExit('PRIVATE_POSTGRES_CONNECTION_FILES_REQUIRED')
    # Operator must stop writers first and bind the service to the approved exact database.
    os.umask(0o077);dest.mkdir(parents=False)
    try:
        with tempfile.TemporaryDirectory(prefix='cts-backup-',dir=dest) as work:
            root=Path(work)
            dump=root/'database.dump'
            if a.rehearsal_container:
                if not a.rehearsal_container.startswith('cts17-'):raise RuntimeError('DISPOSABLE_CONTAINER_REQUIRED')
                prefix=['docker','exec','-i',a.rehearsal_container]
                command=prefix+['pg_dump','--username=cts_owner','--dbname=clicktoship_staging','--format=custom','--no-owner','--no-acl']
            else:
                prefix=[]
                command=['pg_dump',f'--dbname=service={a.service}','--format=custom','--no-owner','--no-acl']
            with dump.open('wb') as f:subprocess.run(command,stdout=f,check=True,timeout=600)
            with dump.open('rb') as f:subprocess.run(prefix+['pg_restore','--list'],stdin=f,stdout=subprocess.DEVNULL,check=True)
            manifest={}
            for file in objects.rglob('*'):
                if file.is_symlink():raise RuntimeError('OBJECT_SYMLINK_NOT_ALLOWED')
                if file.is_file():manifest[str(file.relative_to(objects))]=hashlib.sha256(file.read_bytes()).hexdigest()
            (root/'objects-manifest.json').write_text(json.dumps(manifest,sort_keys=True))
            subprocess.run(['tar','-cf',str(root/'objects.tar'),'-C',str(objects),'.'],check=True,timeout=600)
            for name in ('database.dump','objects.tar','objects-manifest.json'):
                subprocess.run(['openssl','cms','-encrypt','-aes-256-gcm','-binary','-outform','DER','-in',str(root/name),'-out',str(dest/(name+'.enc')),str(certificate)],check=True,timeout=600)
            hashes={f.name:hashlib.sha256(f.read_bytes()).hexdigest() for f in dest.glob('*.enc')}
            (dest/'checksums.json').write_text(json.dumps({'algorithm':'CMS AuthEnvelopedData AES-256-GCM; recipient key escrow separate','objects_count':len(manifest),'files':hashes},indent=2)+'\n')
        print(json.dumps({'code':'LOCAL_ENCRYPTED_BACKUP_CREATED','encrypted_files':3,'offsite_verified':False,'restore_verified':False}))
    except BaseException:
        # Retain partial ciphertext for operator review, never certify it as complete.
        raise
if __name__=='__main__':main()
