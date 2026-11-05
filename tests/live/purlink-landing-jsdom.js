#!/usr/bin/env node
'use strict';
// Requires jsdom installed separately and a disposable instance with a
// pre-seeded advanced page and two same-account public campaign IDs.
const {JSDOM, ResourceLoader, VirtualConsole}=require('jsdom');
const assert=require('assert');
const args=process.argv.slice(2);
if(args.length!==5 || args[4]!=='--ack-disposable-database' || !args.slice(1,4).every(v=>/^[1-9][0-9]*$/.test(v))) {
    throw new Error('Usage: node purlink-landing-live.js BASE ADVANCED_PAGE CAMPAIGN OTHER_CAMPAIGN --ack-disposable-database');
}
const [base,page,campaign,other]=args;
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function waitFor(condition,why){for(let i=0;i<200;i++){if(condition())return;await sleep(40);}throw new Error('Timed out waiting for '+why);}
async function run(refused, moduleUnavailable=false){
    let urls=[], requests=[], release=null, errors=[];
    class Recorder extends ResourceLoader {
        fetch(url,options){urls.push(url); if(moduleUnavailable && url.includes('/202-js/p202-purlink.js'))return null; return super.fetch(url,options);}
    }
    const vc=new VirtualConsole();
    vc.on('jsdomError',e=>errors.push(e.message));
    const src=base+'/tracking202/static/landing.php?lpip='+page+'&p202_purlink=1';
    const fallback=base+'/tracking202/redirect/go.php?acip='+campaign;
    const html='<!doctype html><html><head><script src="'+src.replace(/&/g,'&amp;')+'"></script></head><body>'+
        '<a id="offer" data-p202-purlink data-t202rawlink="https://offer.example/?sid=[[subid]]&amp;acip='+campaign+'" href="'+fallback+'">Offer</a></body></html>';
    const dom=new JSDOM(html,{url:base+'/test-page',runScripts:'dangerously',resources:new Recorder(),pretendToBeVisual:true,virtualConsole:vc,beforeParse(w){
        w.AbortController=AbortController;
        if(refused){
            w.p202={consent:false};
            Object.defineProperty(w,'localStorage',{get(){throw new Error('storage blocked');}});
        }
        w.fetch=(input,options)=>{
            if(String(input).includes('/purlink-prepare.php')) {
                requests.push({url:String(input),body:options.body});
                return new Promise((resolve,reject)=>{release=()=>fetch(input,options).then(resolve,reject);});
            }
            return fetch(input,options);
        };
    }});
    const w=dom.window;
    try {
        await waitFor(()=>urls.some(u=>u.includes('/record.php?')),'record request');
        if(moduleUnavailable){
            await waitFor(()=>urls.some(u=>u.includes('/202-js/p202-purlink.js')),'module request');
            await sleep(300);
            assert.equal(requests.length,0,'unavailable module cannot prepare a link');
            assert.equal(w.document.querySelector('#offer').href,fallback,'unavailable module leaves the redirect');
        } else if(refused){
            await sleep(300);
            const record=urls.find(u=>u.includes('/record.php?'));
            assert.equal(new URL(record).searchParams.get('p202_consent'),'0','refusal survives blocked storage');
            assert.equal(requests.length,0,'refusal does not prepare a link');
        } else {
            await waitFor(()=>requests.length===1,'preparation request');
            assert.ok(requests[0].body.startsWith('proof=exit.'));
            assert.ok(!requests[0].url.includes('proof='));
            assert.equal(w.document.querySelector('#offer').href,fallback,'direct navigation stays disabled');
            w.p202.consent(false);
            release();
            await sleep(200);
            let second=w.document.createElement('a');
            second.setAttribute('data-p202-purlink','');
            second.setAttribute('data-t202rawlink','https://offer.example/?sid=[[subid]]&acip='+other);
            second.href=base+'/tracking202/redirect/go.php?acip='+other;
            w.document.body.appendChild(second);
            await sleep(200);
            assert.equal(requests.length,1,'revocation prevents new preparation');
            assert.equal(w.document.querySelector('#offer').href,fallback,'late proof cannot enable a direct link');
        }
        assert.deepStrictEqual(errors,[]);
    } finally {w.close();}
}
(async()=>{await run(false);await run(true);await run(false,true);console.log('Landing pageview, preparation and consent revocation checks passed in jsdom');})().catch(e=>{console.error(e);process.exitCode=1;});
