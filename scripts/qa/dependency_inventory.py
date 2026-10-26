#!/usr/bin/env python3
"""Offline declared-license inventory from lockfiles; not legal approval or an SBOM attestation."""
import hashlib
import json
from pathlib import Path
import sys

root=Path(__file__).resolve().parents[2]
records=[]
for filename,ecosystem in [('apps/api/composer.lock','composer'),('package-lock.json','npm')]:
    d=json.loads((root/filename).read_text())
    packages=d.get('packages',[]) if ecosystem=='composer' else d['packages'].items()
    if ecosystem=='composer':
        packages=packages+d.get('packages-dev',[])
        for p in packages:records.append({'ecosystem':ecosystem,'name':p['name'],'version':p['version'],'declared_license':p.get('license') or ['UNKNOWN_REQUIRES_REVIEW']})
    else:
        for path,p in packages:
            if not path or p.get('link'):continue
            records.append({'ecosystem':ecosystem,'name':p.get('name') or path.split('node_modules/')[-1],'version':p.get('version','UNKNOWN'),'declared_license':p.get('license') or 'UNKNOWN_REQUIRES_REVIEW'})
unique={(p['ecosystem'],p['name'],p['version']):p for p in records}
result={'scope':'declared lockfile metadata only; transitive trust/license review is not certification','lockfile_sha256':{p:hashlib.sha256((root/p).read_bytes()).hexdigest() for p in ['apps/api/composer.lock','package-lock.json']},'packages':sorted(unique.values(),key=lambda p:(p['ecosystem'],p['name'],p['version']))}
Path(sys.argv[1]).write_text(json.dumps(result,indent=2)+'\n')
print('Declared dependency versions/licenses:',len(result['packages']))
