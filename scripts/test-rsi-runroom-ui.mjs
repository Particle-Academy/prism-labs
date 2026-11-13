import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {build} from 'vite';
import {chromium} from 'playwright';
import {createRsiRunRoomFixture} from './fixtures/rsi-run-room.mjs';

// Actual application entry, Fancy providers and Inertia pages. Every request is
// intercepted; this fixture cannot reach a server, database, real account or provider.
const cwd=process.cwd(),dir=path.resolve(process.argv[2]??'storage/framework/testing/rsi-runroom-browser');
assert(dir.startsWith(path.resolve('storage/framework/testing')+path.sep));
assert(!fs.existsSync(path.join(cwd,'.env')));assert(!fs.existsSync(path.join(dir,'final.json')));
fs.mkdirSync(dir,{recursive:true});
const hash=file=>createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const git=args=>execFileSync('git',args,{encoding:'utf8',windowsHide:true,timeout:30000}).trim();
const source=git(['ls-files','--cached','--others','--exclude-standard','-z']).split('\0').filter(Boolean).map(file=>({file,sha256:hash(file)}));
const subject={head:git(['rev-parse','HEAD']),entrySha256:hash('resources/js/app.tsx'),lockSha256:hash('package-lock.json'),driverSha256:hash(new URL(import.meta.url))};
const {pin,contract,reviewDocuments,evidence}=createRsiRunRoomFixture();
const results=[],errors=[],requests=[];let browser;
try {
 const blade=fs.readFileSync('resources/views/app.blade.php','utf8');assert(blade.includes("@vite(['resources/css/app.css', 'resources/js/app.tsx'])"));assert(blade.includes('class="dark"'));
 const virtual='\0rsi-native-dom-entry';
 const bundle=await build({configFile:path.resolve('vite.config.js'),base:'/',logLevel:'error',plugins:[{
  name:'rsi-real-app-native-dom-fixture',resolveId:id=>id==='rsi-native-dom-entry'?virtual:undefined,
  load:id=>id===virtual?`import ${JSON.stringify(path.resolve('resources/js/app.tsx').replaceAll('\\','/'))};
  import {createDomNavigationAgent,OFF_LIMITS_ERROR} from '@particle-academy/agent-integrations/dom-navigation';
  window.__rsiTestDom=createDomNavigationAgent();window.__rsiTestFenceError=OFF_LIMITS_ERROR;`:undefined,
 }],build:{write:false,minify:false,rollupOptions:{input:{app:'rsi-native-dom-entry',styles:path.resolve('resources/css/app.css')}}}});
 const output=(Array.isArray(bundle)?bundle[0]:bundle).output,entry=output.find(x=>x.type==='chunk'&&x.isEntry&&x.name==='app');assert(entry);
 const files=new Map(output.map(x=>['/'+x.fileName,x.type==='chunk'?x.code:x.source]));
 const css=output.filter(x=>x.fileName.endsWith('.css')).map(x=>`<link rel="stylesheet" href="/${x.fileName}">`).join('');
 browser=await chromium.launch({headless:true});const page=await browser.newPage({offline:true,serviceWorkers:'block',viewport:{width:1440,height:1000}});page.setDefaultTimeout(15000);page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());await page.clock.install();
 let component='Lab/RsiSpecificationReview',intent=null,polls=0,paidPosts=0,approvalPosts=0,stopPosts=0,selected=evidence.run.selected_lane;
 const specPath='/lab/benchmarks/specs/'+contract.id,runPath='/runs/'+evidence.run.id;
 const props=()=>component==='Lab/RsiSpecificationReview'?{contract,reviewDocuments,instrumentCatalog:[pin],errors:{},flash:{}}:{evidence:{...evidence,run:{...evidence.run,selected_lane:selected}},errors:{},flash:{}};
 const inertia=url=>({component,props:props(),url,version:'rsi-owned-fixture',clearHistory:false,encryptHistory:false});
 async function assertFenced(action,handle,value){
  const answer=await page.evaluate(({action,handle,value})=>({result:window.__rsiTestDom[action](handle,value),expected:window.__rsiTestFenceError}),{action,handle,value});
  assert.deepEqual(answer.result,{ok:false,error:answer.expected});
 }
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url()),p=url.pathname;requests.push({method:req.method(),path:p});
  if(url.origin!=='https://rsi.test'){errors.push('External request refused');return route.abort('blockedbyclient');}
  if(files.has(p))return route.fulfill({contentType:p.endsWith('.css')?'text/css':p.endsWith('.woff2')?'font/woff2':p.endsWith('.js')?'text/javascript':'application/octet-stream',body:files.get(p)});
  if(req.method()==='POST'){
   const data=req.postDataJSON();
   if(p==='/lab/run-intents'){
    assert.equal(intent,null);assert.equal(data.action,'benchmarks.launch');assert.equal(data.resource,contract.id);assert.deepEqual(data.payload,{});assert.equal(data.cap_usd,'1');assert.equal(req.headers()['x-csrf-token'],'owned-rsi-csrf');
    intent={id:'40000000-0000-4000-8000-000000000001',digest:'9'.repeat(64),action:data.action,resource:data.resource,payload:{input:data.payload},status:'draft',cap_microusd:1000000,expires_at:'2099-01-01T00:00:00Z'};approvalPosts++;return route.fulfill({status:201,json:intent});
   }
   if(p===`/lab/run-intents/${intent?.id}/approve`){assert.equal(intent.status,'draft');assert.deepEqual(data,{digest:intent.digest});intent.status='approved';approvalPosts++;return route.fulfill({json:intent});}
   if(p===`/lab/run-intents/${intent?.id}/go`){assert.equal(intent.status,'approved');assert.deepEqual(data,{digest:intent.digest,cap_usd:'1',confirmation:'GO'});intent.status='ready';approvalPosts++;return route.fulfill({json:intent});}
   if(p===`/lab/benchmarks/${contract.id}/request-approval`||p===`/lab/benchmarks/${contract.id}/approve`){assert.deepEqual(data,{digest:contract.digest});contract.status=p.endsWith('/approve')?'approved':'awaiting_approval';if(contract.status==='approved'){contract.approved_by=1;contract.approved_digest=contract.digest;contract.approved_at='2026-11-12T00:00:00Z';}return route.fulfill({headers:{'X-Inertia':'true'},json:inertia(specPath)});}
   if(p===`/lab/benchmarks/${contract.id}/launch`){assert.equal(intent?.status,'ready');assert.equal(req.headers()['x-lab-run-id'],intent.id);assert.deepEqual(data,{});intent.status='running';paidPosts++;component='Lab/RsiRunRoom';return route.fulfill({headers:{'X-Inertia':'true'},json:inertia(runPath)});}
   if(p===`/lab/benchmarks/runs/${evidence.run.id}/stop`){stopPosts++;evidence.run.status='cancelled';evidence.run.cancelled_at='2026-11-12T00:00:01Z';return route.fulfill({headers:{'X-Inertia':'true'},json:inertia(runPath)});}
   throw new Error('Unexpected fixture POST');
  }
  if(p===runPath+'/evidence')return route.fulfill({contentType:'application/json',headers:{'Content-Disposition':'attachment; filename="rsi-evidence.json"'},body:JSON.stringify(props().evidence)});
  if(p===runPath){component='Lab/RsiRunRoom';if(url.searchParams.has('lane'))selected=url.searchParams.get('lane');assert(evidence.lanes.some(l=>l.id===selected));if(req.headers()['x-inertia-partial-data']==='evidence')polls++;}
  else if(p===specPath)component='Lab/RsiSpecificationReview';
  else {errors.push('Unexpected fixture GET '+p);return route.fulfill({status:404,body:'Owned route refused'});}
  if(req.headers()['x-inertia'])return route.fulfill({headers:{'X-Inertia':'true'},json:inertia(p+url.search)});
  return route.fulfill({contentType:'text/html',body:`<!doctype html><html lang="en" class="dark" style="color-scheme: dark"><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="owned-rsi-csrf">${css}</head><body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased"><div id="app"></div><script type="application/json" data-page="app">${JSON.stringify(inertia(p+url.search)).replaceAll('<','\\u003c')}</script><script type="module" src="/${entry.fileName}"></script></body></html>`});
 });
 await page.goto('https://rsi.test'+specPath);await page.locator('[data-surface="rsi-contract"]').waitFor();
 const complete=await page.locator('[data-field="rsi-complete-contract"]').textContent();
 for(const value of [contract.specification.brief,'previous_revision','fixtures','assertions','instruments','package_ref','harness_ref','max_calls','timeout_seconds','max_output_tokens','approved_digest',contract.digest])assert(complete.includes(value),'Complete frozen document must show '+value);
 assert(complete.includes('9007199254740993')&&complete.includes('9223372036854775807'));
 assert(!complete.includes('9007199254740992')&&!complete.includes('9223372036854776000'));
 assert((await page.locator('[data-field="rsi-fixtures"]').textContent()).includes('9007199254740993'));
 assert((await page.locator('[data-field="rsi-assertions"]').textContent()).includes('9223372036854775807'));
 results.push('authoritative review strings preserve large integer fixture sizes and expectations');
 assert.equal(await page.evaluate(()=>window.rsiInjected),undefined);assert(await page.locator('#rsi-spec-action').isDisabled());assert.equal(paidPosts,0);results.push('full frozen v1 contract, escaped brief and disabled owner action');
 await assertFenced('click','rsi-review-complete');assert.equal(await page.locator('#rsi-review-complete').isChecked(),false);
 await assertFenced('click','rsi-spec-action');assert.equal(intent,null);assert.equal(approvalPosts,0);
 assert.equal(await page.evaluate(()=>JSON.stringify(window.__rsiTestDom.describe()).includes('rsi-review-complete')),false);
 results.push('installed native agent cannot review or approve the frozen contract');
 await page.locator('#rsi-review-complete').click();await page.locator('#rsi-spec-action').click();await page.waitForFunction(()=>document.querySelector('#rsi-spec-action')?.textContent?.includes('Approve this frozen contract'));
 assert(await page.locator('#rsi-spec-action').isDisabled());assert.equal(paidPosts,0);await page.locator('#rsi-review-complete').click();await page.locator('#rsi-spec-action').click();await page.waitForFunction(()=>document.querySelector('#rsi-spec-action')?.textContent?.includes('Review paid GO'));
 assert(await page.locator('#rsi-spec-action').isDisabled());results.push('exact digest review and approval transitions reset human confirmation');
 await page.locator('#rsi-review-complete').click();await page.locator('#rsi-spec-action').click();await page.getByLabel('USD cap for this run (up to $5)',{exact:true}).waitFor();
 await assertFenced('setField','paid-run-cap','5');assert.equal(await page.locator('#paid-run-cap').inputValue(),'');
 await page.getByLabel('USD cap for this run (up to $5)',{exact:true}).fill('1');assert.equal(paidPosts,0);
 await assertFenced('click','paid-run-prepare');assert.equal(intent,null);
 await page.getByRole('button',{name:'Prepare exact intent',exact:true}).click();await page.getByRole('button',{name:'Approve this scope and cap',exact:true}).waitFor();assert.equal(paidPosts,0);assert.deepEqual(JSON.parse(await page.getByLabel('Frozen request',{exact:true}).textContent()),{});
 await assertFenced('click','paid-run-approve');assert.equal(intent.status,'draft');assert.equal(approvalPosts,1);
 await page.getByRole('button',{name:'Approve this scope and cap',exact:true}).click();await page.getByRole('button',{name:'GO — launch this run',exact:true}).waitFor();assert.equal(paidPosts,0);
 await assertFenced('click','paid-run-go');assert.equal(intent.status,'approved');assert.equal(approvalPosts,2);
 await page.getByRole('button',{name:'GO — launch this run',exact:true}).click();await page.locator('[data-surface="rsi-run-room"]').waitFor();assert.equal(paidPosts,1);assert.equal(approvalPosts,3);results.push('launch withheld until exact native paid-intent approve and GO phases');
 results.push('installed native agent cannot set the cap or prepare, approve or GO');
 assert((await page.locator('[data-field="rsi-costs"]').first().textContent()).includes('Known charge: Unknown'));assert((await page.locator('main').textContent()).includes('Unjudged'));assert.equal(await page.locator('[data-selected-lane]').getAttribute('data-selected-lane'),evidence.lanes[0].id);
 const runDocument=await page.locator('[data-field="rsi-complete-contract"]').textContent();assert(runDocument.includes('9007199254740993')&&runDocument.includes('9223372036854775807'));
 await page.locator(`[data-lane-id="${evidence.lanes[1].id}"]`).click();await page.waitForFunction(id=>document.querySelector('[data-selected-lane]')?.getAttribute('data-selected-lane')===id,evidence.lanes[1].id);
 assert((await page.locator('[data-selected-lane]').textContent()).includes('unsupported'));const href=await page.getByRole('link',{name:'Export evidence',exact:true}).getAttribute('href');assert(href.endsWith('?lane='+selected));results.push('persisted lane switching, unsupported and Unknown evidence, selected export binding');
 const downloadPromise=page.waitForEvent('download');await page.getByRole('link',{name:'Export evidence',exact:true}).click();const download=await downloadPromise;assert.equal(download.suggestedFilename(),'rsi-evidence.json');results.push('bounded evidence attachment download');
 const before=polls,response=page.waitForResponse(r=>new URL(r.url()).pathname===runPath&&r.request().headers()['x-inertia-partial-data']==='evidence');await page.clock.fastForward(8100);await response;assert(polls>before&&polls<=before+2);results.push('visible active run uses bounded partial evidence polling');
 await page.screenshot({path:path.join(dir,'desktop.png'),fullPage:true});await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(dir,'mobile.png'),fullPage:true});
 const layout=await page.evaluate(()=>({viewport:window.innerWidth,width:document.documentElement.scrollWidth,overflow:[...document.querySelectorAll('main *')].filter(el=>el.getBoundingClientRect().right>window.innerWidth+2).slice(0,20).map(el=>({tag:el.tagName,class:el.className,width:el.getBoundingClientRect().width}))}));fs.writeFileSync(path.join(dir,'mobile-layout.json'),JSON.stringify(layout,null,2)+'\n',{flag:'wx'});assert(layout.width<=layout.viewport+2,'Mobile page must not force horizontal viewport overflow');results.push('desktop and mobile real-page render');
 await assertFenced('click','rsi-stop');assert.equal(stopPosts,0);assert.equal(evidence.run.status,'running');results.push('installed native agent cannot operate emergency stop');
 await page.getByRole('button',{name:'Emergency stop',exact:true}).click();await page.waitForFunction(()=>!document.querySelector('[data-action="rsi-stop"]'));assert.equal(stopPosts,1);assert.equal(evidence.allocation.costs.pending_microusd,100000);assert.equal(evidence.allocation.costs.known_charge_microusd,null);results.push('emergency stop retains uncertain allocation and removes new-work control');
 assert.deepEqual(errors,[]);for(const f of source)assert.equal(hash(f.file),f.sha256);
 fs.writeFileSync(path.join(dir,'final.json'),JSON.stringify({at:new Date().toISOString(),status:'PASS',subject,groups:results,requests,paidPosts,approvalPosts,stopPosts,allNetworkIntercepted:true,realProviderRequests:0,syntheticOwnerOnlyNativeAuthenticationCoveredInPHP:true,source},null,2)+'\n',{flag:'wx'});console.log(JSON.stringify({status:'RSI_BROWSER_PASS',groups:results.length}));
} catch(error){fs.writeFileSync(path.join(dir,'final.json'),JSON.stringify({at:new Date().toISOString(),status:'BLOCK_RETAINED',subject,groups:results,errors:[...errors,error.message],requests},null,2)+'\n',{flag:'wx'});throw error;}
finally {await browser?.close();}
