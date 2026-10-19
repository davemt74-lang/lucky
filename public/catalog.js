(() => {
'use strict';
const $=id=>document.getElementById(id), admin=Boolean($('catalog-form'));
const api=window.Lucky?.api; if(!api)return;
let products=[];
const money=c=>'$'+(Number(c)/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
function node(tag, cls='', str=''){const n=document.createElement(tag);if(cls)n.className=cls;if(str)n.textContent=str;return n;}
function status(message,error=false){const e=$(admin?'catalog-admin-status':'catalog-status');e.textContent=message;e.classList.toggle('platform-error',error);}
function allowedUrl(str){try{const u=new URL(str);return u.protocol==='https:'&&!!u.hostname&&!u.username&&!u.password;}catch{return false;}}
function makeTile(p){
 const box=node('article','catalog-tile');
 if(p.imageUrl&&allowedUrl(p.imageUrl)){const im=node('img','catalog-cover');im.src=p.imageUrl;im.alt='';im.loading='lazy';im.referrerPolicy='no-referrer';box.append(im);}
 else box.append(node('div','catalog-cover catalog-placeholder',p.category==='hardware'?'🎮':p.category==='game'?'✦':'◈'));
 const body=node('div','catalog-tile-body');body.append(node('span','mini-label',p.category.toUpperCase()),node('h3','',p.title),node('p','catalog-summary',p.summary),node('strong','catalog-price',money(p.priceCents)));
 if(p.status==='available'&&allowedUrl(p.purchaseUrl)){const link=node('a','btn btn-primary','View product ↗');link.href=p.purchaseUrl;link.target='_blank';link.rel='noopener noreferrer';body.append(link);}
 else body.append(node('span','btn btn-outline disabled-action',p.status==='coming_soon'?'Coming soon':'Not for sale'));
 box.append(body);return box;
}
function renderShop(){const root=$('catalog-products');if(!root)return;root.replaceChildren();const type=$('catalog-filter').value;const list=products.filter(p=>type==='all'||type===p.category);list.forEach(p=>root.append(makeTile(p)));status(list.length?`${list.length} product${list.length===1?'':'s'} in this view.`:'No products in this category yet.');}
function formData(){const f=$('catalog-form').elements,price=Number(f.namedItem('price').value);if(!Number.isFinite(price)||price<0||price>9999999.99)throw Error('Invalid product price.');const cents=Math.round(price*100);return {id:f.namedItem('id').value,title:f.namedItem('title').value.trim(),summary:f.namedItem('summary').value.trim(),category:f.namedItem('category').value,status:f.namedItem('status').value,priceCents:cents,imageUrl:f.namedItem('imageUrl').value.trim(),purchaseUrl:f.namedItem('purchaseUrl').value.trim(),sortOrder:Number(f.namedItem('sortOrder').value)};}
function clearForm(){const f=$('catalog-form');f.reset();f.elements.namedItem('id').value='';$('catalog-form-title').textContent='Add a product';}
function edit(p){const f=$('catalog-form').elements;for(const field of ['id','title','summary','category','status','imageUrl','purchaseUrl','sortOrder'])f.namedItem(field).value=p[field]??'';f.namedItem('price').value=(p.priceCents/100).toFixed(2);$('catalog-form-title').textContent='Edit product';window.scrollTo({top:0,behavior:'smooth'});}
function renderAdmin(){const root=$('catalog-manage-list');if(!root)return;root.replaceChildren();for(const p of products){const item=node('div','catalog-manage-item');const info=node('div');info.append(node('strong','',p.title),node('p','console-footnote',`${p.category} · ${p.status} · ${money(p.priceCents)}`));const controls=node('div','catalog-actions');const e=node('button','btn btn-outline','Edit');e.type='button';e.onclick=()=>edit(p);const d=node('button','btn btn-outline','Delete');d.type='button';d.onclick=async()=>{if(!confirm(`Remove ${p.title} from the catalog?`))return;try{await api('catalog_delete',{body:{id:p.id}});await reload();status('Product removed.');}catch(ex){status(ex.message,true);}};controls.append(e,d);item.append(info,controls);root.append(item);}if(!products.length)root.append(node('p','console-footnote','No products yet. Add your first game or hardware listing.'));}
async function reload(){const res=await api(admin?'catalog_admin':'catalog');products=res.products||[];if(admin)renderAdmin();else renderShop();}
if(admin){$('catalog-form').addEventListener('submit',async ev=>{ev.preventDefault();const submit=$('catalog-form').querySelector('[type=submit]');try{submit.disabled=true;const record=formData();if(record.status==='available'&&!allowedUrl(record.purchaseUrl))throw Error('Available products require a secure HTTPS purchase URL.');await api('catalog_save',{body:record});clearForm();await reload();status('Product saved.');}catch(e){status(e.message,true);}finally{submit.disabled=false;}});$('catalog-cancel').onclick=clearForm;}
else $('catalog-filter')?.addEventListener('change',renderShop);
reload().catch(e=>status(`${e.message} If upgrading from V5M, run the V5N database migration first.`,true));
})();
