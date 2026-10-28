import copy
import importlib.util
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/demo'))
import policy
import control

class DemoPolicyTest(unittest.TestCase):
    def env(self):
        text=(ROOT/'infrastructure/demo/application.env.example').read_text().replace('=INSTANCE','=cts-demo-'+'1'*12)
        text=text.replace('APP_KEY=','APP_KEY=base64:'+'A'*43+'=').replace('DB_PASSWORD=','DB_PASSWORD='+'b'*64).replace('REDIS_PASSWORD=','REDIS_PASSWORD='+'c'*64)
        return dict(line.split('=',1) for line in text.splitlines() if line and not line.startswith('#'))

    def test_exact_demo_env_and_injected_credentials(self):
        e=self.env();self.assertTrue(policy.validate_env(e,'cts-demo-'+'1'*12))
        for key,value in [('APP_ENV','production'),('APP_DEBUG','true'),('DB_DATABASE','clicktoship_staging'),('DB_HOST','2.24.48.221'),('TRUSTED_PROXIES','*'),('MAIL_HOST','smtp.example.com'),('CTS_DEMO_ACK','yes'),('SESSION_ENCRYPT','false'),('CTS_DISPOSABLE_DEMO','false'),('APP_ALLOWED_HOSTS','*.zeeship.cloud')]:
            with self.subTest(key=key):
                changed={**e,key:value};self.assertFalse(policy.validate_env(changed,'cts-demo-'+'1'*12))
        for key in ['DB_URL','REDIS_URL','ARМEX_PASSWORD','AWS_SECRET_ACCESS_KEY','MAIL_URL','SESSION_DOMAIN']:
            self.assertFalse(policy.validate_env({**e,key:'injected'},'cts-demo-'+'1'*12))
        self.assertFalse(policy.validate_env({**e,'REDIS_PASSWORD':e['DB_PASSWORD']},'cts-demo-'+'1'*12))

    def rendered(self):
        image='sha256:'+'a'*64
        c={'name':'cts-demo-'+'1'*12,'services':{},'networks':{'ingress':{},'private':{'internal':True,'ipam':{'config':[{'subnet':'172.30.73.0/24'}]}}},'volumes':{n:{'driver':'local','driver_opts':{'type':'tmpfs','device':'tmpfs','o':'nosuid,nodev'}} for n in ['database_data','redis_data','private_data']}}
        images={**control.FIXED,'api':image,'web':image,'gateway':image}
        for name in policy.SERVICES:
            c['services'][name]={'image':images['api' if name in {'api','migrate','critical','background','scheduler'} else name],'networks':{'private':{}},'read_only':True,'cap_drop':['ALL'],'user':'10001:10001'}
        c['services']['gateway'].update(networks={'private':{'ipv4_address':'172.30.73.10'},'ingress':{}},ports=[{'host_ip':'127.0.0.1','published':'18443','target':8443,'protocol':'tcp'}])
        return c,images

    def test_network_and_retained_volume_attacks(self):
        c,images=self.rendered();self.assertTrue(policy.validate_compose(c,c['name'],images))
        attacks=[lambda x:x['services']['postgres'].update(ports=[{'published':'5432'}]),lambda x:x['services']['api'].update(networks={'ingress':{}}),lambda x:x['services']['api'].update(volumes=[{'type':'bind','source':'/','target':'/host'}]),lambda x:x['services']['api'].update(privileged=True),lambda x:x['networks']['private'].update(internal=False),lambda x:x['volumes']['database_data'].update(external=True),lambda x:x['volumes']['database_data']['driver_opts'].update(type='none'),lambda x:x['services']['gateway']['ports'][0].update(host_ip='0.0.0.0'),lambda x:x['services']['web'].update(image='latest')]
        for attack in attacks:
            changed=copy.deepcopy(c);attack(changed)
            self.assertFalse(policy.validate_compose(changed,c['name'],images))

    def test_public_ingress_cannot_appear_in_private_start(self):
        c,images=self.rendered()
        c['services']['gateway']['ports'][0].update(host_ip='2.24.48.221',published='443')
        self.assertFalse(policy.validate_compose(c,c['name'],images))
        self.assertTrue(policy.validate_compose(c,c['name'],images,public=True))

    def test_production_preflight_has_no_demo_exception(self):
        source=(ROOT/'scripts/deployment/preflight.py').read_text()
        self.assertNotIn('CTS_DISPOSABLE_DEMO',source)
        self.assertIn("Local `--local-image-ids`", (ROOT/'docs/deployment/SECURITY_PREFLIGHT.md').read_text())

    def test_review_key_must_be_pinned_before_signature_execution(self):
        with patch.object(policy,'run') as mocked:
            with self.assertRaises(ValueError):
                policy.verify_review('unused','unused','unused','not-a-fingerprint')
            mocked.assert_not_called()

    def test_controller_rejects_non_run_paths_before_operating(self):
        with self.assertRaises(ValueError):
            control.protected_ram(Path('/etc/clicktoship-staging'))

if __name__=='__main__':
    unittest.main()
