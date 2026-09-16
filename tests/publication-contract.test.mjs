import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fixture } from './fixtures/publication/site.mjs';
import { loadSnapshot } from '../templates/astro/src/lib/snapshot.mjs';
import { validateDesign, presets } from '../templates/astro/src/lib/design.mjs';

test('snapshot adapter isolates drafts, preserves rendered HTML, and paginates', async t => {
  const root=await mkdtemp(path.join(os.tmpdir(),'publication-contract-'));t.after(()=>rm(root,{recursive:true,force:true}));
  const data=fixture({count:103});data.items.push({...data.items[0],id:900,slug:'private',status:'private'});
  const file=path.join(root,'snapshot.json');await writeFile(file,JSON.stringify(data));
  const adapter=await loadSnapshot(file);
  const first=adapter.request('wp/v2/posts',{per_page:100});
  assert.equal(first.data.length,100);assert.equal(first.headers.get('x-wp-totalpages'),'2');
  assert.equal(adapter.request('wp/v2/posts',{per_page:100,page:2}).data.length,3);
  assert.match(first.data[0].content.rendered,/Rendered searchable/);assert.match(first.data[0].content.raw,/RAW EDITOR/);
  assert.throws(()=>adapter.request('wp/v2/unknown'),/Unsupported/);
  await assert.rejects(adapter.localAsset('https://example.com/private'),/absent/);
  data.items[0].rendered=undefined;await writeFile(file,JSON.stringify(data));await assert.rejects(loadSnapshot(file),/rendered content/);
});

test('design settings are bounded and versioned', () => {
  for(const palette of presets.palette)for(const typography of presets.typography)for(const layout of presets.layout)assert.equal(validateDesign(fixture({palette,typography,layout}).design).schema_version,1);
  assert.throws(()=>validateDesign({...fixture().design,palette:'url(javascript:bad)'}),/Unsupported/);
  assert.throws(()=>validateDesign({...fixture().design,schema_version:2}),/schema/);
});

test('sealed media fails closed on corruption and escaped paths', async t => {
  const { fixtureImage } = await import('./fixtures/publication/site.mjs');
  const { mkdir } = await import('node:fs/promises');
  const root=await mkdtemp(path.join(os.tmpdir(),'publication-media-'));t.after(()=>rm(root,{recursive:true,force:true}));
  const data=fixture({withMedia:true});const file=path.join(root,'snapshot.json');
  await mkdir(path.join(root,'media'));await writeFile(path.join(root,'media/9000-photo.png'),fixtureImage);await writeFile(file,JSON.stringify(data));
  const adapter=await loadSnapshot(file);
  assert.match(await adapter.localAsset('https://publication.example/wp-content/uploads/photo.png'),/9000-photo.png$/);
  await writeFile(path.join(root,'media/9000-photo.png'),'changed');
  await assert.rejects(adapter.localAsset('https://publication.example/wp-content/uploads/photo.png'),/checksum/);
  data.assets['media/../../escape']=data.assets['media/9000-photo.png'];await writeFile(file,JSON.stringify(data));
  await assert.rejects(loadSnapshot(file),/Unsafe snapshot asset path/);
});
