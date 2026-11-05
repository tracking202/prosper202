const {JSDOM}=require('jsdom');
const fs=require('fs'), assert=require('assert'), cp=require('child_process'), path=require('path');
const [base, database, socket, proofFile, click, firstPublic, secondPublic, secondId, ack]=process.argv.slice(2);
if (!base || !database || !socket || !proofFile || ![click,firstPublic,secondPublic,secondId].every(x=>/^[1-9][0-9]*$/.test(x)) || ack!=='--ack-disposable-database') {
 throw Error('Usage: node purlink-report-jsdom.js BASE DATABASE SOCKET PROOF_FILE CLICK FIRST_PUBLIC SECOND_PUBLIC SECOND_ID --ack-disposable-database');
}
const dom=new JSDOM('<a id="a" href="https://a.example/?sid=[[subid]]&amp;acip='+firstPublic+'">A</a><a id="b" href="https://b.example/?sid=[[subid]]&amp;acip='+secondPublic+'">B</a>',{url:base+'/',runScripts:'outside-only'});
const w=dom.window, writes=[];
w.AbortController=AbortController;
w.fetch=(url,opts)=>{const p=fetch(url,opts);if(String(url).includes('purlink-exit.php'))writes.push({body:opts.body,promise:p});return p;};
w.eval(fs.readFileSync(path.join(__dirname,'../../202-js/p202-purlink.js'),'utf8'));
const proof=fs.readFileSync(proofFile,'utf8').trim();
const config={directEnabled:true,fallback:n=>base+'/tracking202/redirect/go.php?acip='+n,prepareOffer:w.p202PurLink.createPreparer(base+'/tracking202/static/purlink-prepare.php',proof),report:w.p202PurLink.createReporter(base+'/tracking202/static/purlink-exit.php',proof,()=>true)};
w.p202PurLink.init(config);
w.document.addEventListener('click',e=>e.preventDefault());
w.document.addEventListener('auxclick',e=>e.preventDefault());
const started=Date.now();
async function run(){
 while(!config.offerSubids || !config.offerSubids[firstPublic] || !config.offerSubids[secondPublic]){if(Date.now()-started>5000)throw Error('preparation timeout');await new Promise(r=>setTimeout(r,20));}
 w.document.querySelector('#a').dispatchEvent(new w.MouseEvent('click',{bubbles:true,cancelable:true,button:0}));
 w.document.querySelector('#b').dispatchEvent(new w.MouseEvent('auxclick',{bubbles:true,cancelable:true,button:1}));
 assert.equal(writes.length,2);
 assert.ok(writes[0].body.includes('&sequence=1&campaign='+firstPublic));
 assert.ok(writes[1].body.includes('&sequence=2&campaign='+secondPublic));
 const responses=await Promise.all(writes.map(x=>x.promise));assert.ok(responses.every(r=>r.ok));
 const out=cp.execFileSync('mysql',['--protocol=socket','--socket='+socket,'-uroot',database,'-N','-B','-e','SELECT sequence,campaign_id FROM 202_purlink_exit_order WHERE click_id='+click],{encoding:'utf8'}).trim();
 assert.equal(out,'2\t'+secondId);
 console.log('DOM click and middle-click sent ordered reports to disposable HTTP endpoint');
 w.close();
}
run().catch(e=>{console.error(e);w.close();process.exitCode=1;});
