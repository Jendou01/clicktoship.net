import hashlib
import io
import json
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts/demo'))
from control import verify_archive
from policy import digest

class DemoArchiveTest(unittest.TestCase):
    def archive(self,path,bad_tag=False,unsafe=False):
        entries=[];images={};sha='a'*40
        with tarfile.open(path,'w') as target:
            def add(name,data):
                entry=tarfile.TarInfo(name);entry.size=len(data);target.addfile(entry,io.BytesIO(data))
            for name in ['api','web','gateway']:
                config=json.dumps({'fixture':name}).encode();value=hashlib.sha256(config).hexdigest()
                images[name]='sha256:'+value
                add(value+'.json',config);add(name+'/layer.tar',b'fixture-layer')
                entries.append({'Config':value+'.json','RepoTags':['retained:latest' if bad_tag and name=='api' else 'clicktoship-demo-'+name+':'+sha],'Layers':[name+'/layer.tar']})
            add('manifest.json',json.dumps(entries).encode())
            if unsafe:
                add('../outside',b'forbidden')
        return {'git_sha':sha,'images':images,'archive_sha256':digest(path)}

    def test_three_reviewed_configs_and_exact_tags(self):
        with tempfile.TemporaryDirectory() as work:
            path=Path(work)/'images.tar';m=self.archive(path);verify_archive(path,m)

    def test_unreviewed_mutable_tag_is_rejected_even_with_matching_archive_hash(self):
        with tempfile.TemporaryDirectory() as work:
            path=Path(work)/'images.tar';m=self.archive(path,bad_tag=True)
            with self.assertRaisesRegex(ValueError,'UNREVIEWED_ARCHIVE_IMAGE_OR_TAG'):verify_archive(path,m)

    def test_path_traversal_is_rejected_without_extracting(self):
        with tempfile.TemporaryDirectory() as work:
            path=Path(work)/'images.tar';m=self.archive(path,unsafe=True)
            with self.assertRaisesRegex(ValueError,'UNSAFE_IMAGE_ARCHIVE'):verify_archive(path,m)

if __name__=='__main__':unittest.main()
