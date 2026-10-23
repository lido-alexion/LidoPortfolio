import tempfile, unittest
from pathlib import Path
from unittest.mock import patch
import watcher
class RootGuards(unittest.TestCase):
 def test_finished_event_never_replays(self):
  s=watcher.Supervisor({'repository':'lido-alexion/LidoPortfolio','targets':[]});s.audit=lambda *a,**k:None;s.state['events']={'1:1:a:review':{'attempts':1,'status':'finished'}}
  with patch('watcher.subprocess.Popen') as spawn:
   s.dispatch({}, {'id':1,'head_sha':'a'},None);spawn.assert_not_called()
 def test_green_pr_queues_review(self):
  s=watcher.Supervisor({'repository':'lido-alexion/LidoPortfolio','targets':[]});s.state={'events':{},'etags':{},'launches':[],'seen_runs':{}};s.audit=lambda *a,**k:None
  class GH:
   def get(self,path,*args):
    if '/pulls/' in path:return ({'state':'open','head':{'sha':'a','ref':'feat'}},True,{})
    return ({'jobs':[]},True,{})
  s.gh=GH();r={'id':1,'head_sha':'a','head_branch':'feat','event':'pull_request','status':'completed','conclusion':'success','updated_at':'new','path':'.github/workflows/ci.yml'}
  self.assertEqual([(r,None)],s.inspect_target({'name':'pr','kind':'pull_request','number':240},[r],True))
 def test_deferred_queue_survives_unchanged_poll(self):
  with tempfile.TemporaryDirectory() as td:
   s=watcher.Supervisor({'repository':'lido-alexion/LidoPortfolio','targets':[]});root=Path(td)
   for attr,name in [('lease_path','lock'),('audit_path','audit'),('heartbeat_path','heart'),('state_path','state')]:setattr(s,attr,root/name)
   s.state['pending']={'k':{'target':{},'run':{},'job':None}};s.fetch_runs=lambda:([],False)
   calls=[];s.dispatch=lambda *args:calls.append(args);s.once();self.assertEqual([],calls);self.assertIn('k',s.state['pending'])
