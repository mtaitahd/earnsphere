const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
function guide(open){
 const g={open,listeners:{}};
 function frame(){return {dataset:{welcomeSrc:'https://www.youtube.com/embed/3a_69gfYSE0',welcomeAlternate:'https://www.youtube-nocookie.com/embed/3a_69gfYSE0'},getAttribute(){return this.src||null;},setAttribute(_,v){this.src=v;},removeAttribute(){delete this.src;},cloneNode(){return frame();},replaceWith(next){g.frame=next;}};}
 g.frame=frame();g.retry={addEventListener(_,fn){this.click=fn;}};g.status={textContent:''};g.media={paused:0,listeners:{},pause(){this.paused++;},addEventListener(n,fn){this.listeners[n]=fn;}};
 g.querySelectorAll=s=>s==='iframe'?[g.frame]:[g.media];g.querySelector=s=>s==='iframe'?g.frame:s==='[data-welcome-retry]'?g.retry:g.status;g.addEventListener=(n,fn)=>g.listeners[n]=fn;return g;
}
const first=guide(true),second=guide(false),button={},close={},dialog={open:false,listeners:{},querySelectorAll(){return [first,second];},querySelector(){return close;},showModal(){this.open=true;},close(){this.open=false;this.listeners.close();},addEventListener(n,fn){this.listeners[n]=fn;}};
button.addEventListener=(_,fn)=>button.click=fn;close.addEventListener=(_,fn)=>close.click=fn;
vm.runInNewContext(fs.readFileSync('assets/js/welcome-media.js','utf8'),{document:{getElementById:()=>dialog,querySelectorAll:()=>[button]}});
assert(!first.frame.src);button.click();assert(dialog.open);assert(first.frame.src);assert(!second.frame.src);
const original=first.frame;first.retry.click();assert.notEqual(first.frame,original);assert(first.frame.src.includes('youtube-nocookie.com'));first.retry.click();assert(first.frame.src.includes('www.youtube.com'));
second.open=true;second.listeners.toggle();assert(!first.open);assert(!first.frame.src);assert(second.frame.src);assert(first.media.paused>0);
close.click();assert(!dialog.open);assert(!second.frame.src);assert(second.media.paused>0);button.click();assert(second.frame.src);
console.log('Guide UI: inline retry replaces blocked frame, alternates approved embeds, stops inactive players and reopens safely.');
