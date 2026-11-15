// Production assets and native PHP-generated props, entirely intercepted. No live server or providers.
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';

const cwd=process.cwd(), hash=file=>createHash('sha256').update(fs.readFileSync(file)).digest('hex');
assert(!fs.existsSync(path.join(cwd,'.env')),'Models browser controls require a credential-free checkout.');
const goldenFile=path.join(cwd,'tests/fixtures/model-catalogue-browser.json'),golden=JSON.parse(fs.readFileSync(goldenFile));
assert.equal(golden.initial.providers.length,6);assert.equal(golden.initial.providers.flatMap(p=>p.models).length,10);
const manifestFile=path.join(cwd,'public/build/manifest.json'),manifest=JSON.parse(fs.readFileSync(manifestFile)),entry=manifest['resources/js/app.tsx'];
assert(entry?.file,'Build the actual production client before browser controls.');
const css=[...new Set([...Object.values(manifest).flatMap(e=>e.css??[]),manifest['resources/css/app.css']?.file].filter(Boolean))];
const files=new Map(),types={'.js':'text/javascript','.css':'text/css','.json':'application/json','.woff':'font/woff','.woff2':'font/woff2','.svg':'image/svg+xml','.png':'image/png','.webp':'image/webp','.ico':'image/x-icon'};
function addAssets(directory,prefix){if(!fs.existsSync(directory))return;for(const item of fs.readdirSync(directory,{withFileTypes:true})){const target=path.join(directory,item.name),url=prefix+'/'+item.name;assert(!item.isSymbolicLink(),'Fixture assets must stay inside the owned build.');if(item.isDirectory())addAssets(target,url);else if(types[path.extname(item.name)])files.set(url,{body:fs.readFileSync(target),contentType:types[path.extname(item.name)]});}}
addAssets(path.join(cwd,'public/build'),'/build');addAssets(path.join(cwd,'public/fonts'),'/fonts');
const artifacts=path.join(cwd,'models-browser-artifacts');fs.mkdirSync(artifacts,{recursive:true});
const report=path.join(cwd,'prism-labs-models-ci.json'),subject={head:execFileSync('git',['rev-parse','HEAD'],{cwd,encoding:'utf8',windowsHide:true}).trim(),dirty:execFileSync('git',['status','--porcelain','--untracked-files=no'],{cwd,encoding:'utf8',windowsHide:true}).trim()!=='',driverSha256:hash(new URL(import.meta.url)),goldenSha256:hash(goldenFile),pageSha256:hash(path.join(cwd,'resources/js/pages/Lab/Models.tsx')),cssSha256:hash(path.join(cwd,'resources/css/lab-models.css')),manifestSha256:hash(manifestFile),lockSha256:hash(path.join(cwd,'package-lock.json'))};
fs.writeFileSync(report,JSON.stringify({at:new Date().toISOString(),status:'RUNNING',subject})+'\n');
const results=[],screenshots=[],errors=[],browser=await chromium.launch({headless:true});
try{
 for(const [name,viewport]of [['desktop',{width:1440,height:1050}],['mobile',{width:390,height:844}]]){
  const context=await browser.newContext({viewport,isMobile:name==='mobile',hasTouch:name==='mobile',serviceWorkers:'block'}),page=await context.newPage();page.setDefaultTimeout(15000);page.on('pageerror',e=>errors.push(e.message));let active=structuredClone(golden.initial),posts=[];
  const inertia=()=>({component:'Lab/Models',props:{name:'Prism Lab',labEnabled:true,auth:{user:null},flash:{},errors:{},...active},url:'/lab/models',version:'models-fixture-v1',clearHistory:false,encryptHistory:false});
  await page.route('**/*',async route=>{
   const request=route.request(),url=new URL(request.url());if(url.origin!=='https://models.test'){errors.push('Unexpected external request '+url.origin);return route.abort('blockedbyclient');}
   if(files.has(url.pathname))return route.fulfill(files.get(url.pathname));
   if(request.method()==='POST'){
    const body=request.postDataJSON();posts.push({path:url.pathname,body});
    if(url.pathname==='/lab/models/refresh')active=structuredClone(golden.refreshed);
    else if(url.pathname==='/lab/models')active={...active,selection:body.allowed,allowed:body.allowed.filter(key=>active.providers.some(p=>p.models.some(m=>m.key===key))),unavailable:active.unavailable.filter(model=>body.allowed.includes(model.key))};
    else{errors.push('Unexpected POST '+url.pathname);return route.fulfill({status:422,json:{message:'No real mutations.'}});}
    return route.fulfill({headers:{'X-Inertia':'true'},json:inertia()});
   }
   if(url.pathname==='/lab/agent')return route.fulfill({json:{messages:[],drafts:[]}});
   if(url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
   if(url.pathname!=='/lab/models'){errors.push('Unexpected fixture GET '+url.pathname);return route.fulfill({status:404,body:'Unexpected fixture route'});}
   if(request.headers()['x-inertia'])return route.fulfill({headers:{'X-Inertia':'true'},json:inertia()});
   const data=JSON.stringify(inertia()).replaceAll('<','\\u003c');
   return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-csrf">${css.map(file=>`<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="app"></div><script type="application/json" data-page="app">${data}</script><script type="module" src="/build/${entry.file}"></script></body></html>`});
  });
  const revealProvider=async key=>{
   const provider=page.locator(`[data-model-provider="${key}"]`),toggle=provider.locator(`[data-model-provider-toggle="${key}"]`);
   await provider.getByRole('heading',{level:2}).waitFor();
   if(await toggle.count()&&await toggle.getAttribute('aria-expanded')==='false')await toggle.click();
   if(active.providers.find(p=>p.key===key)?.models.length)await provider.locator('[data-react-fancy-accordion-content]').waitFor();
  };
  const revealUnknown=async key=>{
   const provider=page.locator(`[data-model-provider="${key}"]`),toggle=provider.locator(`[data-model-unknown-toggle="${key}"]`);
   if(await toggle.count()&&await toggle.getAttribute('aria-expanded')==='false')await toggle.click();
  };
  const revealAll=async()=>{for(const provider of active.providers)if(provider.models.length){await revealProvider(provider.key);await revealUnknown(provider.key);}};
  const capture=async suffix=>{const file=path.join(artifacts,`models-${name}${suffix}.png`);await page.screenshot({path:file,fullPage:true});screenshots.push({file,sha256:hash(file)});};
  await page.goto('https://models.test/lab/models');await page.getByRole('heading',{name:'Models and prices',exact:true}).waitFor();
  assert.equal(await page.locator('[data-model-key]').count(),0,'Provider lists start collapsed, not thousands of pixels tall');
  assert.equal(await page.locator('.lab-models-keyless [data-model-provider]').count(),1,'Unconfigured providers use compact rows');
  const firstProviders=await page.locator('[data-model-provider]').evaluateAll(nodes=>nodes.slice(0,3).map(node=>node.getAttribute('data-model-provider')));assert.deepEqual(firstProviders,['anthropic','openai','replicate'],'Model-bearing providers first');
  const toggleSpacing=await page.locator('[data-model-provider-toggle]').first().evaluate(button=>{const name=button.querySelector('.lab-models-provider-name').getBoundingClientRect(),action=button.querySelector('.lab-models-provider-action').getBoundingClientRect();return action.x-name.right;});assert(toggleSpacing>=8,`${name} provider label/action must stay visibly separated`);
  await capture('-overview');
  await revealAll();
  assert.equal(await page.locator('[data-model-provider]').count(),6);assert.equal(await page.getByRole('checkbox').count(),10);
  const anthropic=page.locator('[data-model-provider="anthropic"]');assert.equal(await anthropic.locator('[data-model-key]').first().getAttribute('data-model-key'),'anthropic:claude-fable-5-1');
  const fable=anthropic.locator('[data-model-key="anthropic:claude-fable-5-1"]');for(const value of ['$10.00','$50.00','$0.25','$12.50','$20.00'])assert((await fable.innerText()).includes(value));
  assert.equal(await fable.getByRole('link',{name:'Official prices'}).getAttribute('href'),'https://platform.claude.com/docs/en/about-claude/pricing');
  assert.equal(await anthropic.locator('[data-model-key="anthropic:claude-haiku-5-5"] .lab-models-rate').count(),2);
  assert((await anthropic.locator('[data-model-key="anthropic:claude-opus-5"]').innerText()).includes('Legacy'));
  const openai=page.locator('[data-model-provider="openai"]');assert((await openai.innerText()).includes('Deprecated'));assert((await openai.innerText()).includes('Provider creation date: 2024-04-05'));assert(!(await openai.innerText()).includes('1712361441'));
  assert.equal(await openai.getByRole('link',{name:'Model status source'}).getAttribute('href'),'https://developers.openai.com/api/docs/models/gpt-4.1-mini');
  assert.equal(await page.locator('.lab-models-kind').count(),0);
  assert((await page.locator('[data-model-provider="replicate"]').innerText()).includes('public catalogue, not your account'));
  for(const state of ['unsupported','unconfigured','unreachable'])assert.equal(await page.locator(`[data-listing-state="${state}"] [data-model-key]`).count(),0);
  assert((await page.locator('[data-listing-state="unsupported"]').innerText()).includes('not implemented in the installed Prism package'));
  assert((await page.locator('[data-listing-state="unconfigured"]').innerText()).includes('no API key'));
  assert((await page.locator('[data-listing-state="unreachable"]').innerText()).includes("couldn't reach"));
  await page.getByText('<script>window.modelsXss=true</script>',{exact:true}).waitFor();assert.equal(await page.evaluate(()=>window.modelsXss),undefined);assert.equal(await page.locator('.lab-models-row img,.lab-models-row script').count(),0);
  assert(!(await page.content()).includes('fixture-provider-secret'));assert(!(await page.content()).includes('anthropic.fixture'));
  const contrast=await page.getByRole('checkbox').first().evaluate(input=>{
   const label=input.labels?.[0];if(!label)throw new Error('Native checkbox must retain an accessible label');
   const textColor=root=>{const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);let node;while(node=walker.nextNode())if(node.textContent.trim())return getComputedStyle(node.parentElement).color;throw new Error('Expected a visible control label');};
   const canvas=document.createElement('canvas');canvas.width=canvas.height=1;const ctx=canvas.getContext('2d');const luminance=color=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=color;ctx.fillRect(0,0,1,1);const rgb=[...ctx.getImageData(0,0,1,1).data].slice(0,3).map(x=>{x/=255;return x<=0.04045?x/12.92:((x+0.055)/1.055)**2.4;});return rgb[0]*0.2126+rgb[1]*0.7152+rgb[2]*0.0722;};
   const background=getComputedStyle(input.closest('.lab-panel')).getPropertyValue('--k-bg-2'),ratio=(color,base=background)=>{const bg=luminance(base),fg=luminance(color);return (Math.max(bg,fg)+0.05)/(Math.min(bg,fg)+0.05);};const save=document.querySelector('[data-model-action="save"]');return{checkbox:ratio(textColor(label)),refresh:ratio(textColor(document.querySelector('[data-model-action="refresh"]'))),save:ratio(textColor(save),getComputedStyle(save).backgroundColor)};
  });
  assert(contrast.checkbox>=4.5&&contrast.refresh>=4.5&&contrast.save>=4.5,`${name} control text contrast below4.5: ${JSON.stringify(contrast)}`);
  const refreshBox=await page.getByRole('button',{name:'Refresh models',exact:true}).boundingBox();assert(refreshBox.width>=120&&refreshBox.height>=36,`${name} refresh control too narrow/small: ${JSON.stringify(refreshBox)}`);
  const overflow=await page.evaluate(()=>({width:innerWidth,document:document.documentElement.scrollWidth}));assert(overflow.document<=overflow.width+1,`${name} horizontal overflow ${JSON.stringify(overflow)}`);
  await capture('');
  const long=golden.initial.providers.flatMap(p=>p.models).find(m=>m.id.startsWith('future-'));assert(long.key.length>480);await page.locator(`[data-model-key="${long.key}"]`).getByRole('checkbox').check();await page.getByRole('button',{name:'Save selection',exact:true}).click();await page.getByRole('button',{name:'Saved',exact:true}).waitFor();assert(posts[0].body.allowed.includes(long.key));active=structuredClone(golden.initial);posts=[];await page.goto('https://models.test/lab/models');await page.getByRole('heading',{name:'Models and prices',exact:true}).waitFor();await revealAll();
  const future=page.getByRole('checkbox',{name:'Returned unpriced-future',exact:true}),sonnet=page.getByRole('checkbox',{name:'Returned claude-sonnet-5',exact:true});assert.equal(await future.isChecked(),false);assert.equal(await sonnet.isChecked(),true);assert.equal(await page.getByRole('checkbox',{name:'gpt-4.1-mini',exact:true}).isChecked(),true);
  await future.check();assert.equal(posts.length,0,'Checkbox edits never autosave');
  await page.getByRole('button',{name:'Refresh models',exact:true}).click();await page.getByText('No longer offered',{exact:true}).waitFor();await revealAll();await page.getByText('Returned brand-new-after-refresh',{exact:true}).waitFor();assert.equal(posts.length,1);assert.equal(posts[0].path,'/lab/models/refresh');assert.equal(await page.getByRole('checkbox',{name:'Returned unpriced-future',exact:true}).isChecked(),true);assert.equal(await page.getByRole('checkbox',{name:'Returned brand-new-after-refresh',exact:true}).isChecked(),false);
  const withdrawn=page.locator('[data-unavailable-key="anthropic:claude-sonnet-5"]');assert.equal(await withdrawn.getByRole('checkbox').isDisabled(),true);assert((await withdrawn.innerText()).includes('No longer offered'));await withdrawn.getByRole('button',{name:'Remove saved model'}).click();assert.equal(await withdrawn.count(),0);assert.equal(posts.length,1,'Removing a saved model requires Save');
  await page.getByRole('button',{name:'Save selection',exact:true}).click();await page.getByRole('button',{name:'Saved',exact:true}).waitFor();assert.equal(posts.length,2);assert.equal(posts[1].path,'/lab/models');assert.deepEqual([...posts[1].body.allowed].sort(),['anthropic:unpriced-future','openai:gpt-4.1-mini'].sort());assert.equal(await page.getByRole('button',{name:'Saved',exact:true}).isDisabled(),true);
  active=structuredClone(golden.outage);await page.goto('https://models.test/lab/models');await revealAll();const kept=page.locator('[data-unavailable-key="anthropic:unpriced-future"]');await kept.waitFor();assert.equal(await kept.getByRole('checkbox').isDisabled(),true);assert((await kept.innerText()).includes('Your selection is kept'));assert(!(await kept.innerText()).includes('No longer offered'));assert.equal(await kept.getByRole('button',{name:'Remove saved model'}).count(),0);await page.getByRole('checkbox',{name:'gpt-4.1-mini',exact:true}).uncheck();await page.getByRole('button',{name:'Save selection',exact:true}).click();await page.getByRole('button',{name:'Saved',exact:true}).waitFor();assert.deepEqual(posts.at(-1).body.allowed,['anthropic:unpriced-future']);active=structuredClone(golden.recovered);await page.goto('https://models.test/lab/models');await revealAll();const recovered=page.getByRole('checkbox',{name:'Returned unpriced-future',exact:true});await recovered.waitFor();assert.equal(await recovered.isEnabled(),true);assert.equal(await recovered.isChecked(),true);assert.equal(await page.getByRole('checkbox',{name:'gpt-4.1-mini',exact:true}).isChecked(),false);
  active=structuredClone(golden.stale);await page.goto('https://models.test/lab/models');await page.getByText('Prices need checking',{exact:true}).waitFor();assert.equal(await page.evaluate(()=>window.modelsXss),undefined);
  active=structuredClone(golden.scale);await page.goto('https://models.test/lab/models');await page.getByRole('heading',{name:'Models and prices',exact:true}).waitFor();
  const scaleOpenAI=page.locator('[data-model-provider="openai"]'),scaleAnthropic=page.locator('[data-model-provider="anthropic"]');
  assert.equal(await scaleOpenAI.locator('[data-model-key]').count(),0);
  const beforeOpen=await scaleOpenAI.boundingBox(),beforeAnthropic=await scaleAnthropic.boundingBox();assert(beforeAnthropic.y>beforeOpen.y&&beforeAnthropic.y-beforeOpen.y<250,`${name} next provider must not be buried below142 expanded models`);
  await capture('-catalogue-overview');await revealProvider('openai');assert.equal(await scaleOpenAI.locator('[data-model-key]').count(),86,'Priced models first, unselected unknown rows collapsed');
  const sol=scaleOpenAI.locator('[data-model-key="openai:gpt-6.1-sol"]');const solText=await sol.innerText();assert(solText.includes('Cache write · 30m')&&solText.includes('Cache write · 12h'));assert(!solText.includes('Cache write · 5m')&&!solText.includes('Cache write · 1h'));
  const unknownToggle=scaleOpenAI.locator('[data-model-unknown-toggle="openai"]');assert((await unknownToggle.innerText()).includes('56'));await revealUnknown('openai');assert.equal(await scaleOpenAI.locator('[data-model-key]').count(),142);
  assert((await page.evaluate(()=>document.documentElement.scrollWidth))<=viewport.width+1);
  const postCount=posts.length;await page.getByLabel('Filter by model ID',{exact:true}).fill('future-synthetic-055');await page.locator('[data-model-key="openai:future-synthetic-055"]').waitFor();assert.equal(await page.locator('[data-model-key]').count(),1);assert((await page.locator('.lab-models-filter-result').innerText()).includes('1 matching model IDs'));assert.equal(posts.length,postCount,'Filter never saves or changes permission');
  await page.getByLabel('Jump to provider',{exact:true}).selectOption('anthropic');await scaleAnthropic.locator('[data-model-key="anthropic:claude-sonnet-4-5-20250929"]').waitFor();assert.equal(await page.getByLabel('Filter by model ID',{exact:true}).inputValue(),'');assert.equal(await scaleOpenAI.locator('[data-model-key]').count(),0,'Jump closes other provider lists');
  await page.waitForFunction(()=>{const box=document.getElementById('model-provider-anthropic').getBoundingClientRect();return box.top>=-1&&box.top<innerHeight-100;});
  assert((await scaleAnthropic.locator('[data-model-key="anthropic:claude-opus-4-5-20251101"]').innerText()).includes('Legacy'));assert((await scaleAnthropic.locator('[data-model-key="anthropic:claude-sonnet-4-5-20250929"]').innerText()).includes('Deprecated'));assert.equal(posts.length,postCount);await capture('-jump-anthropic');
  await revealProvider('openai');assert.equal(await scaleOpenAI.getByRole('checkbox',{name:'gpt-4.1-mini',exact:true}).isChecked(),true,'Filter/jump preserve saved choices');
  active=structuredClone(golden.authentication);await page.goto('https://models.test/lab/models');const rejected=page.locator('[data-listing-state="authentication_rejected"]');await rejected.waitFor();assert((await rejected.innerText()).includes('Update its credentials or permissions'));assert.equal(await rejected.locator('[data-model-key]').count(),0);assert.equal(await rejected.locator('[data-unavailable-key="anthropic:claude-sonnet-5"]').getByRole('checkbox').isDisabled(),true);assert.equal(await rejected.locator('[data-model-provider-toggle]').count(),0,'Authentication failure remains expanded');assert(!(await page.content()).includes('private-auth-body'));assert(!(await page.content()).includes('fixture-provider-secret'));
  results.push({viewport:name,models:10,providers:6,availabilityStates:5,haikuRates:2,escapedText:true,noOverflow:true,controlContrast:contrast,refreshControl:refreshBox,newModelUnticked:true,refreshPreservesUnsavedAndShowsWithdrawn:true,withdrawnRequiresExplicitRemovalAndSave:true,outageSelectionKeptAndRecovers:true,newAfterRefreshUnticked:true,longIdSaved:true,epochDateFormatted:true,datedLifecycleShown:true,savePosts:3,refreshPosts:1,staleNotice:true,providersInitiallyCollapsed:true,modelBearingProvidersFirst:true,keylessProvidersCompact:true,syntheticScaleModels:142,syntheticScalePriced:86,unknownCount:56,unknownCollapsed:true,filterFindsUnknown:true,jumpClosesOtherProviders:true,correctOpenAiCacheDurations:true,authFailureVisibleAndSelectionKept:true,status:'PASS'});await context.close();
 }
 assert.deepEqual(errors,[],'No unexpected network or browser errors');assert.equal(hash(goldenFile),subject.goldenSha256);assert.equal(hash(path.join(cwd,'resources/js/pages/Lab/Models.tsx')),subject.pageSha256);assert.equal(hash(path.join(cwd,'resources/css/lab-models.css')),subject.cssSha256);
 fs.writeFileSync(report,JSON.stringify({at:new Date().toISOString(),status:'PASS',subject,results,screenshots,productionBuild:true,nativeGeneratedProps:true,allRequestsIntercepted:true,noLiveProviderCalls:true,browserErrors:errors},null,2)+'\n');console.log(JSON.stringify({status:'PASS',viewports:results.length,report,sha256:hash(report)}));
}catch(error){fs.writeFileSync(report,JSON.stringify({at:new Date().toISOString(),status:'FAIL',subject,results,screenshots,error:error.message,browserErrors:errors,allRequestsIntercepted:true,noLiveProviderCalls:true},null,2)+'\n');throw error;}finally{await browser.close();}
