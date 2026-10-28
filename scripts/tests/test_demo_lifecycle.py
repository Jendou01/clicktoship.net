import copy
import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/demo'))
import control
import resources
from policy import ACK, safe_compose_detail

class DemoLifecycleSafetyTest(unittest.TestCase):
    def test_compose_error_diagnostic_removes_private_values_before_output(self):
        secret='synthetic-private-sentinel-only'
        lines=safe_compose_detail(('progress\nError: '+secret+'\nfailed dependency').encode(),[secret])
        self.assertNotIn(secret,' '.join(lines))
        self.assertIn('[REDACTED]',' '.join(lines))
        self.assertNotIn('progress',lines)
    def test_shutdown_does_not_require_ci_or_tls(self):
        args=['control.py','stop','--directory','/run/cts-demo-123456abcdef','--ack',ACK]
        with patch.object(sys,'argv',args), patch.object(control,'protected_ram') as ram, \
             patch.object(control,'verify_target') as target, patch.object(control,'private',return_value=True), \
             patch.object(control,'approved') as approved, patch.object(control,'check') as check, \
             patch.object(control,'owned_action') as stop, patch('sys.stdout',new=io.StringIO()):
            control.main()
            ram.assert_called_once_with(Path('/run/cts-demo-123456abcdef'),operational=False)
            target.assert_called_once();stop.assert_called_once()
            approved.assert_not_called();check.assert_not_called()

    def test_wrong_target_aborts_shutdown_before_docker(self):
        args=['control.py','teardown','--directory','/run/cts-demo-123456abcdef','--ack',ACK]
        with patch.object(sys,'argv',args),patch.object(control,'protected_ram'), \
             patch.object(control,'verify_target',side_effect=ValueError('WRONG_TARGET')), \
             patch.object(control,'owned_action') as remove:
            with self.assertRaises(ValueError):control.main()
            remove.assert_not_called()

    def test_forged_receipt_cannot_stop_or_delete_anything(self):
        record={'images':{},'resources':{'container:api':{'id':'owned'}}}
        with patch.object(resources,'receipt',return_value=record), \
             patch.object(resources,'inventory',return_value={'container:api':{'id':'foreign'}}), \
             patch.object(resources,'docker') as docker:
            with self.assertRaisesRegex(ValueError,'RESOURCE_RECEIPT_MISMATCH'):
                resources.owned_action(Path('/run/cts-demo-123456abcdef'),'teardown')
            docker.assert_not_called()

    def test_teardown_targets_exact_recorded_ids_never_global_prune(self):
        actual={'container:api':{'id':'exact-container-id'},'volume:owned':{'name':'exact-volume-name'},'network:owned':{'id':'exact-network-id'}}
        record={'images':{},'resources':copy.deepcopy(actual)}
        with patch.object(resources,'receipt',return_value=record), \
             patch.object(resources,'inventory',return_value=actual), \
             patch.object(resources,'write_private'),patch.object(resources,'docker') as docker:
            resources.owned_action(Path('/run/cts-demo-123456abcdef'),'teardown')
            self.assertEqual([c.args for c in docker.call_args_list],[
                ('stop','-t','30','exact-container-id'),('rm','exact-container-id'),
                ('volume','rm','exact-volume-name'),('network','rm','exact-network-id')])

    def test_docker_context_cannot_redirect_to_a_remote_daemon(self):
        with patch.dict('os.environ',{'PATH':'test-path','DOCKER_HOST':'tcp://remote:2375','DOCKER_CONTEXT':'remote'}), \
             patch.object(resources,'run',return_value=b'') as run:
            resources.docker('ps')
            args,kwargs=run.call_args
            self.assertEqual(args[0][:3],['docker','--host','unix:///var/run/docker.sock'])
            self.assertNotIn('DOCKER_CONTEXT',kwargs['env'])
            self.assertEqual(kwargs['env']['DOCKER_HOST'],'unix:///var/run/docker.sock')

if __name__=='__main__':unittest.main()
