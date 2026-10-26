import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('audit', Path(__file__).parents[1] / 'qa/audit_repository.py')
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)

class SecurityAuditTest(unittest.TestCase):
    def test_detects_key_without_printing_its_value(self):
        secret = b'APP_KEY=base64:' + b'A' * 43 + b'=\n'
        self.assertEqual(['RETAINED_APP_KEY'], audit.scan('config.txt', secret))
    def test_rejects_private_attachments(self):
        self.assertEqual(['PRIVATE_ARTIFACT'], audit.scan('archive.dump', b'data'))
    def test_examples_and_runtime_secret_lookups_are_allowed(self):
        self.assertEqual([], audit.scan('.env.example', b'APP_KEY=\n'))
        self.assertEqual([], audit.scan('runtime.php', b"env('APP_KEY')"))
    def test_detects_private_keys(self):
        value = b'-----BEGIN ' + b'PRIVATE KEY-----'
        self.assertEqual(['PRIVATE_KEY'], audit.scan('unsafe.txt', value))
    def test_detects_provider_token_pattern(self):
        value = b'ghp_' + b'Q' * 36
        self.assertEqual(['GITHUB_TOKEN'], audit.scan('unsafe.txt', value))
