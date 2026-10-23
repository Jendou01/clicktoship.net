"""Cache public GitHub release ZIPs at locked commits without GitHub API access.

Composer 2 uses files/<package>/<sha1(canonical dist URL)>.zip. We fetch the
same GitHub archive via codeload, validate its CRC, locked root reference and
any provided SHA-1, then atomically populate that cache. Never rewrite locks,
substitute versions, disable TLS, or use credentials from repository files.
"""
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
from pathlib import Path
import re
import ssl
import sys
import tempfile
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PATTERN = re.compile(r"https://api\.github\.com/repos/([\w.-]+)/([\w.-]+)/zipball/([0-9a-f]{40})")


def validate(path, reference, checksum):
    if checksum and hashlib.sha1(path.read_bytes()).hexdigest() != checksum:
        raise ValueError("Locked artifact checksum does not match")
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        if not names or reference[:7] not in names[0].split("/")[0]:
            raise ValueError("Archive root does not match the locked commit")
        if archive.testzip() is not None:
            raise ValueError("Archive integrity check failed")


def prime(package, cache):
    dist = package.get("dist", {})
    url = dist.get("url", "")
    match = PATTERN.fullmatch(url)
    if not match:
        # Non-GitHub artifacts remain under Composer's normal verified downloader.
        return False
    owner, repo, reference = match.groups()
    if dist.get("type") != "zip" or reference != dist.get("reference"):
        raise ValueError("Unexpected locked distribution reference")
    name = package["name"]
    if not re.fullmatch(r"[a-z0-9_.-]+/[a-z0-9_.-]+", name):
        raise ValueError("Unsafe package cache path")
    target = cache / "files" / name / (hashlib.sha1(url.encode()).hexdigest() + ".zip")
    target.parent.mkdir(parents=True, exist_ok=True)
    checksum = dist.get("shasum", "")
    if target.exists():
        validate(target, reference, checksum)
        return True
    archive_url = f"https://codeload.github.com/{owner}/{repo}/legacy.zip/{reference}"
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=target.parent, delete=False) as output:
            temporary = Path(output.name)
            with urllib.request.urlopen(archive_url, context=ssl.create_default_context(), timeout=120) as response:
                if not response.url.startswith("https://codeload.github.com/"):
                    raise ValueError("Unexpected archive redirect destination")
                total = 0
                while chunk := response.read(1024 * 1024):
                    total += len(chunk)
                    if total > 256 * 1024 * 1024:
                        raise ValueError("Archive exceeds installation size limit")
                    output.write(chunk)
        validate(temporary, reference, checksum)
        os.replace(temporary, target)
        return True
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def main():
    cache = Path(sys.argv[1]).resolve()
    lock = json.loads((ROOT / "apps/api/composer.lock").read_text())
    packages = lock["packages"] + lock.get("packages-dev", [])
    with ThreadPoolExecutor(max_workers=4) as executor:
        count = sum(executor.map(lambda package: prime(package, cache), packages))
    print(f"Verified {count} locked GitHub archives in Composer cache")


if __name__ == "__main__":
    main()
