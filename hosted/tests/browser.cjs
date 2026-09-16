// Local-only browser acceptance. Requires a disposable Hub installation.
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
  await page.goto(base+'/sign-in/');
  check(await page.locator('input[type=email]').count()===0,'customer email-link form removed');
  check((await page.locator('body').innerText()).includes('WordPress.com'),'WordPress.com is the customer sign-in provider');
  const legacy=await page.request.post(base+'/wp-json/dashless-hub/v1/auth/request',{data:{email:'fixture@example.test'}});check(legacy.status()===404,'legacy email sign-in endpoint removed');
  const noNonce=await page.request.post(base+'/wp-json/dashless-hub/v1/checkout',{data:{slug:'nonce-test'}});check([401,403].includes(noNonce.status()),'cookie mutation without REST nonce rejected');
  const signature=await page.request.post(base+'/wp-json/dashless-hub/v1/stripe/webhook',{data:{id:'spoof'}});check(signature.status()===400,'unsigned Stripe webhook rejected');
  console.log(checks+' browser checks passed.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
