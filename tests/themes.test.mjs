import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { themes, validateDesign } from '../templates/astro/src/lib/design.mjs';
import { validateThemeCatalog } from '../templates/astro/src/lib/theme-registry.mjs';
import { fixture } from './fixtures/publication/site.mjs';

test('catalog selections validate and reject unknown editions', () => {
  for (const theme of themes) {
    const design={...fixture().design,...theme.defaults,theme_id:theme.id,theme_version:theme.version};
    assert.equal(validateDesign(design).theme_id,theme.id);
    assert.throws(()=>validateDesign({...design,theme_version:999}),/Unsupported theme/);
  }
  assert.throws(()=>validateDesign({...fixture().design,theme_id:'unknown',theme_version:1}),/Unsupported theme/);
  assert.throws(()=>validateDesign({...fixture().design,theme_version:1}),/Unsupported theme/);
  assert.equal(validateDesign(fixture().design).theme_id,undefined,'legacy designs retain their presentation');
});

test('theme manifests use the small authoring contract', () => {
  assert.equal(validateThemeCatalog(themes).length, themes.length);
  for (const theme of themes) {
    assert.match(theme.concept, /\S/);
    assert.ok(theme.mood.length >= 3 && theme.mood.length <= 5);
    assert.match(theme.signature, /\S/);
    assert.match(theme.quiet_area, /\S/);
  }
  assert.throws(() => validateThemeCatalog([{...themes[0]}, {...themes[0]}]), /unique/);
  assert.throws(() => validateThemeCatalog([{...themes[0], template: 'custom'}]), /template/);
  assert.throws(() => validateThemeCatalog([{...themes[0], concept: undefined}]), /Incomplete/);
});

test('theme tool contract provides discovery and safe staged selection', async () => {
  const tools=JSON.parse(await readFile(new URL('../hosted/hub/contracts/tools.v1.json',import.meta.url)));
  for(const name of ['list_themes','get_theme']) {
    const tool=tools.find(t=>t.name===name);assert.equal(tool.scope,'blog:read');assert.equal(tool.annotations.readOnlyHint,true);
  }
  const changes=tools.find(t=>t.name==='update_design').inputSchema.properties.changes;
  assert.ok(changes.properties.theme_id);assert.ok(changes.properties.theme_version);assert.equal(changes.additionalProperties,false);
});

test('Astro and Hub catalogs stay in sync', async () => {
  const hub = JSON.parse(await readFile(new URL('../hosted/hub/contracts/themes.v1.json', import.meta.url)));
  assert.deepEqual(hub.map(({id, version, template}) => ({id, version, template})), themes.map(({id, version, template}) => ({id, version, template})));
});

test('the Dashless wordmark remains asset-backed through the stylesheet cascade', async () => {
  const css = await readFile(new URL('../hosted/theme/assets/site.css', import.meta.url), 'utf8');
  const functions = await readFile(new URL('../hosted/theme/functions.php', import.meta.url), 'utf8');
  assert.match(functions, /class="dl-rip"/);
  assert.match(functions, /class="dl-hot-type"/);
  assert.doesNotMatch(css, /\.dl-rip\{display:none\}/);
  assert.doesNotMatch(css, /\.dl-hot-type:after\{content:['"]Dashless/);
  assert.match(css, /\.dl-hot-type\{[^}]*background:[^}]*hot-type-v3\.webp/);
});

test('PHP selection preserves identity, applies explicit overrides and rejects stale/unknown versions', () => {
  const result=spawnSync('php',['tests/helpers/themes.php'],{encoding:'utf8'});
  assert.equal(result.status,0,result.stdout+result.stderr);
});
