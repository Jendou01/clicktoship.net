"""The installer must reject archives that fail the locked artifact checks."""
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
import zipfile

spec = importlib.util.spec_from_file_location(
    "archive_cache", Path(__file__).parents[1] / "prime-composer-cache.py"
)
cache = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cache)


class ArchiveIntegrityTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.archive = Path(self.directory.name) / "package.zip"
        self.reference = "a" * 40
        with zipfile.ZipFile(self.archive, "w") as output:
            output.writestr("owner-repo-aaaaaaa/composer.json", "{}")

    def test_valid_locked_archive_passes_checksum_and_reference_checks(self):
        digest = hashlib.sha1(self.archive.read_bytes()).hexdigest()
        cache.validate(self.archive, self.reference, digest)

    def test_unexpected_locked_checksum_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "checksum"):
            cache.validate(self.archive, self.reference, "0" * 40)

    def test_different_commit_archive_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "locked commit"):
            cache.validate(self.archive, "b" * 40, "")

    def test_corrupt_archive_is_rejected(self):
        self.archive.write_bytes(b"not an archive")
        with self.assertRaises(zipfile.BadZipFile):
            cache.validate(self.archive, self.reference, "")


if __name__ == "__main__":
    unittest.main()
