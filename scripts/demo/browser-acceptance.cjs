// Explicit operator acceptance only; never invoked by deployment or against production.
// Private fixture file: gateway {username,password}, owner/member/otherOwner
// {email,password,merchantName}, adminA/adminB {email,password,mfaCode}, serviceId.
const { chromium, expect } = require('../../node_modules/@playwright/test');
const fs = require('node:fs');
const crypto = require('node:crypto');

(async () => {
  if (process.env.CTS_DEMO_ACK !== 'SYNTHETIC_ONLY_PERMANENT_DATA_LOSS_ACCEPTED') throw Error('ACK_REQUIRED');
  const path = process.argv[2];
  const meta = fs.lstatSync(path);
  if (!meta.isFile() || meta.isSymbolicLink() || (meta.mode & 0o077) || meta.uid !== process.getuid()) throw Error('PRIVATE_FIXTURE_REQUIRED');
  const f = JSON.parse(fs.readFileSync(path, 'utf8'));
  for (const key of ['owner','member','otherOwner','adminA','adminB']) {
    if (!f[key]?.email?.endsWith('@example.invalid') || f[key].password?.length < 16) throw Error('SYNTHETIC_ACCOUNT_REQUIRED');
  }
  if (!/^[a-f0-9-]{36}$/.test(f.serviceId) || !f.gateway?.password) throw Error('APPROVED_FIXTURE_REQUIRED');
  const baseURL = 'https://staging.zeeship.cloud';
  const browser = await chromium.launch({headless:true});
  const checks = [];
  try {
    const denied = await browser.newContext({baseURL});
    expect((await denied.request.get('/en')).status()).toBe(401);
    await denied.close(); checks.push('gateway_unauthorized_401');
    const context = await browser.newContext({baseURL,httpCredentials:f.gateway});
    const page = await context.newPage();
    // No traces, screenshots, video, passwords or response bodies in evidence.
    const csrf = async () => decodeURIComponent((await context.cookies()).find(c=>c.name==='XSRF-TOKEN')?.value || '');
    const post = async (path,data) => page.request.post(path,{data,headers:{Origin:baseURL,Referer:baseURL+'/en/dashboard','X-XSRF-TOKEN':await csrf()}});
    const logout = async () => {
      const result=await post('/api/v1/auth/logout',{});expect(result.ok()).toBe(true);
      expect((await page.request.get('/api/v1/auth/me')).status()).toBe(401);
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
    expect((await page.request.get('/api/v1/health/ready')).status()).toBe(200);
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
    expect((await page.request.get('/api/v1/shipments/'+id)).status()).toBe(404);
    checks.push('cross_tenant_idor_denied');await logout();
    await login('member',true);await expect(page.getByRole('main')).toBeVisible();
    await logout();checks.push('operational_member_login_logout');
    for (const key of ['adminA','adminB']) {
      await login(key);await page.goto('/en/platform-overview');
      await expect(page.getByRole('main').getByRole('alert')).toContainText('Recent MFA');
      await page.goto('/en/mfa');const form=page.getByRole('button',{name:'Verify MFA',exact:true}).locator('..');
      await form.getByLabel('Current password',{exact:true}).fill(f[key].password);
      await form.getByLabel('Authenticator or recovery code',{exact:true}).fill(f[key].mfaCode);
      await form.getByRole('button',{name:'Verify MFA',exact:true}).click();
      await expect(page.getByRole('status')).toContainText('Changes saved');
      await page.goto('/en/platform-overview');await expect(page.getByRole('heading',{name:'Registered merchants',exact:true})).toBeVisible();
      await logout();
    }
    checks.push('two_admin_mfa_enforced_and_dashboard');
    await context.close();console.log(JSON.stringify({scope:'disposable_demo_browser_acceptance',checks,ok:true,live_integrations_verified:false}));
  } finally {await browser.close();}
})().catch(()=>{console.error('DEMO_BROWSER_ACCEPTANCE_FAILED_NO_PRIVATE_DIAGNOSTICS');process.exit(1);});
