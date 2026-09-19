import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, readFile, rm, readdir } from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { spawn } from 'node:child_process';
import { fixture } from '../../tests/fixtures/publication/site.mjs';
import { checkPublication } from '../../templates/astro/scripts/check-publication.mjs';
const root=path.resolve('hosted/runtime');
export async function buildFixture(data, work) {
  await writeFile(path.join(work,'snapshot.json'),JSON.stringify(data));
  const child=spawn(process.execPath,[path.join(root,'build.mjs'),work],{stdio:['ignore','pipe','pipe']});let output='';
  child.stdout.on('data',chunk=>output+=chunk);child.stderr.on('data',chunk=>output+=chunk);
  const code=await new Promise((res,rej)=>{child.once('error',rej);child.once('close',res);});
  assert.equal(code,0,output);return path.join(work,'source/dist');
}
for(const [name,options] of [['empty', {count:0}],['single',{count:1,palette:'night',typography:'modern',layout:'minimal'}],['archive',{count:14,home:true,palette:'lilac',typography:'classic',layout:'magazine'}]])test(`hosted shared publication: ${name}`,{timeout:180000},async t=>{
  const work=await mkdtemp(path.join(os.tmpdir(),`dashless-${name}-`));
  t.after(()=>rm(work,{recursive:true,force:true}));
  const dist=await buildFixture(fixture(options),work);
  const result=JSON.parse(await readFile(path.join(work,'build-result.json'),'utf8'));
  assert.equal(result.quality.passed,true);assert.equal(result.post_count,options.count);
  const home=await readFile(path.join(dist,'index.html'),'utf8');
  assert.match(home,/href="\/__DASHLESS_BASE__\/search\/"/);
  assert.match(home,/href="\/__DASHLESS_BASE__\/_astro\//);
  assert.match(home,/<script\b/);
  const search=await readFile(path.join(dist,'search/index.html'),'utf8');
  assert.doesNotMatch(search,/RAW EDITOR SOURCE/);
  if(options.count) {
    assert.match(search,/"url":"\/__DASHLESS_BASE__\/stories\//);
    const story=await readFile(path.join(dist,'stories/story-1/index.html'),'utf8');
    assert.match(story,/Rendered searchable curiosity/);assert.doesNotMatch(story,/RAW EDITOR/);
    assert.match(story,/rel="canonical" href="https:\/\/publication.example\/stories\/story-1\/"/);
    assert.equal((await readdir(path.join(dist,'_dashless/social'))).length,options.count);
  } else assert.match(home,/No published stories yet/);
  if(options.home) {
    await readFile(path.join(dist,'stories/page/2/index.html'));
    const team=await readFile(path.join(dist,'about/team/index.html'),'utf8');
    assert.match(team,/team page body/);
  }
  // Removing a required route must prevent a releasable result.
  await rm(path.join(dist,'search'),{recursive:true});await assert.rejects(checkPublication(path.join(work,'source')));
});
