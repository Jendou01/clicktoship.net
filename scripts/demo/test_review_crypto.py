#!/usr/bin/env python3
"""Linux CI crypto integration, ephemeral test key only; never a release approval."""
import json
import tempfile
from pathlib import Path
from policy import digest, run, verify_review

def main():
    with tempfile.TemporaryDirectory(prefix='cts-demo-review-test-') as work:
        root=Path(work);root.chmod(0o700)
        private=root/'ephemeral-test.key';public=root/'review.pub'
        manifest=root/'manifest.json';signature=root/'manifest.sig'
        run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:3072','-out',str(private)])
        private.chmod(0o600)
        run(['openssl','pkey','-in',str(private),'-pubout','-out',str(public)])
        record={'schema':1,'profile':'disposable-remote-demo','git_sha':'a'*40,'ci_run_id':1,'reviewers':['test-reviewer-one','test-reviewer-two'],'archive_sha256':'b'*64,'images':{name:'sha256:'+value*64 for name,value in [('api','1'),('web','2'),('gateway','3')]}}
        manifest.write_text(json.dumps(record,sort_keys=True))
        run(['openssl','dgst','-sha256','-sign',str(private),'-out',str(signature),str(manifest)])
        assert verify_review(manifest,signature,public,digest(public))==record
        original=manifest.read_bytes();manifest.write_bytes(original+b' ')
        try:
            verify_review(manifest,signature,public,digest(public))
        except ValueError:
            pass
        else:
            raise RuntimeError('TAMPERED_MANIFEST_ACCEPTED')
        manifest.write_bytes(original)
        try:
            verify_review(manifest,signature,public,'0'*64)
        except ValueError:
            pass
        else:
            raise RuntimeError('UNPINNED_REVIEW_KEY_ACCEPTED')
    print('DEMO_REVIEW_SIGNATURE_AND_TAMPER_REJECTION_PASSED_EPHEMERAL_TEST_ONLY')

if __name__=='__main__':
    main()
