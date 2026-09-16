import assert from 'node:assert/strict';
import { cp, mkdtemp, realpath, writeFile, readFile, mkdir, rm } from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import http from 'node:http';
import { createHash } from 'node:crypto';
import { spawn } from 'node:child_process';
import { chromium } from 'playwright';
import { fixture, fixtureImage } from './fixtures/publication/site.mjs';
import { presets } from '../templates/astro/src/lib/design.mjs';
const root=path.resolve('.'), evidence=path.join(root,'dist/frontend-qa');
await mkdir(evidence,{recursive:true});
const work=await realpath(await mkdtemp(path.join(os.tmpdir(),'dashless-browser-'))), source=path.join(work,'source');
await cp(path.join(root,'templates/astro'),source,{recursive:true});
async function run(args,env={}) {
  const child=spawn('npm',args,{cwd:source,env:{...process.env,...env},stdio:['ignore','pipe','pipe']});let output='';
  child.stdout.on('data',b=>output+=b);child.stderr.on('data',b=>output+=b);
  assert.equal(await new Promise((resolve,reject)=>{child.once('error',reject);child.once('close',resolve);}),0,output);
}
await run(['ci','--no-audit','--no-fund']);
let browser;
const server=http.createServer(async(req,res)=>{
  try {
    let route=new URL(req.url,'http://local').pathname;
    if(!route.startsWith('/preview/')){res.writeHead(404);res.end();return;}
    route=route.slice('/preview'.length);if(route.endsWith('/'))route+='index.html';
    const file=path.resolve(source,'dist',`.${route}`);if(!file.startsWith(path.join(source,'dist')+path.sep))throw new Error();
    let body=await readFile(file);const ext=path.extname(file);
    const mime={'.html':'text/html','.css':'text/css','.js':'text/javascript','.json':'application/json','.png':'image/png','.svg':'image/svg+xml','.xml':'application/xml','.txt':'text/plain'}[ext]||'application/octet-stream';
    if(ext==='.html') {
      let html=body.toString().replace(/((?:href|src|poster|action)=["']|"url":")\/(?!\/)/g,'$1/preview/');
      const hashes=[...html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)].filter(m=>!/(?:\bsrc\s*=|application\/(?:ld\+)?json)/i.test(m[1])).map(m=>`'sha256-${createHash('sha256').update(m[2]).digest('base64')}'`);
      res.setHeader('Content-Security-Policy',`default-src 'none'; script-src 'self' ${hashes.join(' ')}; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self'; media-src 'self'; base-uri 'none'; form-action 'none'`);
      body=Buffer.from(html);
    }
    res.writeHead(200,{'Content-Type':mime});res.end(body);
  }catch {res.writeHead(404);res.end('Not found');}
});
await new Promise(r=>server.listen(0,'127.0.0.1',r));const url=`http://127.0.0.1:${server.address().port}/preview`;
const report={scenarios:[],presets:0,widths:[390,768,1280,320],source:work};
try {
  browser=await chromium.launch({headless:true});
  for(const [name,options] of [['empty',{count:0}],['single',{count:1,palette:'night',typography:'modern',layout:'minimal'}],['archive',{count:14,home:true,palette:'lilac',typography:'classic',layout:'magazine'}]]) {
    const data=fixture({...options,withMedia:options.count>0}),snapshot=path.join(work,'snapshot.json');await writeFile(snapshot,JSON.stringify(data));
    if(options.count) { await mkdir(path.join(work,'media'),{recursive:true}); await writeFile(path.join(work,'media/9000-photo.png'),fixtureImage); }
    await writeFile(path.join(source,'dashless.config.mjs'),`export default ${JSON.stringify({siteName:name === "single" ? "Teddy Gazette" : data.design.site_title,siteDescription:data.design.description,wordpressUrl:data.site_url,publicUrl:data.site_url,postsPath:'stories',topicsPath:'topics',tagsPath:'tags',postsPerPage:12,mirrorMedia:true,homePageId:data.settings.homePageId,design:data.design,navigation:data.design.navigation})};\n`);
    await rm(path.join(source,'public/_dashless'),{recursive:true,force:true});
    await run(['run','build'],{DASHLESS_SNAPSHOT:snapshot});
    const context=await browser.newContext(),page=await context.newPage(),errors=[];
    page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(['error','warning'].includes(message.type()))errors.push(message.text());});
    const routes=['/','/stories/','/topics/','/tags/','/search/','/404.html',...(options.count?['/stories/story-1/','/topics/news/','/tags/web/']:[]),...(options.home?['/about/team/','/stories/page/2/']:[])];
    for(const width of report.widths)for(const route of routes) {
      await page.setViewportSize({width,height:900});await page.goto(url+route);await page.evaluate(()=>document.fonts.ready);
      assert.equal(await page.locator('h1').count(),1,`${name} ${route}: heading`);
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${name} ${route} overflows ${width}`);
    }
    await page.goto(url+'/');
    if(name === 'single') {
      assert.equal(await page.locator('#teddy-sighting').count(),1);
      await page.keyboard.type('teddy');assert.equal(await page.locator('#teddy-sighting').isVisible(),true);
      await page.goto(url+'/');
    } else {
      assert.equal(await page.locator('#teddy-sighting').count(),0);
      assert.equal(await page.locator('img[src$="/teddy-logo.png"]').count(),0);
      assert.ok(!(await page.content()).includes('Try typing T-E-D-D-Y'));
      await page.keyboard.type('teddy');assert.notEqual(await page.locator('body').getAttribute('data-oddity'),'true');
      await page.locator('#oddity-switch').click();
      assert.doesNotMatch(await page.locator('.signal-strip > span').nth(1).evaluate(el=>getComputedStyle(el,'::after').content),/TEDDY/);
      await page.goto(url+'/');
    }
    await page.keyboard.press('Tab');assert.equal(await page.locator('.skip-link').evaluate(el=>el===document.activeElement),true);
    const theme=page.locator('#theme-toggle');const before=await theme.getAttribute('aria-pressed');await theme.click();assert.notEqual(await theme.getAttribute('aria-pressed'),before);await page.reload();assert.notEqual(await theme.getAttribute('aria-pressed'),before);
    await page.goto(url+'/search/');await page.getByLabel('Search this site').fill('curiosity');await page.getByRole('button',{name:'Search',exact:true}).click();
    assert.equal(await page.locator('#search-results article').count(),options.count);assert.match(page.url(),/q=curiosity/);
    if(options.count) assert.match(await page.locator('#search-results a').first().getAttribute('href'),/^\/preview\/stories\//);
    await page.getByLabel('Search this site').fill('no-match-zzzz');await page.getByRole('button',{name:'Search',exact:true}).click();assert.match(await page.locator('#search-summary').textContent(),/^0 results/);
    await page.emulateMedia({reducedMotion:'reduce',forcedColors:'active'});await page.goto(url+'/');assert.equal(await page.evaluate(()=>matchMedia('(prefers-reduced-motion: reduce)').matches),true);await page.emulateMedia({forcedColors:'none'});
    if(options.home) {
      for(const palette of presets.palette)for(const typography of presets.typography)for(const layout of presets.layout) {
        await page.evaluate(({palette,typography,layout})=>{Object.assign(document.documentElement.dataset,{palette,type:typography,layout});},{palette,typography,layout});
        for(const theme of ['light','dark']) {
          await page.evaluate(async theme=>{document.documentElement.dataset.theme=theme;await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));},theme);
          const failures=await page.evaluate(()=>{
            const luminance=rgb=>{const values=rgb.match(/[\d.]+/g).slice(0,3).map(n=>Number(n)/255).map(n=>n<=.04045?n/12.92:((n+.055)/1.055)**2.4);return .2126*values[0]+.7152*values[1]+.0722*values[2];};
            return [...document.querySelectorAll('.window-bar,.widget-title,.story-window-bar,.hero-badges span')].flatMap(el=>{const css=getComputedStyle(el),a=luminance(css.color),b=luminance(css.backgroundColor),ratio=(Math.max(a,b)+.05)/(Math.min(a,b)+.05);return ratio<4.5?[`${el.className}: ${ratio.toFixed(2)} (${css.color} / ${css.backgroundColor})`]:[];});
          });
          assert.deepEqual(failures,[],`${palette}/${theme} label contrast`);
        }
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${palette}/${typography}/${layout} overflow`);report.presets++;
      }
    }
    if(options.count) {
      await page.goto(url+'/stories/story-1/');
      assert.equal(await page.locator('.wp-block-gallery img').count(),2);
      await page.locator('.article-body img').evaluateAll(images=>images.forEach(img=>img.dispatchEvent(new Event('error'))));
      assert.equal(await page.locator('.wp-block-gallery .inline-image-fallback[role="img"]').count(),1);
      assert.equal(await page.locator('.wp-block-gallery .inline-image-fallback[aria-hidden="true"]').count(),1);
    }
    await page.setViewportSize({width:1280,height:1000});await page.goto(url+'/');await page.screenshot({path:path.join(evidence,`${name}.png`),fullPage:true});
    if(options.home) {
      await page.evaluate(()=>localStorage.setItem('dashless-theme','light'));await page.reload();
      await page.screenshot({path:path.join(evidence,'archive-light.png'),fullPage:true});
      await page.setViewportSize({width:390,height:844});await page.goto(url+'/stories/story-1/');
      await page.screenshot({path:path.join(evidence,'article-mobile.png'),fullPage:true});
    }
    assert.deepEqual(errors,[],`${name} browser errors`);
    const plain=await browser.newContext({javaScriptEnabled:false});const noJS=await plain.newPage();await noJS.goto(url+(options.count?'/stories/story-1/':'/'));assert.ok(await noJS.locator('main').innerText());await plain.close();
    report.scenarios.push({name,routes:routes.length,passed:true});await context.close();
  }
  await writeFile(path.join(evidence,'report.json'),JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report,null,2));
} finally {await browser?.close();await new Promise(r=>server.close(r));}
