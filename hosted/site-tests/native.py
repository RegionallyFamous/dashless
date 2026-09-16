#!/usr/bin/env python3
"""Explicit Teddy-only acceptance orchestration. Secrets stay in ignored, mode-0600 local state."""
import argparse, base64, hashlib, importlib.util, json, os, pathlib, secrets, subprocess, tempfile, time, urllib.request, urllib.parse, uuid, zipfile
ROOT=pathlib.Path(__file__).resolve().parents[2];STATE=ROOT/'hosted/.local/site-native.json'
p=argparse.ArgumentParser();p.add_argument('action',choices=['install','status','queue','accept','cleanup','probe','report','diagnose','restart','finish-cleanup','verify-clean']);p.add_argument('--mode',choices=['empty-direct','empty-supervised','full-direct','full-serial']);p.add_argument('--apply',action='store_true');a=p.parse_args()
spec=importlib.util.spec_from_file_location('wpcloud','/Users/nick/.codex/skills/wpcloud-api/scripts/wpcloud.py');api=importlib.util.module_from_spec(spec);spec.loader.exec_module(api);api.load_dotenv(pathlib.Path('/Users/nick/Documents/Projects/wpcloud-api-tools/.env'))
opts=argparse.Namespace(client=None,dry_run=not a.apply,timeout=45,compact=True,show_secrets=False)
connections=json.loads((pathlib.Path.home()/'Library/Application Support/Dashless/connections.json').read_text());site=next(s for s in connections['sites'].values() if s.get('site_url')=='https://teddy.blog');deployment=site['deployment']
packages=json.loads((ROOT/'hosted/dist/site-packages.json').read_text())
state=json.loads(STATE.read_text()) if STATE.exists() else {'operation':str(uuid.uuid4()),'secret':secrets.token_hex(32),'site_id':152056190}
def save():
 STATE.parent.mkdir(parents=True,exist_ok=True);STATE.write_text(json.dumps(state));STATE.chmod(0o600)
def task(args):
 form=[(f'args[{i}]',v) for i,v in enumerate(args)]+[('site_run_list[0]','152056190'),('site_count_limit','1'),('cli_mode','full'),('send_webhook_for','none')]
 path='/task-create/'+api.path_part(api.client_name(opts))+'/run-wp-cli-command'
 dry=argparse.Namespace(**vars(opts));dry.dry_run=True;api.call_api(dry,'POST',path,form=form)
 if not a.apply:return None
 value=api.call_api(opts,'POST',path,form=form)['data']['task_id'];state['last_task']=str(value);save();return str(value)
def request(path,body=None):
 headers={'Authorization':'Bearer '+state['secret'],'X-Dashless-Contract':'1','Cache-Control':'no-store'}
 if body is not None:headers['Content-Type']='application/json'
 r=urllib.request.Request('https://teddy.blog/wp-json/dashless-hosted/v1'+path,headers=headers,data=None if body is None else json.dumps(body).encode())
 with urllib.request.urlopen(r,timeout=30) as response:return json.loads(response.read())
def sftp(lines):
 if not a.apply:print(json.dumps({'sftp_files':len(lines),'target':'teddy.blog','remote_scope':'mu-plugin acceptance fixture and pinned runtime'}));return
 batch='\n'.join(lines)+'\n';result=subprocess.run(['sftp','-b','-','-oBatchMode=yes','-oStrictHostKeyChecking=yes','-i',deployment['identity_file'],deployment['user']+'@'+deployment['host']],input=batch,text=True,capture_output=True,timeout=240)
 if result.returncode:raise RuntimeError('SFTP failed: '+result.stderr[-1000:])
def quote(path):return '"'+str(path).replace('\\','\\\\').replace('"','\\"')+'"'
if a.action=='install':
 if a.apply and state.get('installed'):raise SystemExit('Already installed; inspect status instead of repeating bootstrap.')
 state['packages']=packages;save() if a.apply else None
 with tempfile.TemporaryDirectory(prefix='dashless-native-install-') as tmp:
  with zipfile.ZipFile(ROOT/'hosted/dist'/packages['site']['filename']) as z:z.extractall(tmp)
  stage='/htdocs/wp-content/mu-plugins/dashless-site';lines=['-mkdir '+quote(stage),'-mkdir "/htdocs/wp-content/uploads/dashless-site-acceptance"']
  for f in sorted((pathlib.Path(tmp)/'dashless-site').rglob('*')):
   rel=f.relative_to(pathlib.Path(tmp)/'dashless-site');dest=stage+'/'+str(rel)
   lines.append(('-mkdir ' if f.is_dir() else 'put '+quote(f)+' ')+quote(dest))
  wrapper=pathlib.Path(tmp)/'zz-dashless-hosted.php';wrapper.write_text("<?php\nrequire_once __DIR__.'/dashless-site/dashless-hosted.php';\nrequire_once __DIR__.'/dashless-site/native-probe.php';\n")
  lines+=['put '+quote(ROOT/'hosted/site-tests/native-probe.php')+' '+quote(stage+'/native-probe.php'),'put '+quote(wrapper)+' "/htdocs/wp-content/mu-plugins/zz-dashless-hosted.php"']
  for package in packages['site'],packages['runtime']:lines.append('put '+quote(ROOT/'hosted/dist'/package['filename'])+' '+quote('/htdocs/wp-content/uploads/dashless-site-acceptance/'+package['filename']))
  sftp(lines)
 args=['dashless','bootstrap','--operation='+state['operation'],'--site-id=152056190','--hub=https://dashless.blog','--credential-hash='+hashlib.sha256(state['secret'].encode()).hexdigest(),'--package-sha256='+packages['site']['sha256'],'--archive=/srv/htdocs/wp-content/uploads/dashless-site-acceptance/'+packages['site']['filename'],'--runtime-archive=/srv/htdocs/wp-content/uploads/dashless-site-acceptance/'+packages['runtime']['filename'],'--preserve-content']
 print('bootstrap task',task(args));
 if a.apply:state['installed']=True;save()
elif a.action=='status':
 if state.get('last_task'):print(json.dumps(api.call_api(opts,'GET','/task-get/'+state['last_task'])))
 if a.apply:
  try:print(json.dumps(request('/capabilities')))
  except Exception as e:print(type(e).__name__)
elif a.action=='queue':
 if not a.apply:print('Create exactly two private full-blog preview jobs on Teddy; no publication.');raise SystemExit()
 jobs=[]
 for i in range(2):
  body={'actor':{'account_id':900000001},'arguments':{'client_key':state['operation']+'-native-'+str(i)}}
  result=request('/tools/create_preview',body);jobs.append(result['job_id'])
 state['jobs']=jobs;save();print(json.dumps({'site_id':152056190,'jobs':jobs}))
elif a.action=='accept':
 env=dict(os.environ);env['DASHLESS_TEST_SITE_SECRET']=state['secret'];cmd=['python3',str(ROOT/'hosted/scripts/native-acceptance.py'),'--site-id','152056190','--site-url','https://teddy.blog','--client',api.client_name(opts),'--first-job',state['jobs'][0],'--second-job',state['jobs'][1],'--output',str(ROOT/'hosted/evidence/site-native-acceptance.json')]
 if a.apply:cmd.append('--apply')
 raise SystemExit(subprocess.call(cmd,env=env))
elif a.action=='cleanup':
 # Caller must verify every acceptance task has finished before this operator reconciliation.
 print('cleanup task',task(['dashless','acceptance-cleanup','--operation='+state['operation'],'--reconcile']))
elif a.action=='diagnose':
 if not a.mode:raise SystemExit('--mode required')
 evidence=json.loads((ROOT/'hosted/evidence/site-native-acceptance.json').read_text())
 for task_id in [state['last_task']]+evidence['task_ids']:
  if not api.call_api(opts,'GET','/task-get/'+str(task_id))['data'].get('complete'):raise SystemExit('Previous task is not terminal.')
 sftp(['put '+quote(ROOT/'hosted/site-tests/native-probe.php')+' "/htdocs/wp-content/mu-plugins/dashless-site/native-probe.php"','put '+quote(ROOT/'hosted/site-tests/native-trace.mjs')+' "/htdocs/wp-content/mu-plugins/dashless-site/native-trace.mjs"'])
 print('fixed diagnostic task',task(['dashless','acceptance-diagnose',a.mode,'--operation='+state['operation']]))
elif a.action in ['probe','report']:
 if a.action=='probe':sftp(['put '+quote(ROOT/'hosted/site-tests/native-probe.php')+' "/htdocs/wp-content/mu-plugins/dashless-site/native-probe.php"'])
 if a.apply:
  r=urllib.request.Request('https://teddy.blog/wp-json/dashless-native-fixture/v1/report',headers={'Authorization':'Bearer '+state['secret'],'X-Dashless-Contract':'1'})
  with urllib.request.urlopen(r,timeout=30) as response:
   report=json.loads(response.read());print(json.dumps(report));(ROOT/'hosted/evidence'/('site-native-'+state['operation']+'-details.json')).write_text(json.dumps(report,indent=2))
elif a.action in ['restart','finish-cleanup']:
 completed=api.call_api(opts,'GET','/task-get/'+state['last_task'])['data']
 if not completed.get('complete') or completed['meta'].get('success_count')!='1' or 'acceptance-cleanup' not in completed['meta'].get('args',[]):raise SystemExit('Require a successfully completed acceptance cleanup task.')
 if a.action=='restart':
  if not a.apply:print('Archive the completed fixture state and initialize a new explicit test operation.');raise SystemExit()
  archive=STATE.with_name('site-native-'+state['operation']+'.json');archive.write_text(json.dumps(state));archive.chmod(0o600)
  evidence=ROOT/'hosted/evidence/site-native-acceptance.json'
  if evidence.exists():evidence.rename(evidence.with_name('site-native-'+state['operation']+'.json'))
  state={'operation':str(uuid.uuid4()),'secret':secrets.token_hex(32),'site_id':152056190};save();print('Fresh test state prepared; no production request dispatched.')
 else:
  histories=[state]+[json.loads(f.read_text()) for f in STATE.parent.glob('site-native-*.json')];files=set();directories=set();archives=set()
  for history in histories:
   if 'packages' not in history:continue
   for package in history['packages'].values():
    if isinstance(package,dict) and 'filename' in package:archives.add('/htdocs/wp-content/uploads/dashless-site-acceptance/'+package['filename'])
   with zipfile.ZipFile(ROOT/'hosted/dist'/history['packages']['site']['filename']) as z:
    for name in z.namelist():
     remote='/htdocs/wp-content/mu-plugins/'+name
     if not name.endswith('/'):files.add(remote)
     parent=str(pathlib.PurePosixPath(remote).parent)
     while parent.startswith('/htdocs/wp-content/mu-plugins/dashless-site'):directories.add(parent);parent=str(pathlib.PurePosixPath(parent).parent)
  files|={'/htdocs/wp-content/mu-plugins/dashless-site/native-probe.php','/htdocs/wp-content/mu-plugins/dashless-site/native-trace.mjs','/htdocs/wp-content/mu-plugins/zz-dashless-hosted.php'}
  sftp(['-rm '+quote(f) for f in sorted(files|archives)]+['-rmdir '+quote(d) for d in sorted(directories,key=len,reverse=True)]+['-rmdir "/htdocs/wp-content/uploads/dashless-site-acceptance"','-rmdir "/htdocs/wp-content/uploads/dashless-hosted-runtime"'])
  if a.apply:state['cleaned']=True;save();print('Removed only manifest-listed test packages and fixture loaders.')
elif a.action=='verify-clean':
 targets=['/htdocs/wp-content/mu-plugins/zz-dashless-hosted.php','/htdocs/wp-content/mu-plugins/dashless-site','/htdocs/wp-content/uploads/dashless-site-acceptance','/htdocs/wp-content/uploads/dashless-hosted-vault','/htdocs/wp-content/uploads/dashless-hosted-runtime'];removed={}
 for target in targets:
  result=subprocess.run(['sftp','-b','-','-oBatchMode=yes','-oStrictHostKeyChecking=yes','-i',deployment['identity_file'],deployment['user']+'@'+deployment['host']],input='ls '+quote(target)+'\n',text=True,capture_output=True,timeout=30)
  removed[target]=result.returncode!=0 and ('No such file' in result.stderr or 'not found' in result.stderr)
 checks={}
 for path in ['/','/wp-json/dashless-hosted/v1/capabilities','/wp-json/dashless-native-fixture/v1/report']:
  try:
   with urllib.request.urlopen('https://teddy.blog'+path,timeout=30) as response:checks[path]={'status':response.status,'release':response.headers.get('X-Dashless-Release'),'generation':response.headers.get('X-Dashless-Content-Generation')}
  except urllib.error.HTTPError as e:checks[path]={'status':e.code}
 resource={key:api.call_api(opts,'GET','/site-meta/152056190/'+key+'/get')['data'] for key in ['default_php_conns','php_memory_limit','burst_php_conns']}
 evidence={'site_id':152056190,'cleanup_task':state['last_task'],'removed':removed,'http':checks,'restored_meta':resource}
 evidence['passed']=all(removed.values()) and checks['/']['status']==200 and checks['/']['release']=='20260912T190537187Z-7caf64' and all(checks[path]['status']==404 for path in checks if path!='/') and resource=={'default_php_conns':'2','php_memory_limit':None,'burst_php_conns':None}
 (ROOT/'hosted/evidence/site-native-cleanup.json').write_text(json.dumps(evidence,indent=2));print(json.dumps(evidence,indent=2));raise SystemExit(0 if evidence['passed'] else 1)
