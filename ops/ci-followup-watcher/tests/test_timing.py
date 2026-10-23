import unittest
from watcher import poll_plan
from datetime import datetime,timezone
def ts(t):return datetime.fromtimestamp(t,timezone.utc).isoformat()
class TimingTests(unittest.TestCase):
 def setUp(self):self.targets=[{'workflow':'ci.yml','worktree':'owned'}]
 def runrow(self,id,start,status='completed',conclusion='success',duration=1800):return {'id':id,'path':'.github/workflows/ci.yml','event':'pull_request','run_started_at':ts(start),'updated_at':ts(start+duration),'status':status,'conclusion':conclusion}
 def test_initial_wait_and_near_completion(self):
  history=self.runrow(1,1000);active=self.runrow(2,5000,'in_progress',None)
  self.assertEqual(900,poll_plan([history,active],self.targets,5000)['seconds'])
  self.assertEqual(300,poll_plan([history,active],self.targets,5900)['seconds'])
 def test_idle_discovers_new_runs(self):self.assertEqual(900,poll_plan([],self.targets,5000)['seconds'])
 def test_early_failure_not_used_as_runtime(self):
  p=poll_plan([self.runrow(1,1000,conclusion='failure',duration=60),self.runrow(2,5000,'in_progress',None)],self.targets,5000)
  self.assertEqual(0,p['active_runs'][0]['history_samples']);self.assertEqual(900,p['seconds'])
 def test_queued_shorter_interval(self):self.assertEqual(300,poll_plan([self.runrow(2,5000,'queued',None)],self.targets,5000)['seconds'])
 def test_earliest_of_multiple_runs(self):self.assertEqual(300,poll_plan([self.runrow(2,5000,'in_progress',None),self.runrow(3,4000,'in_progress',None)],self.targets,5000)['seconds'])
 def test_unrelated_workflow_ignored(self):
  r=self.runrow(1,4000,'in_progress',None);r['path']='foreign'
  self.assertEqual([],poll_plan([r],self.targets,5000)['active_runs'])
