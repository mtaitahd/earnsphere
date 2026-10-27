const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
function guide(open){const frame={dataset:{welcomeSrc:'https://www.youtube.com/embed/dQw4w9WgXcQ'},getAttribute(){return this.src||null;},removeAttribute(){delete this.src;}};const media={paused:0,listeners:{},pause(){this.paused++;},addEventListener(n,fn){this.listeners[n]=fn;}};return {open,frame,media,listeners:{},querySelectorAll(s){return s==='iframe'?[frame]:[media];},addEventListener(n,fn){this.listeners[n]=fn;}};}
const first=guide(true),second=guide(false),button={},close={},dialog={open:false,listeners:{},querySelectorAll(){return [first,second];},querySelector(){return close;},showModal(){this.open=true;},close(){this.open=false;this.listeners.close();},addEventListener(n,fn){this.listeners[n]=fn;}};
button.addEventListener=(_,fn)=>button.click=fn;close.addEventListener=(_,fn)=>close.click=fn;
vm.runInNewContext(fs.readFileSync('assets/js/welcome-media.js','utf8'),{document:{getElementById:()=>dialog,querySelectorAll:()=>[button]}});
assert(!first.frame.src,'Loaded YouTube before user opened instructions');button.click();assert(dialog.open);assert(first.frame.src);assert(!second.frame.src);
second.open=true;second.listeners.toggle();assert(!first.open);assert(!first.frame.src);assert(second.frame.src);assert(first.media.paused>0);
close.click();assert(!dialog.open);assert(!second.frame.src);assert(second.media.paused>0);
button.click();assert(second.frame.src,'Selected video did not reload after reopening');
console.log('Guide UI: lazy loading, switching videos, stopping inactive players and closing/reopening passed.');
