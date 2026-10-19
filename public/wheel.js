(() => {
 'use strict';
 const colors=['#f4c96e','#82d3c7','#efa09b','#9cb8ed','#e2a8df','#a7da8a','#8dbbdc','#f4a976','#b3a0e0'];
 const rad=Math.PI/180;
 class LuckyWheel {
  constructor(canvas){this.canvas=canvas;this.ctx=canvas.getContext('2d');this.current=[];this.rotation=0;this.busy=false;}
  setSlices(slices){this.current=Array.isArray(slices)?slices:[];this.draw();}
  draw(){const c=this.canvas,ctx=this.ctx,w=c.width,r=w/2-15,x=w/2,n=this.current.length;
   ctx.clearRect(0,0,w,w);if(!n){ctx.fillStyle='#263b57';ctx.beginPath();ctx.arc(x,x,r,0,Math.PI*2);ctx.fill();ctx.fillStyle='#fff';ctx.textAlign='center';ctx.font='bold 40px system-ui';ctx.fillText('START GAME',x,x);return;}
   const arc=Math.PI*2/n;
   this.current.forEach((p,i)=>{const a=-Math.PI/2+i*arc,b=a+arc,mid=(a+b)/2;
    ctx.fillStyle=p.kind==='trigger'?'#aa64ca':p.kind==='empty'?'#384659':p.tier==='bonus'?'#c18de8':p.tier==='tier2'?'#78cdb9':colors[i%colors.length];
    ctx.beginPath();ctx.moveTo(x,x);ctx.arc(x,x,r,a,b);ctx.closePath();ctx.fill();ctx.strokeStyle='#142136';ctx.lineWidth=5;ctx.stroke();
    ctx.save();const d=r*(n>14?.69:.65);ctx.translate(x+Math.cos(mid)*d,x+Math.sin(mid)*d);let t=mid+Math.PI/2;if(Math.cos(t)<0)t+=Math.PI;ctx.rotate(t);ctx.textAlign='center';ctx.textBaseline='middle';
    ctx.fillStyle=p.kind==='empty'?'#cfd4de':'#17283f';if(p.kind==='trigger')ctx.fillStyle='#fff';
    const size=n>16?18:n>10?23:30,width=n>16?126:194;
    ctx.font=`800 ${size}px system-ui`;const words=String(p.name??'').split(' '),lines=[];let line='';
    for(const word of words){const next=line?line+' '+word:word;if(line&&ctx.measureText(next).width>width){lines.push(line);line=word;}else line=next;}
    if(line)lines.push(line);for(const [j,text] of lines.slice(0,2).entries())ctx.fillText(text,0,j*size-23,width);
    ctx.font=`800 ${Math.max(16,size-3)}px system-ui`;
    ctx.fillText(p.kind==='trigger'?'✧ EXTRA':p.kind==='empty'?'—':('$'+(Number(p.cents||p.value||0)/100).toFixed(2)),0,30,width);
    if(p.tier==='tier2'||p.tier==='bonus'){ctx.font='bold 16px system-ui';ctx.fillText(p.tier==='tier2'?'TIER 2':'BONUS',0,61,width);}ctx.restore();
   });
  }
  spinTo(index,slices,duration=3500){
    if(this.busy)return Promise.reject(Error('A spin is already running.'));
    this.busy=true;this.setSlices(slices);const N=Math.max(1,slices.length);const start=this.rotation;
    const to=(360-((index+.5)*360/N)%360)%360;const current=((start%360)+360)%360;
    const end=start+360*5+((to-current+360)%360);const beginning=performance.now();
    return new Promise(resolve=>{const step=t=>{const f=Math.min(1,(t-beginning)/duration);const ease=1-Math.pow(1-f,4);
      this.canvas.style.transform=`rotate(${start+(end-start)*ease}deg)`;
      if(f<1)requestAnimationFrame(step);else{this.rotation=end;this.busy=false;resolve();}};requestAnimationFrame(step);});
  }
 }
 window.LuckyWheel=LuckyWheel;
})();
