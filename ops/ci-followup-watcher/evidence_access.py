"""Exact-run evidence cache and least-scope optional GitHub Actions log download."""
import json, os, urllib.request, urllib.parse
from pathlib import Path
class StripAuthRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self, req, fp, code, msg, headers, newurl):
  result=super().redirect_request(req,fp,code,msg,headers,newurl)
  if result and urllib.parse.urlsplit(req.full_url).netloc != urllib.parse.urlsplit(newurl).netloc:
   result.remove_header('Authorization')
  return result

def evidence_dir(root,run):return root/'evidence'/f"{run['id']}_{run.get('run_attempt',1)}_{run['head_sha']}"

def ensure_logs(root,repo,run,jobs):
 folder=evidence_dir(root,run);folder.mkdir(parents=True,exist_ok=True)
 paths=[];missing=[]
 token=os.environ.get('GH_TOKEN') or os.environ.get('GITHUB_TOKEN')
 tokenpath=root/'actions-read-token'
 if not token and tokenpath.exists():
  if tokenpath.stat().st_mode & 0o077:raise ValueError('actions-read-token must have mode0600 or stricter')
  token=tokenpath.read_text().strip()
 for job in jobs:
  path=folder/f"{job['id']}.log";meta=path.with_suffix('.json')
  expected={'run_id':run['id'],'attempt':run.get('run_attempt',1),'head_sha':run['head_sha'],'job_id':job['id']}
  cached=False
  if path.exists() and meta.exists():
   got=json.loads(meta.read_text());cached=all(got.get(k)==v for k,v in expected.items()) and path.stat().st_size>0
  if not cached:
   if not token:missing.append(job['id']);continue
   req=urllib.request.Request(f"https://api.github.com/repos/{repo}/actions/jobs/{job['id']}/logs",headers={'Authorization':f'Bearer {token}','User-Agent':'stox-ci-evidence','Accept':'application/vnd.github+json'})
   with urllib.request.build_opener(StripAuthRedirect()).open(req,timeout=30) as response:
    raw=response.read(10_000_001)
   if len(raw)>10_000_000:raise ValueError('job log exceeds10MB evidence limit')
   text=raw.decode('utf-8-sig',errors='replace')
   if not text.strip():raise ValueError('empty job log')
   tmp=path.with_suffix('.tmp');tmp.write_text(text);tmp.replace(path)
   meta.write_text(json.dumps({**expected,'source':'GitHub Actions authenticated log API','is_excerpt':False},indent=2))
  paths.append(path)
 return paths,missing
