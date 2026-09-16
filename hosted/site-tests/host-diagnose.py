#!/usr/bin/env python3
"""Fixed Teddy-only hosting checks. Never publishes or reads customer content."""
import argparse, hashlib, importlib.util, json, pathlib, secrets, subprocess, tempfile, time, urllib.request, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
STATE = ROOT / 'hosted/.local/host-diagnostic.json'
MODES = ['node-basic', 'timer', 'cpu', 'astro-import', 'rolldown-import', 'transform', 'astro-minimal', 'transform-js', 'astro-setup', 'astro-traced', 'astro-plain', 'astro-cold', 'shared-empty', 'host-limits', 'astro-cold-sampled', 'astro-cold-cpu-one', 'shared-cpu-one', 'astro-cold-again-cpu-one', 'astro-cold-control', 'shared-again-cpu-one']
p = argparse.ArgumentParser()
p.add_argument('action', choices=['install', 'refresh', 'run', 'matrix', 'status', 'cleanup', 'finish-cleanup'])
p.add_argument('--mode', choices=MODES)
p.add_argument('--apply', action='store_true')
a = p.parse_args()
spec = importlib.util.spec_from_file_location('wpcloud', '/Users/nick/.codex/skills/wpcloud-api/scripts/wpcloud.py')
api = importlib.util.module_from_spec(spec)
spec.loader.exec_module(api)
api.load_dotenv(pathlib.Path('/Users/nick/Documents/Projects/wpcloud-api-tools/.env'))
opts = argparse.Namespace(client=None, dry_run=not a.apply, timeout=45, compact=True, show_secrets=False)
connections = json.loads((pathlib.Path.home() / 'Library/Application Support/Dashless/connections.json').read_text())
site = next(s for s in connections['sites'].values() if s.get('site_url') == 'https://teddy.blog')
deployment = site['deployment']
packages = json.loads((ROOT / 'hosted/dist/site-packages.json').read_text())
state = json.loads(STATE.read_text()) if STATE.exists() else {'operation': str(uuid.uuid4()), 'secret': secrets.token_hex(32), 'site_id': 152056190, 'tasks': []}
stage = '/htdocs/wp-content/mu-plugins/dashless-host-diagnostic'
remote = '/htdocs/wp-content/uploads/dashless-host-diagnostic/' + state['operation']

def save():
    STATE.parent.mkdir(parents=True, exist_ok=True)
    STATE.write_text(json.dumps(state))
    STATE.chmod(0o600)

def quote(value):
    return '"' + str(value).replace('\\', '\\\\').replace('"', '\\"') + '"'

def sftp(lines):
    if not a.apply:
        print(json.dumps({'sftp_commands': len(lines), 'site_id': 152056190, 'scope': 'fixed diagnostic fixture only'}))
        return
    result = subprocess.run(['sftp', '-b', '-', '-oBatchMode=yes', '-oStrictHostKeyChecking=yes', '-i', deployment['identity_file'], deployment['user'] + '@' + deployment['host']], input='\n'.join(lines)+'\n', text=True, capture_output=True, timeout=240)
    if result.returncode:
        raise RuntimeError('SFTP failed: ' + result.stderr[-1000:])

def last_task():
    return api.call_api(opts, 'GET', '/task-get/' + state['tasks'][-1]['id'])['data'] if state['tasks'] else None

def require_idle():
    previous = last_task()
    if previous and not previous.get('complete'):
        raise SystemExit('Previous fixed task is still running. No overlapping checks.')
    return previous

def task(mode):
    require_idle()
    form = [(f'args[{i}]', v) for i, v in enumerate(['dashless', 'diagnostic', mode])] + [('site_run_list[0]', '152056190'), ('site_count_limit', '1'), ('cli_mode', 'full'), ('send_webhook_for', 'none')]
    endpoint = '/task-create/' + api.path_part(api.client_name(opts)) + '/run-wp-cli-command'
    dry = argparse.Namespace(**vars(opts)); dry.dry_run = True
    api.call_api(dry, 'POST', endpoint, form=form)
    if a.apply:
        task_id = str(api.call_api(opts, 'POST', endpoint, form=form)['data']['task_id'])
        state['tasks'].append({'id': task_id, 'mode': mode})
        save()
        print(json.dumps({'task_id': task_id, 'mode': mode, 'site_id': 152056190}))

def report():
    request = urllib.request.Request('https://teddy.blog/wp-json/dashless-host-diagnostic/v1/report', headers={'Authorization': 'Bearer ' + state['secret'], 'Cache-Control': 'no-store'})
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.loads(response.read())

if a.action == 'install':
    if state.get('installed'):
        raise SystemExit('Already installed; preserve current evidence.')
    state['runtime'] = packages['runtime']
    if a.apply: save()
    with tempfile.TemporaryDirectory(prefix='dashless-host-diagnostic-') as tmp:
        settings = pathlib.Path(tmp) / 'settings.json'
        settings.write_text(json.dumps({'operation': state['operation'], 'credential_hash': hashlib.sha256(state['secret'].encode()).hexdigest(), 'runtime_sha256': packages['runtime']['sha256']}))
        lines = ['-mkdir '+quote(stage), '-mkdir "/htdocs/wp-content/uploads/dashless-host-diagnostic"', 'mkdir '+quote(remote)]
        for source, name in [(settings, 'settings.json'), (ROOT/'wordpress/hosted/Support.php', 'Support.php'), (ROOT/'wordpress/hosted/Runtime.php', 'Runtime.php'), (ROOT/'hosted/site-tests/host-diagnostic.mjs', 'host-diagnostic.mjs')]:
            lines.append('put '+quote(source)+' '+quote(stage+'/'+name))
        lines.append('put '+quote(ROOT/'hosted/dist'/packages['runtime']['filename'])+' '+quote(remote+'/runtime.zip'))
        lines.append('put '+quote(ROOT/'hosted/site-tests/host-diagnostic.php')+' "/htdocs/wp-content/mu-plugins/dashless-host-diagnostic.php"')
        sftp(lines)
    if a.apply:
        state['installed'] = True
        save()
    task('setup')
elif a.action == 'refresh':
    require_idle()
    sftp(['put '+quote(ROOT/'hosted/site-tests/host-diagnostic.mjs')+' '+quote(stage+'/host-diagnostic.mjs'), 'put '+quote(ROOT/'hosted/site-tests/host-diagnostic.mjs')+' '+quote(remote+'/runtime/host-diagnostic.mjs'), 'put '+quote(ROOT/'hosted/site-tests/host-diagnostic.php')+' "/htdocs/wp-content/mu-plugins/dashless-host-diagnostic.php"'])
elif a.action == 'run':
    if not a.mode: raise SystemExit('--mode is required')
    if any(t['mode'] == a.mode for t in state['tasks']): raise SystemExit('This check already has evidence; do not overwrite.')
    if not state.get('installed'): raise SystemExit('Install the fixture first.')
    task(a.mode)
elif a.action == 'matrix':
    if not a.apply: raise SystemExit('Fixed matrix: '+', '.join(MODES))
    if not state.get('installed'): raise SystemExit('Install the fixture first.')
    previous = require_idle()
    if state['tasks'][-1]['mode'] == 'setup' and previous['meta'].get('success_count') != '1':
        raise SystemExit('Setup must succeed first.')
    for mode in MODES:
        if any(t['mode'] == mode for t in state['tasks']): continue
        task(mode)
        started = time.monotonic()
        while True:
            current = last_task()
            if current.get('complete'): break
            if time.monotonic()-started > 120: raise SystemExit('Task still pending; stop instead of overlapping.')
            time.sleep(3)
        status = subprocess.run(['python3', str(__file__), 'status', '--apply'], capture_output=True, text=True, check=True)
        result = json.loads(status.stdout)
        r = result['report']
        traces=(r['traces'] or {}).get(mode, [])
        print(json.dumps({'mode': mode, 'task': current['task_id'], 'success': current['meta']['success_count'], 'result': r['result'], 'max_rss_kb': max((t['usage']['maxRSS'] for t in traces),default=None), 'stages': [{'stage': t['stage'], 'message': t.get('message'), 'parallelism': t.get('parallelism')} for t in traces if t['stage']!='usage-sample'], 'log': (r['logs'] or {}).get(mode, '')}), flush=True)
elif a.action == 'status':
    current = last_task()
    evidence = {'operation': state['operation'], 'site_id': 152056190, 'runtime': state.get('runtime'), 'tasks': state['tasks'], 'task': current}
    if state.get('installed') and not state.get('cleaned'):
        evidence['report'] = report()
    destination = ROOT/'hosted/evidence'/('host-diagnostic-'+state['operation']+'.json')
    if destination.exists():
        prior = json.loads(destination.read_text())
        history = prior.get('history', {})
        if current: history[state['tasks'][-1]['mode']] = {'task': current, 'report': evidence.get('report')}
        evidence['history'] = history
    else:
        evidence['history'] = {state['tasks'][-1]['mode']: {'task': current, 'report': evidence.get('report')}} if current else {}
    destination.write_text(json.dumps(evidence, indent=2))
    print(json.dumps(evidence, indent=2))
elif a.action == 'cleanup':
    task('cleanup')
elif a.action == 'finish-cleanup':
    previous = require_idle()
    if not previous or state['tasks'][-1]['mode'] != 'cleanup' or previous['meta'].get('success_count') != '1':
        raise SystemExit('Require successful fixed cleanup first.')
    files = ['/htdocs/wp-content/mu-plugins/dashless-host-diagnostic.php'] + [stage+'/'+name for name in ['settings.json','Support.php','Runtime.php','host-diagnostic.mjs']]
    sftp(['rm '+quote(f) for f in files] + ['rmdir '+quote(stage), 'rmdir "/htdocs/wp-content/uploads/dashless-host-diagnostic"'])
    if a.apply:
        state['cleaned'] = True
        save()
        print('Temporary diagnostic fixture removed.')
