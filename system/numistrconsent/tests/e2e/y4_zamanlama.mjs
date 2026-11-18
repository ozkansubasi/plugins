// Y4 zamanlama ölçümü (NT-261118-110022oc, Prime çift sayım sorusu, 18.11): sayfa kodundaki
// gtag('event', …, {send_to}) GA4 hedefi ne zaman hazır olunca gerçekten gönderiliyor?
// Masaüstü kullanıcı aracısı (yönlendirme yok), analitik onayı çerezi, canlı GTM kapsayıcısı.
// GA4 toplama istekleri yerelde 204 ile yanıtlanır (canlı mülke bir şey gitmez); sendBeacon/fetch gövdeleri sayfa içinden izlenir.
// Çalıştırma: calistir.sh ile aynı ortamda: NCZ_PROBE=y4_zamanlama.mjs bash calistir.sh
import fs from 'node:fs';

const PORT = Number(process.env.NCZ_CDP || 19333), SITE = process.env.NCZ_SITE || 'http://127.0.0.1:18791';
const OUT = (process.env.NCZ_DIR || '/tmp/ncz_e2e') + '/cikti/';
fs.mkdirSync(OUT, { recursive: true });
const METIN = JSON.parse(fs.readFileSync(new URL('../../media/metinler.json', import.meta.url), 'utf8'));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let ws, seq = 0;
const bekleyen = new Map();
const gonderim = [];

function cdp(method, params = {}) {
  const id = ++seq;
  ws.send(JSON.stringify({ id, method, params }));
  return new Promise((res, rej) => bekleyen.set(id, { res, rej, method }));
}
const r = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' });
ws = new WebSocket((await r.json()).webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r, { once: true }));
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data);
  if (m.id && bekleyen.has(m.id)) {
    const b = bekleyen.get(m.id); bekleyen.delete(m.id);
    m.error ? b.rej(new Error(b.method + ': ' + m.error.message)) : b.res(m.result);
  } else if (m.method === 'Runtime.consoleAPICalled') {
    const t = (m.params.args || []).map((a) => a.value).join(' ');
    if (/^NCZ/.test(t)) gonderim.push(t);
  } else if (m.method === 'Fetch.requestPaused') {
    cdp('Fetch.fulfillRequest', { requestId: m.params.requestId, responseCode: 204, responseHeaders: [{ name: 'Access-Control-Allow-Origin', value: '*' }], body: '' }).catch(() => null);
  }
});
for (const k of ['Network.enable', 'Page.enable', 'Runtime.enable']) await cdp(k);
await cdp('Network.setCacheDisabled', { cacheDisabled: true });
await cdp('Network.setBlockedURLs', { urls: ['*doubleclick.net*', '*google.com/ccm/*', '*googleadservices.com*', '*google.com/pagead*', '*apps.apple.com*', '*play.google.com/store*'] });
await cdp('Fetch.enable', { patterns: [{ urlPattern: '*google-analytics.com/*collect*' }, { urlPattern: '*analytics.google.com/*collect*' }] });
await cdp('Page.addScriptToEvaluateOnNewDocument', { source: `(function(){var t0=performance.now();var sb=navigator.sendBeacon.bind(navigator);
function yaz(tur,u,b){var ms=Math.round(performance.now()-t0);if(b&&typeof b!=='string'&&b.text){b.text().then(function(x){console.log('NCZ'+tur+' '+ms+' '+u+' || '+x);});}else if(b&&typeof b!=='string'&&(b instanceof ArrayBuffer||ArrayBuffer.isView(b))){console.log('NCZ'+tur+' '+ms+' '+u+' || '+new TextDecoder().decode(b));}else{console.log('NCZ'+tur+' '+ms+' '+u+' || '+(b||''));}}
navigator.sendBeacon=function(u,b){if(/collect/.test(String(u)))yaz('BEACON',u,b);return sb(u,b);};
var f=window.fetch;window.fetch=function(u,o){var s=String(u&&u.url||u);if(/collect/.test(s))yaz('FETCH',s,o&&o.body);return f.apply(this,arguments);};
var ii=Object.getOwnPropertyDescriptor(HTMLImageElement.prototype,'src');Object.defineProperty(HTMLImageElement.prototype,'src',{set:function(v){if(/collect/.test(String(v)))console.log('NCZIMG '+Math.round(performance.now()-t0)+' '+v);ii.set.call(this,v);},get:function(){return ii.get.call(this);}});})();` });
await cdp('Network.setUserAgentOverride', { userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36' });

const tercihV = METIN.surum || METIN.v || '';
const sonuc = [];
for (const gecikme of [0, 300, 1000]) {
  await cdp('Network.clearBrowserCookies');
  // metin sürümü sayfadan okunur (ön ilk açılış): çerez değeri sayfadaki A.v ile aynı olmalı
  await cdp('Page.navigate', { url: SITE + '/tr/app/' });
  await sleep(800);
  const v = await cdp('Runtime.evaluate', { expression: `(document.documentElement.innerHTML.match(/"v":"([^"]+)"/)||[])[1]||''`, returnByValue: true });
  await cdp('Network.setCookie', { name: 'numistr_cerez_tercihi', value: encodeURIComponent(JSON.stringify({ v: v.result.value || tercihV, t: Date.now(), a: 1, p: 0 })), url: SITE + '/' });
  gonderim.length = 0;
  await cdp('Page.navigate', { url: SITE + '/tr/app/' });
  // Eklentideki mantığın aynısı (masaüstünde eklenti olay göndermediği için burada çalıştırılır), gecikme eklenerek:
  const olcum = await cdp('Runtime.evaluate', { awaitPromise: true, returnByValue: true, expression: `new Promise(function(res){
    var t0=performance.now(),cb=null,hazir=null;var g=window.gtag||function(){(window.dataLayer=window.dataLayer||[]).push(arguments);};
    (function bekle(){var m=window.google_tag_manager||{};var h=Object.keys(m).filter(function(k){return k.indexOf('G-')===0;})[0];
      if(h){hazir=Math.round(performance.now()-t0);setTimeout(function(){
        g('event','app_store_redirect',{send_to:h,store:'olcum${gecikme}',lang:'tr',event_callback:function(){cb=Math.round(performance.now()-t0);},event_timeout:800});
        setTimeout(function(){res({hazir:hazir,gonderim_ms:hazir+${gecikme},callback:cb});},2500);},${gecikme});return;}
      if(performance.now()-t0<5000){setTimeout(bekle,25);}else{res({hazir:null});}})();})` });
  const olay = gonderim.filter((s) => /en=app_store_redirect/.test(s));
  const satir = { gecikme_ms: gecikme, ...olcum.result.value, olay_istegi: olay.length, olay_ornek: (olay[0] || '').slice(0, 40), ga4_olaylari: [...new Set(gonderim.flatMap((s) => [...s.matchAll(/(?:^|[?&\s])en=([^&\s]+)/g)].map((m) => m[1])))] };
  sonuc.push(satir);
  console.log(JSON.stringify(satir));
}
fs.writeFileSync(OUT + 'y4_zamanlama.json', JSON.stringify(sonuc, null, 1));
ws.close();
process.exit(0);
