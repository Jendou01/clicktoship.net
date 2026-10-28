#!/usr/bin/env python3
"""Explicit packaging only. No deployment, push, signing key or trust fabrication."""
import argparse
import json
import re
import subprocess
from pathlib import Path
from policy import digest, run

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--sha',required=True);p.add_argument('--ci-run-id',required=True,type=int)
    p.add_argument('--reviewers',required=True,nargs=2);p.add_argument('--output',required=True,type=Path)
    a=p.parse_args()
    if not re.fullmatch('[a-f0-9]{40}',a.sha) or a.ci_run_id<=0 or len(set(a.reviewers))!=2 or not all(re.fullmatch('[A-Za-z0-9_-]{1,64}',x) for x in a.reviewers):
        raise ValueError('EXACT_RELEASE_AND_REVIEWERS_REQUIRED')
    if run(['git','rev-parse','HEAD']).decode().strip()!=a.sha or run(['git','status','--porcelain']).strip() or a.output.exists():
        raise ValueError('CLEAN_EXACT_CHECKOUT_AND_NEW_OUTPUT_REQUIRED')
    # Build labels + digest review is a human-reviewed integrity policy, not
    # signed build provenance. CI and signature are verified again on the host.
    refs=[];images={}
    for service in ['api','web','gateway']:
        file=f'infrastructure/{"demo" if service=="web" else "deployment"}/{service}.Dockerfile'
        ref=f'clicktoship-demo-{service}:{a.sha}'
        run(['docker','build','--build-arg','RELEASE_SHA='+a.sha,'-f',file,'-t',ref,'.'],timeout=2400)
        images[service]=run(['docker','image','inspect',ref,'--format','{{.Id}}']).decode().strip()
        refs.append(ref)
    a.output.mkdir(mode=0o700,parents=False)
    archive=a.output/'images.tar'
    run(['docker','save','-o',str(archive),*refs],timeout=600);archive.chmod(0o600)
    manifest={'schema':1,'profile':'disposable-remote-demo','git_sha':a.sha,'ci_run_id':a.ci_run_id,'reviewers':a.reviewers,'archive_sha256':digest(archive),'images':images}
    (a.output/'manifest.json').write_text(json.dumps(manifest,sort_keys=True,indent=2)+'\n')
    print(json.dumps({'code':'DEMO_UNSIGNED_CANDIDATE_PACKAGED','approval':False,'deployment':False}))

if __name__=='__main__':
    main()
