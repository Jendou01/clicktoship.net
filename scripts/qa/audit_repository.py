#!/usr/bin/env python3
"""Bounded, offline first-party scanner. Reports paths/rule IDs, never matched secrets."""
import json
from pathlib import Path
import re
import subprocess

RULES = {
    'PRIVATE_KEY': rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',
    'GITHUB_TOKEN': rb'\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{50,})\b',
    'AWS_ACCESS_ID': rb'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b',
    'RETAINED_APP_KEY': rb'(?m)^APP_KEY=base64:[A-Za-z0-9+/]{40,}={0,2}\s*$',
}

def scan(path, data):
    findings = []
    name = Path(path).name
    if name == '.env' or name.endswith(('.pem', '.p12', '.pfx', '.sql.gz', '.dump', '.docx', '.pdf', '.zip')):
        findings.append('PRIVATE_ARTIFACT')
    for code, pattern in RULES.items():
        if re.search(pattern, data):
            findings.append(code)
    return findings

def main():
    root = Path(subprocess.check_output(['git', 'rev-parse', '--show-toplevel'], text=True).strip())
    paths = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().split('\0')
    findings = []
    for path in filter(None, paths):
        p = root / path
        if p.is_symlink() or not p.is_file():
            findings.append({'path': path, 'rule': 'UNREVIEWED_LINK_OR_MISSING_FILE'})
            continue
        if p.stat().st_size > 4 * 1024 * 1024:
            findings.append({'path': path, 'rule': 'OVERSIZED_COMMITTED_ARTIFACT'})
            continue
        findings.extend({'path': path, 'rule': rule} for rule in scan(path, p.read_bytes()))
    print(json.dumps({'tool': 'first-party bounded pattern/artifact review; not an external secret scanner', 'files': len(list(filter(None, paths))), 'findings': findings}, indent=2))
    return bool(findings)

if __name__ == '__main__':
    raise SystemExit(main())
