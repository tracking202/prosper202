'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

class Link {
    constructor(href) { this.tagName='A'; this.nodeType=1; this.attrs={href}; }
    getAttribute(k) { return Object.hasOwn(this.attrs,k) ? this.attrs[k] : null; }
    setAttribute(k,v) { this.attrs[k]=String(v); }
    hasAttribute(k) { return Object.hasOwn(this.attrs,k); }
    removeAttribute(k) { delete this.attrs[k]; }
}
const links = [new Link('https://offer.test/?sid=[[subid]]&acip=42'), new Link('https://offer.test/second?sid=[[subid]]&acip=42')];
const document = {baseURI:'https://page.test/', documentElement:{}, querySelectorAll: (s) => s==='a[href]' ? links : [], addEventListener() {}};
const timers = new Map();
let nextTimer=0;
const window = {setTimeout: (f) => { const id=++nextTimer; timers.set(id,f); return id; }, clearTimeout: (id) => timers.delete(id)};
const context={window,document,URL,WeakMap,Object,encodeURIComponent,Promise};
vm.createContext(context);
vm.runInContext(fs.readFileSync(process.argv[2],'utf8'),context);
const tick = () => new Promise(resolve => setImmediate(resolve));
const subid='p1.1.2.3.42.1700000000.'+'a'.repeat(22);
const fallback=()=>'https://tracker.test/go?acip=42';

(async () => {
    let resolveFirst;
    let calls=0;
    const config={fallback, prepareOffer: () => {calls++; return new Promise(resolve => {resolveFirst=resolve;});}};
    window.p202PurLink.init(config);
    window.p202PurLink.refresh();
    assert.equal(calls,1,'same offer and repeated refresh share one request');
    assert.equal(links[0].getAttribute('href'),fallback());
    resolveFirst(subid);
    await tick();
    assert.equal(config.offerSubids['42'],subid);
    assert.equal(links[0].getAttribute('href'),fallback(),'direct navigation remains disabled by default');
    config.directEnabled=true;
    window.p202PurLink.refresh();
    assert.equal(links[0].getAttribute('href'),'https://offer.test/?sid='+subid+'&acip=42');
    assert.equal(links[1].getAttribute('href'),'https://offer.test/second?sid='+subid+'&acip=42');

    let staleResolve;
    const old={fallback, prepareOffer: () => new Promise(resolve => {staleResolve=resolve;})};
    window.p202PurLink.init(old);
    const current={fallback, directEnabled:false, prepareOffer: () => Promise.reject(new Error('offline'))};
    let failed=0;
    current.prepareOffer=()=>{failed++;return Promise.reject(new Error('offline'));};
    window.p202PurLink.init(current);
    staleResolve(subid);
    await tick();
    assert.equal(old.offerSubids,undefined,'old pageview response is discarded');
    assert.equal(failed,1);
    window.p202PurLink.refresh();
    assert.equal(failed,1,'a scheduled retry is not multiplied by refresh');
    for(let i=0;i<2;i++) { const [id,fn]=timers.entries().next().value; timers.delete(id); fn(); await tick(); }
    assert.equal(failed,3,'only three requests');
    window.p202PurLink.refresh();
    assert.equal(failed,3,'exhausted requests keep the fallback');
    assert.equal(links[0].getAttribute('href'),fallback());

    let seen;
    window.fetch=(url,opts)=>{seen={url,opts}; return Promise.resolve({ok:true,json:()=>Promise.resolve({subid})});};
    const prep=window.p202PurLink.createPreparer('https://tracker.test/tracking202/static/purlink-prepare.php','exit.secret');
    assert.equal(await prep('42'),subid);
    assert.equal(seen.url,'https://tracker.test/tracking202/static/purlink-prepare.php');
    assert.equal(seen.opts.body,'proof=exit.secret&campaign=42');
    assert.equal(seen.opts.credentials,'omit');
    assert.equal(seen.opts.redirect,'error');
    assert.equal(seen.opts.referrerPolicy,'no-referrer');
    assert.equal(timers.size,0,'completed request cancels its timeout');
    console.log('PurLink asynchronous preparation harness passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
