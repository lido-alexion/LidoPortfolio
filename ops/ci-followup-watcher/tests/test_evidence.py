import json,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
from evidence_access import ensure_logs,evidence_dir,StripAuthRedirect
import urllib.request
class EvidenceTests(unittest.TestCase):
 def setUp(self):self.run={'id':1,'head_sha':'abc','run_attempt':2};self.job={'id':3}
 def test_missing_auth_blocks_without_network(self):
  with tempfile.TemporaryDirectory() as d,patch.dict('os.environ',{},clear=True),patch('urllib.request.build_opener') as net:
   paths,missing=ensure_logs(Path(d),'owner/repo',self.run,[self.job]);self.assertEqual([3],missing);self.assertEqual([],paths);net.assert_not_called()
 def test_exact_cache_is_usable_without_credentials(self):
  with tempfile.TemporaryDirectory() as d,patch.dict('os.environ',{},clear=True):
   root=Path(d);p=evidence_dir(root,self.run);p.mkdir(parents=True);(p/'3.log').write_text('real failure');(p/'3.json').write_text(json.dumps({'run_id':1,'attempt':2,'head_sha':'abc','job_id':3}))
   self.assertEqual([],ensure_logs(root,'owner/repo',self.run,[self.job])[1])
 def test_wrong_head_cache_rejected(self):
  with tempfile.TemporaryDirectory() as d,patch.dict('os.environ',{},clear=True):
   root=Path(d);p=evidence_dir(root,self.run);p.mkdir(parents=True);(p/'3.log').write_text('wrong');(p/'3.json').write_text(json.dumps({'run_id':1,'attempt':2,'head_sha':'OLD','job_id':3}))
   self.assertEqual([3],ensure_logs(root,'owner/repo',self.run,[self.job])[1])
 def test_redirect_does_not_leak_token(self):
  request=urllib.request.Request('https://api.github.com/logs',headers={'Authorization':'Bearer SECRET'})
  new=StripAuthRedirect().redirect_request(request,None,302,'found',{},'https://blob.example/log')
  self.assertIsNone(new.get_header('Authorization'))
