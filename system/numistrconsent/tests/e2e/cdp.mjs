// Başsız Chrome + DevTools protokolü ile çerez onayı uçtan uca denemesi (yerel kopya, 127.0.0.1:8765).
// GA4/Ads ölçüm adresleri engellenir (canlı mülke deneme isabeti düşmesin); istekler yine kaydedilir.
import fs from 'node:fs';

const PORT = Number(process.env.NCZ_CDP || 19333), SITE = process.env.NCZ_SITE || 'http://127.0.0.1:18791';
const OUT = (process.env.NCZ_DIR || '/tmp/ncz_e2e') + '/cikti/';
fs.mkdirSync(OUT, { recursive: true });

const ENGEL = ['*google-analytics.com/*collect*', '*analytics.google.com/*collect*', '*doubleclick.net*', '*google.com/measurement*',
  '*google.com/ads/*', '*google.com.tr/ads/*', '*google.com/pagead*', '*googleadservices.com*', '*apps.apple.com*', '*play.google.com/store*',
  '*cloudflareinsights.com*', '*google.com/ccm/*'];
// Y4 (NT-261118-110022oc): GA4 toplama istekleri bazı senaryolarda engellenmez, yerelde 204 ile yanıtlanır (Fetch) —
// böylece event_callback'in gerçek süresi ölçülür ve canlı mülke yine hiçbir şey gitmez.
const TOPLAMA = ['*google-analytics.com/*collect*', '*analytics.google.com/*collect*'];
let sahte204 = false;
const GOOGLE = /googletagmanager\.com|google-analytics\.com|analytics\.google\.com|doubleclick\.net|google\.com(\.tr)?\/(ads|measurement|pagead)/;

const METIN = JSON.parse(fs.readFileSync(new URL('../../media/metinler.json', import.meta.url), 'utf8'));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let ws, seq = 0;
const bekleyen = new Map();
const istekler = [];
const gonderimler = []; // sayfa içi sendBeacon/fetch gövdeleri (Y4 ölçümü)
const sonuc = [];

function cdp(method, params = {}) {
  const id = ++seq;
  ws.send(JSON.stringify({ id, method, params }));
  return new Promise((res, rej) => bekleyen.set(id, { res, rej, method }));
}

async function baglan() {
  const r = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' });
  const sekme = await r.json();
  ws = new WebSocket(sekme.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener('open', r, { once: true }));
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && bekleyen.has(m.id)) {
      const b = bekleyen.get(m.id); bekleyen.delete(m.id);
      m.error ? b.rej(new Error(b.method + ': ' + m.error.message)) : b.res(m.result);
    } else if (m.method === 'Network.requestWillBeSent') {
      const rq = m.params.request;
      const govde = rq.postData || (rq.postDataEntries || []).map((e) => Buffer.from(e.bytes || '', 'base64').toString('utf8')).join('');
      istekler.push({ url: rq.url, t: m.params.timestamp, tn: Date.now(), tur: m.params.type, govde, id: m.params.requestId, govdeVar: !!rq.hasPostData });
    } else if (m.method === 'Runtime.consoleAPICalled') {
      const metin = (m.params.args || []).map((a) => a.value).join(' ');
      if (/^NCZ(BEACON|FETCH) /.test(metin)) gonderimler.push({ tn: Date.now(), metin });
    } else if (m.method === 'Fetch.requestPaused') {
      const fr = m.params.request;
      const fg = fr.postData || (fr.postDataEntries || []).map((e) => Buffer.from(e.bytes || '', 'base64').toString('utf8')).join('');
      const es = istekler.find((i) => i.id === m.params.networkId) || istekler.slice().reverse().find((i) => i.url === fr.url);
      if (es && fg) es.govde = fg;
      cdp('Fetch.fulfillRequest', { requestId: m.params.requestId, responseCode: 204, responseHeaders: [{ name: 'Access-Control-Allow-Origin', value: '*' }], body: '' }).catch(() => null);
    }
  });
  await cdp('Network.enable');
  await cdp('Page.enable');
  await cdp('Runtime.enable');
  await cdp('Network.setBlockedURLs', { urls: ENGEL });
  await cdp('Network.setCacheDisabled', { cacheDisabled: true });
  // Y4: GA4 olayları çoğunlukla sendBeacon gövdesinde gider; DevTools bu gövdeyi vermediği için sayfa içinden izlenir.
  await cdp('Page.addScriptToEvaluateOnNewDocument', { source: `(function(){try{var sb=navigator.sendBeacon&&navigator.sendBeacon.bind(navigator);
if(sb){navigator.sendBeacon=function(u,b){try{if(/collect/.test(String(u)))console.log('NCZBEACON '+u+' || '+(typeof b==='string'?b:''));}catch(e){}return sb(u,b);};}
var f=window.fetch;if(f){window.fetch=function(u,o){try{if(/collect/.test(String(u&&u.url||u)))console.log('NCZFETCH '+(u&&u.url||u)+' || '+(o&&typeof o.body==='string'?o.body:''));}catch(e){}return f.apply(this,arguments);};}}catch(e){}})();` });
}

async function git(yol, bekle = 4000) {
  istekler.length = 0; gonderimler.length = 0;
  await cdp('Page.navigate', { url: SITE + yol });
  await sleep(bekle);
}

async function js(ifade) {
  const r = await cdp('Runtime.evaluate', { expression: ifade, returnByValue: true, awaitPromise: true });
  if (r.exceptionDetails) throw new Error('JS: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
  return r.result.value;
}

async function cerezler() {
  const r = await cdp('Network.getAllCookies');
  return r.cookies.map((c) => c.name);
}

async function cerezTemizle() {
  await cdp('Network.clearBrowserCookies');
}

async function ekran(ad, gen = 1280, yuk = 800, mobil = false) {
  await cdp('Emulation.setDeviceMetricsOverride', { width: gen, height: yuk, deviceScaleFactor: 1, mobile: mobil });
  await sleep(400);
  const r = await cdp('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(OUT + ad + '.png', Buffer.from(r.data, 'base64'));
}

function googleIstekleri() {
  return istekler.filter((i) => GOOGLE.test(i.url));
}

function gcs() {
  return [...new Set(googleIstekleri().map((i) => (i.url.match(/[?&]gcs=([^&]+)/) || [])[1]).filter(Boolean))];
}

function denetle(ad, kosul, ayrinti = '') {
  sonuc.push({ ad, kosul: !!kosul, ayrinti });
  console.log((kosul ? '  ok   ' : '  FAIL ') + ad + (ayrinti ? '  — ' + ayrinti : ''));
}

const gorunur = `(()=>{const k=document.getElementById('ncz');if(!k||k.hidden)return 'yok';return k.querySelector('.ncz-k2').hidden?'k1':'k2';})()`;

await baglan();
await cdp('Emulation.setDeviceMetricsOverride', { width: 1280, height: 800, deviceScaleFactor: 1, mobile: false });

console.log('A · onaysız ilk açılış (TR)');
await cerezTemizle();
await git('/tr/');
denetle('Google adreslerine hiç istek yok', googleIstekleri().length === 0, googleIstekleri().map((i) => i.url.slice(0, 60)).join(' | '));
denetle('_ga* / _gcl_* çerezi yok', !(await cerezler()).some((c) => /^_ga|^_gcl/.test(c)), (await cerezler()).join(','));
denetle('pencere 1. katmanda görünüyor', (await js(gorunur)) === 'k1');
denetle('üç düğme (kabul / ret / ayarlar) aynı sınıf', await js(`[...document.querySelectorAll('.ncz-k1 .ncz-dugme')].map(b=>b.className+'|'+b.dataset.ncz).join(',')==='ncz-dugme|kabul,ncz-dugme|ret,ncz-dugme|ayarlar'`));
denetle('odak sayfada kaldı (pencere odağı çalmadı)', await js(`!document.activeElement||!document.activeElement.closest('#ncz')`));
denetle('consent default dataLayer\'da, update yok', await js(`(()=>{const d=dataLayer.map(a=>Array.from(a));return d.some(a=>a[0]==='consent'&&a[1]==='default'&&a[2].analytics_storage==='denied')&&!d.some(a=>a[0]==='consent'&&a[1]==='update')})()`));
denetle('alt bilgide "Çerez ayarları" bağlantısı', await js(`[...document.querySelectorAll('footer a[href="#cerez-ayarlari"]')].map(a=>a.textContent).join()===${JSON.stringify(METIN.tr.altbilgi_baglanti)}`));
denetle('TR metin metinler.json\'dan', await js(`document.querySelector('#ncz-b1').textContent===${JSON.stringify(METIN.tr.banner_baslik)}&&document.querySelector('.ncz-k1 [data-ncz=ret]').textContent===${JSON.stringify(METIN.tr.btn_ret)}`));
denetle('düğmelerde büyük harf dönüşümü yok', await js(`getComputedStyle(document.querySelector('.ncz-dugme')).textTransform==='none'`));
await ekran('A_tr_masaustu_pencere');
await ekran('A_tr_mobil_pencere', 390, 844, true);
await cdp('Emulation.setDeviceMetricsOverride', { width: 1280, height: 800, deviceScaleFactor: 1, mobile: false });

console.log('B · "Tümünü kabul et"');
istekler.length = 0;
await js(`document.querySelector('.ncz-k1 [data-ncz="kabul"]').click()`);
await sleep(6000);
denetle('pencere kapandı', (await js(gorunur)) === 'yok');
denetle('gtm.js yüklendi (sayfa yenilenmeden)', istekler.some((i) => /googletagmanager\.com\/gtm\.js\?id=GTM-TRH8ZR6M/.test(i.url)));
denetle('GA4 isteklerinde gcs=G111', gcs().includes('G111') && !gcs().includes('G100'), 'gcs: ' + gcs().join(','));
denetle('_ga ve tercih çerezi yazıldı', (await cerezler()).includes('_ga') && (await cerezler()).includes('numistr_cerez_tercihi'), (await cerezler()).join(','));
const tercih = await js(`JSON.parse(decodeURIComponent(document.cookie.match(/numistr_cerez_tercihi=([^;]+)/)[1]))`);
denetle('tercih çerezi {v,t,a:1,p:1}', tercih.a === 1 && tercih.p === 1 && tercih.v && tercih.t > 0, JSON.stringify(tercih));
const omur = (await cdp('Network.getAllCookies')).cookies.find((c) => c.name === 'numistr_cerez_tercihi');
denetle('tercih çerezi ömrü ~6 ay', Math.abs((omur.expires - Date.now() / 1000) - 182 * 86400) < 120, Math.round((omur.expires - Date.now() / 1000) / 86400) + ' gün');

console.log('C · yeniden açılış (onay kayıtlı)');
await git('/tr/', 9000);
denetle('pencere çıkmadı', (await js(gorunur)) === 'yok');
denetle('consent update, gtm.js\'den önce dataLayer\'da', await js(`(()=>{const d=dataLayer.map(a=>a&&a.length!==undefined&&typeof a!=='string'?Array.from(a):a);const u=d.findIndex(a=>Array.isArray(a)&&a[0]==='consent'&&a[1]==='update');const g=d.findIndex(a=>a&&a.event==='gtm.js');return u>-1&&g>u;})()`));
const ics = await js(`(()=>{try{const e=google_tag_data.ics.entries;return Object.keys(e).sort().map(k=>k+'='+(e[k].update===true?'granted':e[k].update===false?'denied':'?')).join(',')}catch(x){return 'yok'}})()`);
denetle('Google onay durumu: analitik + reklam granted, kişiselleştirme denied', /ad_personalization=denied/.test(ics) && /ad_storage=granted/.test(ics) && /analytics_storage=granted/.test(ics) && /ad_user_data=granted/.test(ics), ics);
denetle('gcs=G111 (ölçüm isteği)', gcs().includes('G111'), 'gcs: ' + gcs().join(',') + ' · google: ' + googleIstekleri().map((i) => i.url.replace(/\?.*/, '')).join(' | '));

console.log('D · alt bilgiden ayarlar → onayı geri çek');
await js(`document.querySelector('footer a[href="#cerez-ayarlari"]').click()`);
await sleep(500);
denetle('tercih merkezi açıldı (2. katman)', (await js(gorunur)) === 'k2');
denetle('kutucuklar kayıtlı tercihi gösteriyor', await js(`document.querySelector('[data-ncz-kat=analitik]').checked&&document.querySelector('[data-ncz-kat=pazarlama]').checked`));
denetle('zorunlu kutucuk kilitli', await js(`(()=>{const z=document.querySelector('[data-ncz-kat=zorunlu]');return z.checked&&z.disabled})()`));
denetle('çerez listesi tablosu var', await js(`document.querySelectorAll('.ncz-liste table').length===3`));
denetle('odak tercih merkezi başlığında', await js(`document.activeElement&&document.activeElement.id==='ncz-b2'`));
await ekran('D_tr_tercih_merkezi');
await js(`document.querySelector('[data-ncz-kat=analitik]').checked=false;document.querySelector('[data-ncz-kat=pazarlama]').checked=false;`);
istekler.length = 0;
await js(`document.querySelector('.ncz-k2 [data-ncz="kaydet"]').click()`);
await sleep(5000);
denetle('sayfa yenilendi (belge isteği)', istekler.some((i) => i.tur === 'Document' && i.url.startsWith(SITE + '/tr/')));
denetle('yenilemeden sonra gtm.js yok', !istekler.some((i) => /gtm\.js/.test(i.url)));
denetle('_ga* / _gcl_* silindi', !(await cerezler()).some((c) => /^_ga|^_gcl/.test(c)), (await cerezler()).join(','));
denetle('localStorage _gcl* yok', await js(`!Object.keys(localStorage).some(k=>/^_gcl/.test(k))`));
const t2 = await js(`JSON.parse(decodeURIComponent(document.cookie.match(/numistr_cerez_tercihi=([^;]+)/)[1]))`);
denetle('tercih a:0 p:0 ve korundu', t2.a === 0 && t2.p === 0, JSON.stringify(t2));
denetle('pencere çıkmadı (ret de hatırlanır)', (await js(gorunur)) === 'yok');

console.log('E · "Tümünü reddet" (temiz profil)');
await cerezTemizle();
await git('/tr/');
istekler.length = 0;
await js(`document.querySelector('.ncz-k1 [data-ncz="ret"]').click()`);
await sleep(3000);
denetle('Google adreslerine istek yok', googleIstekleri().length === 0);
denetle('pencere kapandı', (await js(gorunur)) === 'yok');
await git('/tr/');
denetle('yeniden açılışta pencere yok, Google isteği yok', (await js(gorunur)) === 'yok' && googleIstekleri().length === 0);

console.log('F · yalnız analitik');
await cerezTemizle();
await git('/tr/');
await js(`document.querySelector('.ncz-k1 [data-ncz="ayarlar"]').click()`);
await sleep(300);
denetle('Ayarlar düğmesi 2. katmanı açar, kutucuklar boş', (await js(gorunur)) === 'k2' && (await js(`!document.querySelector('[data-ncz-kat=analitik]').checked&&!document.querySelector('[data-ncz-kat=pazarlama]').checked`)));
istekler.length = 0;
await js(`document.querySelector('[data-ncz-kat=analitik]').checked=true;document.querySelector('.ncz-k2 [data-ncz="kaydet"]').click()`);
await sleep(6000);
denetle('gcs=G101 (yalnız analitik)', gcs().includes('G101') && !gcs().includes('G111'), 'gcs: ' + gcs().join(','));
denetle('_ga var, _gcl_* yok', (await cerezler()).includes('_ga') && !(await cerezler()).some((c) => /^_gcl/.test(c)), (await cerezler()).join(','));

console.log('G · metin sürümü değişince yeniden sorulur + eski izler temizlenir');
await js(`document.cookie='numistr_cerez_tercihi='+encodeURIComponent(JSON.stringify({v:'eski',t:1,a:1,p:1}))+'; Path=/';document.cookie='_gid=x; Path=/';document.cookie='_gat_UA1=1; Path=/';document.cookie='_gcl_aw=x; Path=/';localStorage.setItem('_gcl_ls','x');sessionStorage.setItem('_gcl_gs','x')`);
await git('/tr/');
denetle('pencere yeniden çıktı', (await js(gorunur)) === 'k1');
denetle('eski onayla GTM yüklenmedi', !istekler.some((i) => /gtm\.js/.test(i.url)));
denetle('onaysız durumda kalan _ga*, _gid, _gat*, _gcl_* temizlendi', !(await cerezler()).some((c) => /^_ga|^_gid|^_gat|^_gcl/.test(c)), (await cerezler()).join(','));
denetle('localStorage + sessionStorage _gcl* temizlendi', await js(`!Object.keys(localStorage).some(k=>/^_gcl/.test(k))&&!Object.keys(sessionStorage).some(k=>/^_gcl/.test(k))`));

console.log('H · EN sayfa');
await cerezTemizle();
await git('/en/');
denetle('EN metin', await js(`document.querySelector('#ncz-b1').textContent===${JSON.stringify(METIN.en.banner_baslik)}&&document.querySelector('.ncz-k1 [data-ncz=kabul]').textContent===${JSON.stringify(METIN.en.btn_kabul)}`));
denetle('EN alt bilgi bağlantısı', await js(`[...document.querySelectorAll('footer a[href="#cerez-ayarlari"]')].map(a=>a.textContent).join()===${JSON.stringify(METIN.en.altbilgi_baglanti)}`));
denetle('EN: Google isteği yok', googleIstekleri().length === 0);
await ekran('H_en_masaustu_pencere');

console.log('I · klavye');
await js(`document.querySelector('.ncz-k1 [data-ncz="ayarlar"]').focus()`);
await cdp('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r' });
await cdp('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
await sleep(300);
denetle('Enter ile Ayarlar açılır', (await js(gorunur)) === 'k2');
await cdp('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
await sleep(300);
denetle('Esc tercih yokken 1. katmana döner', (await js(gorunur)) === 'k1');

console.log('J · /tr/app (iPhone)');
await cdp('Network.setUserAgentOverride', { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' });
await cerezTemizle();
await git('/tr/app/', 2500);
const doc = istekler.find((i) => i.url.startsWith(SITE + '/tr/app'));
const apple = istekler.find((i) => /apps\.apple\.com/.test(i.url));
denetle('onaysız: GTM yok', !istekler.some((i) => /googletagmanager/.test(i.url)));
denetle('onaysız: App Store\'a beklemeden gidiş (<300 ms)', apple && doc && (apple.t - doc.t) < 0.3, apple && doc ? Math.round((apple.t - doc.t) * 1000) + ' ms' : 'yönlendirme yok');
console.log('K · /tr/app Y4 — gtag olayı + event_callback (NT-261118-110022oc)');
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36';
const olayMi = (i) => /collect/.test(i.url) && /en=app_store_redirect/.test(i.url + '\n' + i.govde);
const olayGonderimi = () => gonderimler.find((g) => /en=app_store_redirect/.test(g.metin));
const ms = (a, b) => (a && b ? (a.t != null && b.t != null ? Math.round((a.t - b.t) * 1000) : a.tn - b.tn) : null);
async function appDene(ua, cerez, { gaEngel = false, sure = 3500 } = {}) {
  await cdp('Network.setUserAgentOverride', { userAgent: ua });
  await cerezTemizle();
  if (cerez) await cdp('Network.setCookie', { name: 'numistr_cerez_tercihi', value: encodeURIComponent(JSON.stringify({ v: tercih.v, t: Date.now(), ...cerez })), url: SITE + '/' });
  const engel = ENGEL.filter((e) => gaEngel || !TOPLAMA.includes(e)).concat(gaEngel ? ['*google-analytics.com*', '*analytics.google.com*'] : []);
  await cdp('Network.setBlockedURLs', { urls: engel });
  if (!gaEngel) await cdp('Fetch.enable', { patterns: TOPLAMA.map((u) => ({ urlPattern: u })) });
  await git('/tr/app/', sure);
  if (!gaEngel) await cdp('Fetch.disable');
  await cdp('Network.setBlockedURLs', { urls: ENGEL });
  const doc = istekler.find((i) => i.url.startsWith(SITE + '/tr/app'));
  const magaza = istekler.find((i) => /apps\.apple\.com|play\.google\.com\/store/.test(i.url));
  const olay = istekler.find(olayMi) || (olayGonderimi() && { url: olayGonderimi().metin, govde: '', t: null, tn: olayGonderimi().tn });
  return { doc, magaza, olay, google: googleIstekleri(), gtm: istekler.some((i) => /googletagmanager\.com\/gtm\.js/.test(i.url)) };
}
for (const [ad, ua, magazaAd] of [['iPhone', IPHONE, 'apple'], ['Android', ANDROID, 'google']]) {
  const a = await appDene(ua, { a: 1, p: 0 });
  const olayUrl = a.olay ? a.olay.url + ' ' + a.olay.govde : '';
  fs.writeFileSync(OUT + `y4_a_${ad}_gonderim.json`, JSON.stringify(gonderimler, null, 1));
  fs.writeFileSync(OUT + `y4_a_${ad}.json`, JSON.stringify(istekler.filter((i) => /collect|gtag|gtm\.js/.test(i.url)).map((i) => ({ ms: ms(i, a.doc), url: i.url, govde: i.govde.slice(0, 2000), govdeVar: i.govdeVar })), null, 1));
  // Y4 asıl şikâyeti: yönlendirilen mobil ziyaret GA4'te hiç görünmüyordu (page_view gitmiyordu).
  const pv = gonderimler.find((g) => /tid=G-4H308X9TS1/.test(g.metin) && /[?&]en=page_view/.test(g.metin));
  denetle(`(a) ${ad} a:1 — bizim GA4 page_view'u yönlendirmeden ÖNCE gidiyor`, pv && a.magaza && pv.tn <= a.magaza.tn,
    pv ? `page_view ${pv.tn - a.doc.tn} ms · yönlendirme ${ms(a.magaza, a.doc)} ms` : 'page_view gitmedi');
  denetle(`(a) ${ad} a:1 — yönlendirme 2,1 sn yedeğinden önce (event_callback)`, a.magaza && a.doc && (a.magaza.t - a.doc.t) < 2.0, ms(a.magaza, a.doc) + ' ms');
  // BULGU (KARAR GEREKİYOR): sayfa kodundaki gtag('event') bu kapsayıcıda GA4'e yönlenmiyor — GTM onu yalnız tetikleyici
  // olayı sayıyor, bağlı GA4 etiketi yok; event_callback'i teslim etmeden çağırıyor. Sayaçta değil, gözlem olarak yazılır.
  console.log(`  BULGU (a) ${ad}: app_store_redirect GA4'e ${a.olay ? 'GİTTİ ' + (olayUrl.match(/ep\.(store|lang)=[^&\s]+/g) || []).join(',') : 'GİTMEDİ'} · GA4 olayları: ${[...new Set(gonderimler.map((g) => (g.metin.match(/[?&]en=([^&\s]+)/) || [])[1]).filter(Boolean))].join(',')}`);
}
const b = await appDene(IPHONE, { a: 1, p: 0 }, { gaEngel: true });
denetle('(b) a:1, GA alan adları engelli — yönlendirme ≤ 2,1 sn', b.magaza && b.doc && (b.magaza.t - b.doc.t) <= 2.1, b.magaza ? ms(b.magaza, b.doc) + ' ms' : 'yönlendirme yok');
for (const [ad, cerez] of [['çerez yok', null], ['a:0,p:0', { a: 0, p: 0 }]]) {
  const c = await appDene(IPHONE, cerez, { sure: 2500 });
  denetle(`(c) ${ad} — Google adreslerine istek yok`, c.google.length === 0, c.google.map((i) => i.url.slice(0, 60)).join(' | '));
  denetle(`(c) ${ad} — yönlendirme hemen (<300 ms)`, c.magaza && c.doc && (c.magaza.t - c.doc.t) < 0.3, c.magaza ? ms(c.magaza, c.doc) + ' ms' : 'yönlendirme yok');
}
const d = await appDene(ANDROID, { a: 0, p: 1 }, { sure: 3500 });
const dGcs = [...new Set(d.google.map((i) => (i.url.match(/[?&]gcs=([^&]+)/) || [])[1]).filter(Boolean))];
const dToplama = d.google.filter((i) => /collect/.test(i.url));
denetle('(d) a:0,p:1 — app_store_redirect olayı yok', !d.olay);
denetle('(d) a:0,p:1 — yönlendirme hemen (<300 ms)', d.magaza && d.doc && (d.magaza.t - d.doc.t) < 0.3, d.magaza ? ms(d.magaza, d.doc) + ' ms' : 'yönlendirme yok');
console.log(`  GÖZLEM (d): GTM yüklendi=${d.gtm} · GA4 toplama isteği=${dToplama.length} · gcs=${dGcs.join(',') || '—'} · olaylar=${[...new Set(dToplama.map((i) => (i.url.match(/[?&]en=([^&]+)/) || [])[1]).filter(Boolean))].join(',') || '—'}`);
fs.writeFileSync(OUT + 'y4_gozlem_d.json', JSON.stringify({ gtm: d.gtm, toplama: dToplama.map((i) => i.url), gcs: dGcs }, null, 1));
await cdp('Network.setUserAgentOverride', { userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36' });
await cerezTemizle();
await git('/tr/app/', 2500);
const doc3 = istekler.find((i) => i.url.startsWith(SITE + '/tr/app'));
const play = istekler.find((i) => /play\.google\.com\/store/.test(i.url));
denetle('Android onaysız: Play\'e beklemeden, bizim GTM yok', play && doc3 && (play.t - doc3.t) < 0.3 && !istekler.some((i) => /GTM-TRH8ZR6M/.test(i.url)), (play && doc3 ? Math.round((play.t - doc3.t) * 1000) + ' ms' : 'yönlendirme yok') + ' · gtm: ' + istekler.filter((i) => /googletagmanager/.test(i.url)).map((i) => Math.round((i.t - doc3.t) * 1000) + 'ms ' + i.url.slice(0, 70)).join(' | '));

const kaldi = sonuc.filter((s) => !s.kosul).length;
console.log(`\n${sonuc.length - kaldi} geçti, ${kaldi} kaldı`);
fs.writeFileSync(OUT + 'sonuc.json', JSON.stringify(sonuc, null, 1));
ws.close();
process.exit(kaldi ? 1 : 0);
