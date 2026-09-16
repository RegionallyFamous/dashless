#!/usr/bin/env python3
"""Run after the site plugin is ready. Explicit single site, two pre-created full-build jobs, three uncached authenticated users.
Secrets: DASHLESS_TEST_SITE_SECRET and WPCLOUD_API_KEY. No secret URLs/response bodies are saved.
"""
import argparse, concurrent.futures, json, math, os, statistics, threading, time, urllib.error, urllib.parse, urllib.request, uuid
from pathlib import Path
from native_memory import assess_memory
p=argparse.ArgumentParser();p.add_argument('--site-id',type=int,required=True);p.add_argument('--site-url',required=True);p.add_argument('--client',required=True);p.add_argument('--first-job',required=True);p.add_argument('--second-job',required=True);p.add_argument('--output',default='native-acceptance.json');p.add_argument('--apply',action='store_true');a=p.parse_args()
if a.site_id<1 or urllib.parse.urlparse(a.site_url).scheme!='https':p.error('Positive explicit atomic ID and HTTPS site URL required.')
for job in [a.first_job,a.second_job]:uuid.UUID(job)
if a.first_job==a.second_job:p.error('Two distinct persisted jobs required.')
forms={'args[0]':'dashless','args[1]':'build-job','args[2]':a.first_job,'site_run_list[0]':str(a.site_id),'site_count_limit':'1','cli_mode':'full','send_webhook_for':'none'}
if not a.apply:print(json.dumps({'target':a.site_id,'first_task':forms,'second_job':a.second_job,'note':'No requests made; repeat with --apply after fixture and entitlement checks.'},indent=2));raise SystemExit()
key=os.environ['WPCLOUD_API_KEY'];secret=os.environ['DASHLESS_TEST_SITE_SECRET'];samples=[];stop=threading.Event();started=time.monotonic()
def request(url,headers,body=None):
 req=urllib.request.Request(url,data=body,headers=headers)
 with urllib.request.urlopen(req,timeout=15) as r:return r.status,dict(r.headers),r.read()
def api(path,form=None):
 _,_,body=request('https://atomic-api.wordpress.com/api/v1.0/'+path,{'auth':key,'Content-Type':'application/x-www-form-urlencoded'},urllib.parse.urlencode(form).encode() if form else None)
 return json.loads(body)['data']
def site(path):
 _,_,body=request(a.site_url.rstrip('/')+'/wp-json/dashless-hosted/v1'+path,{'Authorization':'Bearer '+secret,'X-Dashless-Contract':'1','Cache-Control':'no-store'})
 d=json.loads(body)
 if d.get('contract_version')!=1 or int(d.get('site_id',0))!=a.site_id:raise RuntimeError('Site identity mismatch')
 return d
caps=site('/capabilities')
if not caps.get('capabilities',{}).get('runtime'):raise SystemExit('Runtime capability is not ready.')
for job in [a.first_job,a.second_job]:
 if site('/jobs/'+job)['status']!='queued':raise SystemExit('Both jobs must start queued, created from the same Teddy-sized snapshot.')
# The site plugin must implement this authenticated diagnostic as real uncached front-page rendering, not a cheap health check.
probe=a.site_url.rstrip('/')+'/wp-json/dashless-hosted/v1/diagnostics/uncached-page'
def user(number):
 while not stop.is_set():
  t=time.monotonic();status=0;uncached=False
  try:
   status,h,_=request(probe+'?request='+str(uuid.uuid4()),{'Authorization':'Bearer '+secret,'X-Dashless-Contract':'1','Cache-Control':'no-store'})
   uncached=any(k.lower()=='x-dashless-cache' and v=='bypass' for k,v in h.items())
  except urllib.error.HTTPError as e:status=e.code
  except Exception:pass
  samples.append({'user':number,'seconds':time.monotonic()-t,'status':status,'uncached':uncached,'offset':t-started});stop.wait(.25)
task_ids=[];jobs={};failure=None
try:
 with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
  workers=[pool.submit(user,i) for i in range(3)]
  try:
   # Dispatch first; the second remains persisted while the first task executes.
   first=api('task-create/'+urllib.parse.quote(a.client)+'/run-wp-cli-command',forms)['task_id'];task_ids.append(first)
   for _ in range(65):
    task=api('task-get/'+str(first));jobs['first']=site('/jobs/'+a.first_job)
    if task.get('complete'):break
    if site('/jobs/'+a.second_job)['status']!='queued':raise RuntimeError('Second job started concurrently')
    time.sleep(4)
   if not task.get('complete') or jobs['first']['status']!='succeeded':raise RuntimeError('First full build failed or exceeded deadline')
   forms['args[2]']=a.second_job;second=api('task-create/'+urllib.parse.quote(a.client)+'/run-wp-cli-command',forms)['task_id'];task_ids.append(second)
   for _ in range(65):
    task=api('task-get/'+str(second));jobs['second']=site('/jobs/'+a.second_job)
    if task.get('complete'):break
    time.sleep(4)
   if not task.get('complete') or jobs['second']['status']!='succeeded':raise RuntimeError('Second full build failed or exceeded deadline')
  finally:stop.set()
except Exception as e:failure=type(e).__name__+': '+str(e)
latencies=sorted(r['seconds'] for r in samples);p95=latencies[max(0,math.ceil(len(latencies)*.95)-1)] if latencies else None
responsiveness_passed=len(samples)>=60 and p95<2 and all(r['status']==200 and r['uncached'] for r in samples)
memory_checks={name:assess_memory(jobs.get(name,{})) for name in ['first','second']}
memory_passed=all(check['passed'] for check in memory_checks.values())
passed=not failure and responsiveness_passed and memory_passed
result={'site_id':a.site_id,'task_ids':task_ids,'passed':passed,'responsiveness_passed':responsiveness_passed,'memory_passed':memory_passed,'memory_checks':memory_checks,'p95_seconds':p95,'samples':samples,'failure':failure,'job_metrics':{k:{x:v.get(x) for x in ['status','peak_memory_bytes','memory_method','runtime_sha256','snapshot_generation','release_id']} for k,v in jobs.items()},'note':'Confirm diagnostics render the actual uncached public page; confirm both jobs use a full Teddy-sized snapshot. Missing, zero, partial-process, or unknown memory measurements cannot pass the whole-task memory gate. Task pricing and Hub capacity need separate evidence.'}
Path(a.output).write_text(json.dumps(result,indent=2));print(json.dumps({k:v for k,v in result.items() if k not in ['samples','job_metrics']},indent=2));raise SystemExit(0 if passed else 1)
