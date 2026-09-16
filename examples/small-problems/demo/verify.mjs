import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
const directory=path.dirname(fileURLToPath(import.meta.url)), root=path.resolve(directory,'..');
const base=process.env.DEMO_URL||'http://127.0.0.1:4327';
const manifest=JSON.parse(await fs.readFile(path.join(root,'dist/dashless-publication.json'),'utf8'));
const evidence=path.join(directory,'evidence');await fs.mkdir(evidence,{recursive:true});
const browser=await chromium.launch({headless:true});
const page=await browser.newPage({colorScheme:'light'}),errors=[];
page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(['error','warning'].includes(message.type()))errors.push(message.text());});
const report={pages:manifest.routes.length+1,widths:[390,768,1280,320],checks:[],screenshots:[]};
try {
 for(const width of report.widths){
  await page.setViewportSize({width,height:900});
  for(const route of [...manifest.routes,'/404.html']){
   const response=await page.goto(base+route);assert.ok([200,404].includes(response.status()),route);
   assert.equal(await page.locator('h1').count(),1,route);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${route} overflows at ${width}`);
   assert.equal(await page.locator('main img').evaluateAll(images=>images.every(image=>image.complete&&image.naturalWidth>0)),true,route);
  }
 }
 report.checks.push('23 pages at 4 widths; headings, reflow, loaded images');
 await page.goto(base+'/');await page.keyboard.press('Tab');assert.equal(await page.locator('.skip-link').evaluate(el=>el===document.activeElement),true);
 await page.getByRole('button',{name:'Switch to dark mode'}).click();assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');await page.reload();assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');
 report.checks.push('Keyboard skip link and persistent light/dark toggle');
 await page.setViewportSize({width:1280,height:1000});await page.screenshot({path:path.join(evidence,'home-dark.png'),fullPage:true});
 await page.getByRole('button',{name:'Switch to light mode'}).click();
 await page.goto(base+'/search/');await page.getByRole('searchbox',{name:'Search this site'}).fill('spoon');await page.getByRole('button',{name:'Search',exact:true}).click();assert.ok(await page.locator('#search-results article').count()>0);assert.match(page.url(),/q=spoon/);
 await page.getByRole('searchbox',{name:'Search this site'}).fill('zzzznothinghere');await page.getByRole('button',{name:'Search',exact:true}).click();assert.match(await page.locator('#search-summary').textContent(),/^0 results/);
 await page.getByRole('searchbox',{name:'Search this site'}).fill('<img src=x onerror=alert(1)>');await page.getByRole('button',{name:'Search',exact:true}).click();assert.equal(await page.locator('#search-results img').count(),0);
 report.checks.push('Search results, no results, query URL, and safe text');
 await page.goto(base+'/stories/');await page.getByRole('link',{name:'Older →'}).click();assert.match(page.url(),/stories\/page\/2\//);assert.match(await page.locator('main').innerText(),/Meeting about shorter meetings/);
 await page.goto(base+'/about/');await page.getByRole('link',{name:'Meet the department →'}).click();assert.match(await page.locator('main').innerText(),/Graham is a pigeon/);
 report.checks.push('Archive pagination and nested staff page');
 await page.goto(base+'/stories/seven-objects-on-the-chair/');await page.locator('.article-hero img').evaluate(img=>img.dispatchEvent(new Event('error')));assert.equal(await page.getByRole('img',{name:/image unavailable/}).count(),1);
 report.checks.push('Accessible unavailable-image state');
 await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await page.evaluate(()=>matchMedia('(prefers-reduced-motion: reduce)').matches),true);
 await page.emulateMedia({forcedColors:'active'});await page.goto(base+'/');assert.equal(await page.locator('#theme-toggle').isVisible(),true);await page.emulateMedia({forcedColors:'none'});
 report.checks.push('Reduced-motion and forced-color modes');
 for(const [name,route,width,height] of [['home-desktop','/',1440,1100],['home-mobile','/',390,844],['article-mobile','/stories/seven-objects-on-the-chair/',390,844],['about-desktop','/about/',1280,1000]]){
  await page.setViewportSize({width,height});await page.goto(base+route);await page.screenshot({path:path.join(evidence,name+'.png'),fullPage:true});report.screenshots.push(name+'.png');
 }
 const plain=await browser.newContext({javaScriptEnabled:false});const reader=await plain.newPage();await reader.goto(base+'/stories/inquiry-into-the-wet-spoon/');assert.match(await reader.locator('main').innerText(),/The new spoon|the new spoon/);await plain.close();report.checks.push('Reading without JavaScript');
 assert.deepEqual(errors,[]);report.checks.push('No unexpected browser errors or warnings');
 report.passed=true;await fs.writeFile(path.join(evidence,'report.json'),JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report,null,2));
}finally{await browser.close();}
