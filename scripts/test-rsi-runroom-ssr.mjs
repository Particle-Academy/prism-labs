import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {pathToFileURL} from 'node:url';
import {build} from 'vite';
import {chromium} from 'playwright';
import {createRsiRunRoomFixture} from './fixtures/rsi-run-room.mjs';

assert(!fs.existsSync('.env')&&!fs.existsSync('.env.testing'));
const directory=path.resolve(process.argv[2]??'storage/framework/testing/rsi-runroom-ssr');
assert(directory.startsWith(path.resolve('storage/framework/testing')+path.sep));
assert(!fs.existsSync(directory));fs.mkdirSync(directory,{recursive:true});
const temporary=fs.mkdtempSync(path.resolve('storage/framework/testing/rsi-ssr-'));
const entry='\0rsi-ssr-entry',transport='\0rsi-ssr-capture',groups=[];let browser;
try {
 const built=await build({configFile:false,logLevel:'error',plugins:[{
  name:'rsi-native-ssr-without-listener',enforce:'pre',
  resolveId(id){if(id==='rsi-ssr-entry')return entry;if(id==='@inertiajs/react/server')return transport;},
  load(id){
   if(id===transport)return 'export default function capture(handler){globalThis.__rsiSsrHandler=handler;}';
   if(id===entry)return `import ${JSON.stringify(path.resolve('resources/js/ssr.tsx').replaceAll('\\','/'))};export const renderPage=packet=>globalThis.__rsiSsrHandler(packet);`;
  },
 }],ssr:{noExternal:['@particle-academy/fancy-inertia','@inertiajs/react']},build:{ssr:true,write:false,rollupOptions:{input:'rsi-ssr-entry',output:{entryFileNames:'probe.mjs',chunkFileNames:'[name]-[hash].mjs'}}}});
 const output=(Array.isArray(built)?built[0]:built).output,code=output.filter(item=>item.type==='chunk').map(item=>item.code).join('\n');
 assert(/__rsiSsrHandler\s*=\s*handler/.test(code),'Refuse execution unless the HTTP listener was captured.');
 assert(!/['"]@inertiajs\/react\/server['"]/.test(code));
 assert(!/['"]@particle-academy\/fancy-inertia\/server['"]/.test(code));
 for(const item of output){
  const target=path.resolve(temporary,item.fileName);assert(target.startsWith(temporary+path.sep));
  fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,item.type==='chunk'?item.code:item.source,{flag:'wx'});
 }
 const probe=await import(pathToFileURL(path.join(temporary,'probe.mjs')).href),{pin,contract,reviewDocuments,evidence}=createRsiRunRoomFixture();
 browser=await chromium.launch({headless:true});const page=await browser.newPage({offline:true,serviceWorkers:'block'});
 await page.route('**/*',route=>route.abort('blockedbyclient'));
 const render=async(component,props)=>{
  const result=await probe.renderPage({component,props:{...props,errors:{},flash:{}},url:'/owned-rsi-ssr',version:'synthetic-rsi-ssr',clearHistory:false,encryptHistory:false});
  assert(result.body.includes('data-surface="rsi-contract"'));
  assert(!result.body.includes('<script>window.rsiInjected'),'Untrusted brief remains text in native SSR.');
  fs.writeFileSync(path.join(directory,component.replace('/','-')+'.html'),result.body,{flag:'wx'});
  await page.setContent(result.body);
  assert.equal(await page.locator('script:not([type="application/json"])').count(),0);
  const payload=JSON.parse(await page.locator('script[data-page="app"][type="application/json"]').textContent());
  assert.equal(payload.component,component);
  assert.equal((payload.props.contract??payload.props.evidence.contract).specification.brief,contract.specification.brief);
  assert.equal(await page.evaluate(()=>window.rsiInjected),undefined);
  const complete=await page.locator('[data-field="rsi-complete-contract"]').textContent();
  for(const value of [contract.specification.brief,'previous_revision','fixtures','assertions','instruments','package_ref','harness_ref','max_calls','timeout_seconds','max_output_tokens','approved_digest',contract.digest])assert(complete.includes(value));
  assert(complete.includes('9007199254740993')&&complete.includes('9223372036854775807'));
  assert(!complete.includes('9007199254740992')&&!complete.includes('9223372036854776000'));
  assert((await page.locator('[data-field="rsi-fixtures"]').textContent()).includes('9007199254740993'));
  assert((await page.locator('[data-field="rsi-assertions"]').textContent()).includes('9223372036854775807'));
 };
 await render('Lab/RsiSpecificationReview',{contract,reviewDocuments,instrumentCatalog:[pin]});
 assert(await page.locator('#rsi-spec-action').isDisabled());
 for(const id of ['rsi-review-complete','rsi-spec-action'])assert.equal(await page.locator('#'+id).evaluate(element=>element.closest('[data-agent-off-limits]')?.getAttribute('data-agent-off-limits')),'consent');
 groups.push('actual SSR complete frozen document, escaped brief and disabled human-only review controls');
 await render('Lab/RsiRunRoom',{evidence});
 assert.equal(await page.locator('#rsi-stop').evaluate(element=>element.closest('[data-agent-off-limits]')?.getAttribute('data-agent-off-limits')),'consent');
 assert((await page.locator('main').textContent()).includes('Known charge: Unknown'));
 assert((await page.locator('main').textContent()).includes('Unjudged'));
 assert.equal(await page.locator('[data-selected-lane]').getAttribute('data-selected-lane'),evidence.lanes[0].id);
 groups.push('actual SSR run-room evidence, Unknown measurements, selected lane and stop consent boundary');
 fs.writeFileSync(path.join(directory,'final.json'),JSON.stringify({at:new Date().toISOString(),status:'PASS',groups,actualSsrEntry:true,actualFancyAndInertiaRenderers:true,onlyHttpListenerCaptured:true,noDatabaseServiceProviderOrEnvironmentOperation:true},null,2)+'\n',{flag:'wx'});
 console.log(JSON.stringify({status:'RSI_SSR_PASS',groups:groups.length}));
} catch(error){fs.writeFileSync(path.join(directory,'failure.json'),JSON.stringify({status:'BLOCK_RETAINED',groups,error:error.message},null,2)+'\n',{flag:'wx'});throw error;}
finally {
 await browser?.close();
 assert.equal(path.dirname(fs.realpathSync(temporary)),fs.realpathSync('storage/framework/testing'));
 fs.rmSync(temporary,{recursive:true,force:true});delete globalThis.__rsiSsrHandler;
}
