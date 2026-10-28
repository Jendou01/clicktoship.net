#!/usr/bin/env python3
"""Explicit packaging only. No deployment, push, signing key or trust fabrication."""
import argparse
import json
import re
import subprocess
import tempfile
import tarfile
import io
from pathlib import Path
from policy import digest, run
from resources import docker, docker_env, SOCKET

ROOT=Path(__file__).resolve().parents[2]

def build_images(sha, verbose=False):
    if run(['git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip()!=sha or run(['git','-C',str(ROOT),'status','--porcelain']).strip():
        raise ValueError('CLEAN_EXACT_CHECKOUT_REQUIRED')
    refs=[];images={}
    # Only tracked commit bytes enter the context. Ignored .env/cache/fixtures
    # cannot leak through COPY even if the operator's checkout contains them.
    with tempfile.TemporaryDirectory(prefix='cts-demo-build-') as work:
        context=Path(work);context.chmod(0o700)
        data=run(['git','-C',str(ROOT),'archive','--format=tar',sha])
        with tarfile.open(fileobj=io.BytesIO(data)) as source:
            if any(not (entry.isfile() or entry.isdir()) for entry in source.getmembers()):
                raise ValueError('UNREVIEWED_SOURCE_LINK')
            source.extractall(context,filter='data')
        for service in ['api','web','gateway']:
            file=context/f'infrastructure/{"demo" if service=="web" else "deployment"}/{service}.Dockerfile'
            ref=f'clicktoship-demo-{service}:{sha}'
            args=['build','--build-arg','RELEASE_SHA='+sha,'-f',str(file),'-t',ref,str(context)]
            if verbose:
                # CI build context contains tracked source only; runtime/test
                # credentials do not exist yet. Build diagnostics are safe here.
                subprocess.run(['docker','--host',SOCKET,*args],env=docker_env(),check=True,timeout=2400)
            else:
                docker(*args,timeout=2400)
            images[service]=docker('image','inspect',ref,'--format','{{.Id}}').decode().strip()
            refs.append(ref)
    return refs,images

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--sha',required=True);p.add_argument('--ci-run-id',required=True,type=int)
    p.add_argument('--reviewers',required=True,nargs=2);p.add_argument('--output',required=True,type=Path)
    a=p.parse_args()
    if not re.fullmatch('[a-f0-9]{40}',a.sha) or a.ci_run_id<=0 or len(set(a.reviewers))!=2 or not all(re.fullmatch('[A-Za-z0-9_-]{1,64}',x) for x in a.reviewers):
        raise ValueError('EXACT_RELEASE_AND_REVIEWERS_REQUIRED')
    if a.output.exists():
        raise ValueError('CLEAN_EXACT_CHECKOUT_AND_NEW_OUTPUT_REQUIRED')
    # Build labels + digest review is a human-reviewed integrity policy, not
    # signed build provenance. CI and signature are verified again on the host.
    refs,images=build_images(a.sha)
    a.output.mkdir(mode=0o700,parents=False)
    archive=a.output/'images.tar'
    docker('save','-o',str(archive),*refs,timeout=600);archive.chmod(0o600)
    manifest={'schema':1,'profile':'disposable-remote-demo','git_sha':a.sha,'ci_run_id':a.ci_run_id,'reviewers':a.reviewers,'archive_sha256':digest(archive),'images':images}
    (a.output/'manifest.json').write_text(json.dumps(manifest,sort_keys=True,indent=2)+'\n')
    print(json.dumps({'code':'DEMO_UNSIGNED_CANDIDATE_PACKAGED','approval':False,'deployment':False}))

if __name__=='__main__':
    main()
