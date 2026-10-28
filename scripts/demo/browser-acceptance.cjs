// Explicit operator acceptance only; never invoked by deployment or against production.
// Private fixture file: gateway {username,password}, owner/member/otherOwner
// {email,password,merchantName}, adminA/adminB {email,password,mfaCode}, serviceId.
const { chromium, expect } = require('../../node_modules/@playwright/test');
const fs = require('node:fs');
const crypto = require('node:crypto');

async function runAcceptance(f, options={}) {
  for (const key of ['owner','member','otherOwner','adminA','adminB']) {
    if (!f[key]?.email?.endsWith('@example.invalid') || f[key].password?.length < 16) throw Error('SYNTHETIC_ACCOUNT_REQUIRED');
  }
  if (!/^[a-f0-9-]{36}$/.test(f.serviceId) || !f.gateway?.password) throw Error('APPROVED_FIXTURE_REQUIRED');
  const baseURL = 'https://staging.zeeship.cloud';
  const browser = await chromium.launch({headless:true,...options.launch});
  const checks = [];
  const network=[];
  try {
    const denied = await browser.newContext({baseURL});
    const deniedPage=await denied.newPage();
    // Chromium may fail the navigation after receiving a Basic-auth challenge.
    // Require the real network response even when no document is committed.
    const denial=deniedPage.waitForResponse(r=>r.url()===baseURL+'/en');
    await deniedPage.goto(baseURL+'/en').catch(error=>{
      if(!error.message.includes('ERR_INVALID_AUTH_CREDENTIALS'))throw error;
    });
    expect((await denial).status()).toBe(401);
    await denied.close(); checks.push('gateway_unauthorized_401');
    const context = await browser.newContext({baseURL,httpCredentials:f.gateway});
    const page = await context.newPage();
    page.on('response',async response=>{
      const path=new URL(response.url()).pathname;
      if(!['/sanctum/csrf-cookie','/api/v1/auth/login','/api/v1/auth/me'].includes(path))return;
      let code=null;
      try {const candidate=(await response.json()).error?.code;if(/^[A-Z_]{3,100}$/.test(candidate))code=candidate;}catch{}
      network.push({path,status:response.status(),code});if(network.length>20)network.shift();
    });
    // No traces, screenshots, video, passwords or response bodies in evidence.
    // In-browser fetch follows Chromium's private endpoint mapping and keeps
    // the canonical origin/cookies/SNI. APIRequestContext would use host DNS.
    const api = async (path,data) => {
      const result=await page.evaluate(async ({path,data})=>{
        const token=decodeURIComponent(document.cookie.split('; ').find(x=>x.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=')||'');
        const r=await fetch(path,{method:data===undefined?'GET':'POST',credentials:'same-origin',referrerPolicy:'same-origin',headers:{Accept:'application/json',...(data===undefined?{}:{'Content-Type':'application/json','X-XSRF-TOKEN':token})},body:data===undefined?undefined:JSON.stringify(data)});
        return {status:r.status,body:r.status===204?null:await r.json()};
      },{path,data});
      return {status:()=>result.status,ok:()=>result.status>=200&&result.status<300,json:async()=>result.body};
    };
    const post=(path,data)=>api(path,data);
    const logout = async () => {
      const result=await post('/api/v1/auth/logout',{});expect(result.ok()).toBe(true);
      expect((await api('/api/v1/auth/me')).status()).toBe(401);
    };
    const login = async (key,merchant=false) => {
      await page.goto('/en/login');
      await page.getByLabel('Email address',{exact:true}).fill(f[key].email);
      await page.getByLabel('Password',{exact:true}).fill(f[key].password);
      await page.getByRole('button',{name:'Continue',exact:true}).click();
      await expect(page).toHaveURL(/\/en\/organizations$/);
      if(merchant) {
        await page.getByRole('article').filter({has:page.getByRole('heading',{name:f[key].merchantName,exact:true})}).getByRole('button',{name:'Continue',exact:true}).click();
        await expect(page).toHaveURL(/\/en\/dashboard$/);
      }
    };
    for (const locale of ['en','ar']) {
      for (const viewport of [{width:1440,height:1000},{width:390,height:844}]) {
        await page.setViewportSize(viewport);expect((await page.goto('/'+locale)).status()).toBe(200);
        await expect(page.getByRole('note').first()).toContainText(locale==='en'?'TEST / DEMO':'عرض تجريبي');
        const layout=await page.evaluate(()=>({lang:document.documentElement.lang,dir:document.documentElement.dir,overflow:document.documentElement.scrollWidth>innerWidth}));
        expect(layout.lang).toBe(locale);expect(layout.dir).toBe(locale==='ar'?'rtl':'ltr');expect(layout.overflow).toBe(false);
      }
    }
    checks.push('english_arabic_banner_and_mobile');
    await login('owner',true);
    expect((await api('/api/v1/health/ready')).status()).toBe(200);
    const csrfDenied=await page.evaluate(async()=>{
      const r=await fetch('/api/v1/shipments',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json'},body:'{}'});
      return r.status;
    });
    expect(csrfDenied).toBe(419);checks.push('csrf_without_token_rejected');
    const draft={currency:'SAR',description:'TEST DEMO browser synthetic goods',origin:{country:'SA',city:'synthetic origin'},destination:{country:'SA',city:'synthetic destination'},packages:[{weight_grams:1000,length_mm:100,width_mm:100,height_mm:100}],declared_value_minor:'0',cod_minor:'0',items:[]};
    const created=await post('/api/v1/shipments',{draft,idempotency_key:crypto.randomUUID()});expect(created.status()).toBe(201);
    const id=(await created.json()).data.shipment.id;
    const quoteInput={currency:draft.currency,origin:draft.origin,destination:draft.destination,packages:draft.packages,declared_value_minor:'0',cod_minor:'0',service_id:f.serviceId,idempotency_key:crypto.randomUUID()};
    const quote=await post('/api/v1/rates/quote',quoteInput);expect(quote.status()).toBe(201);
    expect((await post('/api/v1/shipments/'+id+'/submit',{version:1,idempotency_key:crypto.randomUUID()})).status()).toBe(403);
    checks.push('synthetic_draft_quote_and_submission_denial');
    for (const route of ['shipments','tracking','wallet','analytics']) {
      expect((await page.goto('/en/'+route)).status()).toBe(200);
      await expect(page.getByRole('main')).toBeVisible();
    }
    await logout();await login('otherOwner',true);
    expect((await api('/api/v1/shipments/'+id)).status()).toBe(404);
    checks.push('cross_tenant_idor_denied');await logout();
    await login('member',true);await expect(page.getByRole('main')).toBeVisible();
    await logout();checks.push('operational_member_login_logout');
    for (const key of ['adminA','adminB']) {
      await login(key);await page.goto('/en/platform-overview');
      await expect(page.getByRole('main').getByRole('alert')).toContainText('Recent MFA');
      await page.goto('/en/mfa');const form=page.getByRole('button',{name:'Verify MFA',exact:true}).locator('..');
      await form.getByLabel('Current password',{exact:true}).fill(f[key].password);
      await form.getByLabel('Authenticator or recovery code',{exact:true}).fill(options.codeFor?options.codeFor(key):f[key].mfaCode);
      await form.getByRole('button',{name:'Verify MFA',exact:true}).click();
      await expect(page.getByRole('status')).toContainText('Changes saved');
      await page.goto('/en/platform-overview');await expect(page.getByRole('heading',{name:'Registered merchants',exact:true})).toBeVisible();
      await logout();
    }
    checks.push('two_admin_mfa_enforced_and_dashboard');
    await context.close();return {scope:'disposable_demo_browser_acceptance',checks,ok:true,live_integrations_verified:false};
  } catch(error) {error.demoNetwork=network;throw error;}
  finally {await browser.close();}
}
module.exports={runAcceptance};
if(require.main===module) (async()=>{
  if(process.env.CTS_DEMO_ACK!=='SYNTHETIC_ONLY_PERMANENT_DATA_LOSS_ACCEPTED'||process.platform!=='linux')throw Error('EXPLICIT_LINUX_ACK_REQUIRED');
  const path=process.argv[2];const meta=fs.lstatSync(path);
  if(!meta.isFile()||meta.isSymbolicLink()||(meta.mode&0o077)||meta.uid!==process.getuid())throw Error('PRIVATE_FIXTURE_REQUIRED');
  const f=JSON.parse(fs.readFileSync(path,'utf8'));
  const args=process.argv.includes('--private-loopback')?['--no-proxy-server','--host-resolver-rules=MAP staging.zeeship.cloud 127.0.0.1:18443']:[];
  console.log(JSON.stringify(await runAcceptance(f,{launch:{args}})));
})().catch(()=>{console.error('DEMO_BROWSER_ACCEPTANCE_FAILED_NO_PRIVATE_DIAGNOSTICS');process.exit(1);});
