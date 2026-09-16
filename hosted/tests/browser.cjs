// Local-only browser acceptance. Requires the disposable fixture mail sink described in docs/verification.md.
const fs=require('node:fs');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async()=>{
 const base=process.env.DASHLESS_TEST_URL || 'http://localhost:8874';
 if(!/^http:\/\/(localhost|127\.0\.0\.1):\d+$/.test(base))throw new Error('Local fixture URL required.');
 const browser=await chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:1440,height:1000}});let checks=0;
 const check=(condition,message)=>{if(!condition)throw new Error(message);checks++;console.log('PASS '+message);};
 try {
  for(const path of ['/','/sign-in/','/account/','/support/','/terms/','/privacy/','/preview/']) {
   const response=await page.goto(base+path);check(response.status()===200,path+' responds');
   check(!/\[dashless_[a-z_]+\]/.test(await page.locator('body').innerText()),path+' renders dynamic blocks');
  }
  await page.goto(base+'/');await page.screenshot({path:'/tmp/dashless-desktop.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:'/tmp/dashless-mobile.png',fullPage:true});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile has no horizontal overflow');
  await page.goto(base+'/sign-in/');await page.getByLabel('Email address').fill('browser-'+Date.now()+'@example.test');await page.getByRole('button',{name:'Email me a sign-in link'}).click();await page.getByRole('status').filter({hasText:'Check your inbox'}).waitFor();
  const mail=JSON.parse(fs.readFileSync('/tmp/dashless-local-mail.json','utf8'));const url=mail.message.match(/http:\/\/[^\s]+/)[0];
  await page.goto(url);check(await page.getByRole('button',{name:'Sign in to Dashless'}).count()===1,'scanner GET leaves confirmation form available');
  await page.reload();await page.getByRole('button',{name:'Sign in to Dashless'}).click();await page.waitForURL('**/account/');check(await page.getByLabel('Choose your address').count()===1,'magic POST creates signed-in account');
  check(await page.getByRole('button',{name:'Subscriptions open soon'}).isDisabled(),'checkout remains disabled in customer UI');
  await page.screenshot({path:'/tmp/dashless-account-mobile.png',fullPage:true});
  const noNonce=await page.request.post(base+'/wp-json/dashless-hub/v1/checkout',{data:{slug:'nonce-test'}});check([401,403].includes(noNonce.status()),'cookie mutation without REST nonce rejected');
  const signature=await page.request.post(base+'/wp-json/dashless-hub/v1/stripe/webhook',{data:{id:'spoof'}});check(signature.status()===400,'unsigned Stripe webhook rejected');
  console.log(checks+' browser checks passed.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
