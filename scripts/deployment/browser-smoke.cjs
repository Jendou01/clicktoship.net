// Isolated release rehearsal only; fixture/profile are private and deleted by the caller.
const { chromium, expect } = require('../../node_modules/@playwright/test');
const fs = require('node:fs');
(async () => {
  const f = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
  const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '/usr/bin/chromium', headless: true, env: {...process.env, HOME: f.home}, args: ['--no-sandbox', '--no-proxy-server', '--host-resolver-rules=MAP staging.example.test 127.0.0.1'] });
  let views = 0;
  try {
    const context = await browser.newContext({baseURL: f.url, httpCredentials: {username: 'owner', password: f.password}});
    const page = await context.newPage();
    const errors = []; page.on('pageerror', () => errors.push('BROWSER_RUNTIME_ERROR'));
    page.on('response', r => {if(r.url().includes('/_next/static/') && r.status()>=400) errors.push('STATIC_ASSET_HTTP_'+r.status());});
    for (const viewport of [{width:1440,height:1000}, {width:390,height:844}]) {
      await page.setViewportSize(viewport);
      for (const locale of ['en','ar']) for (const route of ['', '/login', '/register']) {
        const response = await page.goto('/'+locale+route);
        if (!response || response.status()!==200) throw Error('PROTECTED_PAGE_UNAVAILABLE:'+String(response?.status())+':/'+locale+route);
        await expect(page.getByRole('heading', {level:1})).toBeVisible();
        const result=await page.evaluate(()=>({lang:document.documentElement.lang,dir:document.documentElement.dir,overflow:document.documentElement.scrollWidth>innerWidth}));
        if (result.lang!==locale || result.dir!==(locale==='ar'?'rtl':'ltr') || result.overflow || errors.length) throw Error('LOCALE_LAYOUT_OR_RUNTIME_FAILURE');
        views++;
      }
    }
    await context.close();
    console.log(JSON.stringify({code:'PROTECTED_TLS_BROWSER_PASSED',views,local_certificate_trusted:true,tls_verification_disabled:false}));
  } finally {await browser.close();}
})().catch((error)=>{const code=String(error.message).match(/net::ERR_[A-Z_]+/);console.error('PROTECTED_TLS_BROWSER_FAILED',code?code[0]:String(error.message).slice(0,240));process.exit(1);});
