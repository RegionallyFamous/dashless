#!/usr/bin/env python3
"""Verify every packaged byte and its manifest without extracting executable files."""
import hashlib, json, pathlib, stat, zipfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
def digest(stream):
 h=hashlib.sha256()
 for block in iter(lambda:stream.read(1024*1024),b''):h.update(block)
 return h.hexdigest()
packages=json.loads((ROOT/'hosted/dist/site-packages.json').read_text());result={}
for kind in ['site','runtime']:
 p=packages[kind];file=ROOT/'hosted/dist'/p['filename']
 with file.open('rb') as stream:assert digest(stream)==p['sha256'],kind+' ZIP digest'
 assert file.stat().st_size==p['bytes']
 with zipfile.ZipFile(file) as archive:
  prefix='dashless-site/' if kind=='site' else '';manifest_name=prefix+('site-manifest.json' if kind=='site' else 'runtime-manifest.json');manifest=json.loads(archive.read(manifest_name));names=archive.namelist()
  assert len(names)==len(set(names)),'Duplicate archive paths'
  assert set(names)=={manifest_name}|{prefix+f['path'] for f in manifest['files']},'Unlisted package file'
  total=0
  for entry in manifest['files']:
   name=prefix+entry['path'];rel=pathlib.PurePosixPath(name);assert not rel.is_absolute() and '..' not in rel.parts
   info=archive.getinfo(name);assert not stat.S_ISLNK(info.external_attr>>16),'Package symlink'
   assert info.file_size==entry['bytes'];total+=info.file_size
   with archive.open(name) as stream:assert digest(stream)==entry['sha256'],name
   source=None
   if kind=='site':
    source=ROOT/'wordpress'/entry['path']
    if entry['path']=='hosted/tools.v1.json':source=ROOT/'hosted/hub/contracts/tools.v1.json'
    if entry['path']=='hosted/themes.json':source=ROOT/'templates/astro/src/lib/themes.json'
    if entry['path'].startswith('build-source/template/'):source=ROOT/'templates/astro'/entry['path'][22:]
    elif entry['path'].startswith('build-source/'):source=ROOT/'hosted/runtime'/entry['path'][13:]
   elif entry['path'].startswith('template/'):source=ROOT/'templates/astro'/entry['path'][9:]
   elif entry['path'] in ['build.mjs','supervise.mjs','package.json','package-lock.json','sources.lock.json']:source=ROOT/'hosted/runtime'/entry['path']
   if source:
    with source.open('rb') as stream:assert digest(stream)==entry['sha256'],'Source changed after packaging: '+str(source)
  assert not any('native-probe' in n or '/.env' in n or '/.local/' in n for n in names)
  if kind=='site':assert manifest['runtime']==packages['runtime']
  result[kind]={'sha256':p['sha256'],'archive_bytes':p['bytes'],'verified_files':len(manifest['files']),'uncompressed_bytes':total,'source_matches':True}
(ROOT/'hosted/evidence/site-package-verification.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2))
