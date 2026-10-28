// Only invoked inside the isolated demo web container's internal network namespace.
// Ephemeral CI authenticator material comes from NORMAL enrollment API responses.
const fs=require('node:fs');
const crypto=require('node:crypto');
const {runAcceptance}=require('./browser-acceptance.cjs');
function code(secret){
  const alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';let bits='';
  for(const c of secret.replace(/=+$/,'')){
    const n=alphabet.indexOf(c);if(n<0)throw Error('INVALID_CI_SECRET');bits+=n.toString(2).padStart(5,'0');
  }
  const key=Buffer.from((bits.match(/.{8}/g)||[]).map(x=>parseInt(x,2)));
  const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));
  const mac=crypto.createHmac('sha1',key).update(counter).digest();const offset=mac[19]&15;
  return String((mac.readUInt32BE(offset)&0x7fffffff)%1000000).padStart(6,'0');
}
(async()=>{
  const path=process.argv[2];const info=fs.lstatSync(path);
  if(!info.isFile()||info.isSymbolicLink()||(info.mode&0o077)||info.uid!==0)throw Error('PRIVATE_CI_FIXTURE_REQUIRED');
  const f=JSON.parse(fs.readFileSync(path,'utf8'));
  const result=await runAcceptance(f,{
    codeFor:key=>code(f[key].ciMfaSecret),
    launch:{headless:true,args:['--no-sandbox','--no-proxy-server',
      '--host-resolver-rules=MAP staging.zeeship.cloud 172.30.73.10:8443']},
  });
  console.log(JSON.stringify(result));
})().catch(error=>{
  // Locations/static browser codes only; assertion messages may contain secrets.
  const locations=[...String(error.stack).matchAll(/(browser-acceptance|integration-browser)\.cjs:(\d+):(\d+)/g)]
    .map(x=>({file:x[1]+'.cjs',line:Number(x[2]),column:Number(x[3])}));
  console.log(JSON.stringify({code:'PRIVATE_DEMO_BROWSER_TEST_FAILED',locations,
    browser_codes:[...new Set(String(error.message).match(/ERR_[A-Z_]+/g)||[])]}));
  process.exit(1);
});
