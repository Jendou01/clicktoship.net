import importlib.util
import os
from pathlib import Path
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('deployment_preflight',ROOT/'scripts/deployment/preflight.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class DeploymentPreflightTest(unittest.TestCase):
    def fixture(self,root):
        root.chmod(0o700)
        text=(ROOT/'infrastructure/deployment/environment.example').read_text().replace('APP_KEY=','APP_KEY=base64:'+'a'*44).replace('DB_PASSWORD=','DB_PASSWORD='+'b'*64).replace('REDIS_PASSWORD=','REDIS_PASSWORD='+'c'*64).replace('REQUIRES_PRIVATE_APPROVED_SMTP','mail')
        for name in m.FILES:
            p=root/name;p.write_text(text if name=='application.env' else 'fixture-only');p.chmod(0o600)
        return ['registry.example.test/cts@sha256:'+'1'*64]*3
    def test_complete_manifest_passes_but_is_not_remote_authority(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);images=self.fixture(root)
            self.assertTrue(all(x['ok'] for x in m.inspect(root,images,'staging.example.test')))
    def test_missing_secrets_and_writable_files_fail_closed(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);images=self.fixture(root)
            (root/'tls.key').unlink();(root/'application.env').chmod(0o666)
            self.assertGreaterEqual(sum(not x['ok'] for x in m.inspect(root,images,'staging.example.test')),2)
    def test_mutable_images_wildcard_hosts_and_open_directory_fail(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);self.fixture(root);root.chmod(0o755)
            c={x['code']:x['ok'] for x in m.inspect(root,['cts:latest']*3,'*.example.test')}
            for key in ['EXACT_HOST','PINNED_RELEASE_IMAGES','SECRET_DIRECTORY_PRIVATE']:self.assertFalse(c[key])
    def test_local_ids_never_pass_the_release_image_requirement(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);self.fixture(root);ids=['sha256:'+'2'*64]*3
            self.assertFalse(next(x['ok'] for x in m.inspect(root,ids,'staging.example.test') if x['code']=='PINNED_RELEASE_IMAGES'))
            self.assertTrue(all(x['ok'] for x in m.inspect(root,ids,'staging.example.test',local=True)))
    def test_symlink_secret_and_proxy_wildcard_fail(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);images=self.fixture(root)
            p=root/'application.env';p.write_text(p.read_text().replace('TRUSTED_PROXIES=172.30.71.10','TRUSTED_PROXIES=*'))
            (root/'tls.key').unlink();(root/'tls.key').symlink_to(root/'tls.crt')
            c={x['code']:x['ok'] for x in m.inspect(root,images,'staging.example.test')}
            self.assertFalse(c['EXACT_PRIVATE_PROXY_IP']);self.assertFalse(c['SECRET_TLS_KEY'])
