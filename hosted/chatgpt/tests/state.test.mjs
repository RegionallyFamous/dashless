import test from 'node:test';
import assert from 'node:assert/strict';
import {describe,nextDelay,reviewUrl} from '../src/state.mjs';
test('only public verification proves publication',()=>{
 for(const data of [{status:'succeeded'},{status:'succeeded',published_in_wordpress:true},{status:'succeeded',deployed:true},{status:'queued',publicly_verified:true}])assert.doesNotMatch(describe(data)[0],/Your blog is live/);
 assert.equal(describe({status:'succeeded',publicly_verified:true})[0],'Your blog is live');
});
test('phase-specific errors never invent preservation',()=>{
 assert.doesNotMatch(describe({status:'failed'})[1],/still online/);
 assert.match(describe({status:'failed',previous_release_preserved:true})[1],/still online/);
 for(const status of ['queued','running','failed','canceled','succeeded'])assert.ok(describe({status})[0]);
});
test('review URL accepts only the fixed Hub preview path',()=>{
 assert.ok(reviewUrl('https://dashless.blog/preview/?preview=test&handoff=test'));
 for(const value of ['javascript:alert(1)','https://dashless.blog.attacker.test/preview/','https://evil@dashless.blog/preview/','https://dashless.blog/checkout/','http://dashless.blog/preview/'])assert.equal(reviewUrl(value),null);
});
test('polling backs off and caps at thirty seconds',()=>{assert.equal(nextDelay(0),2000);assert.equal(nextDelay(3),16000);assert.equal(nextDelay(40),30000);});
