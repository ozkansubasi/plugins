/*!
 * plg_system_numistrconsent 1.1.0 — çerez onayı arayüzü (pencere + tercih merkezi).
 * Bağımlılık yok. <head> betiği window.NumistrCerez'i kurar; bu dosya ertelenmiş yüklenir.
 * 1.1.0: her eylem sunucuya rıza kaydı olarak gönderilir (ADR-013); ulaşmazsa sonraki sayfada bir kez yeniden.
 */
(function (w, d) {
  'use strict';

  var N = w.NumistrCerez;
  var kaynak = d.getElementById('ncz-veri');

  if (!N || !kaynak) {
    return;
  }

  var A = N.ayar;
  var V = {};

  try {
    V = JSON.parse(kaynak.textContent || '{}');
  } catch (e) {
    V = {};
  }

  var M = V.m || {};
  var E = V.e || {};
  var C = V.c || [];
  var kutu = null;
  var sonOdak = null;
  var KOD = { tumunu_kabul: 'ka', tumunu_ret: 're', secim_kaydet: 'se', geri_cekme: 'gc' };
  var EYLEM = { ka: 'tumunu_kabul', re: 'tumunu_ret', se: 'secim_kaydet', gc: 'geri_cekme' };
  var KIMLIK = /^[0-9a-f]{32}$/;

  // --- çerez işleri -------------------------------------------------------

  /** Tercih çerezi: {v, t, a, p, i: onay kimliği, e: eylem kodu, k: katman, g: 1 = kayıt sunucuya henüz ulaşmadı}. */
  function tercihYaz(o) {
    var deger = encodeURIComponent(JSON.stringify(o));
    d.cookie = A.c + '=' + deger + '; Max-Age=' + A.s + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }

  function yeniKimlik() {
    var b = new Uint8Array(16);
    var s = '';
    var j;

    try {
      (w.crypto || w.msCrypto).getRandomValues(b);
    } catch (e) {
      for (j = 0; j < 16; j++) {
        b[j] = Math.floor(Math.random() * 256);
      }
    }

    for (j = 0; j < 16; j++) {
      s += (b[j] < 16 ? '0' : '') + b[j].toString(16);
    }

    return s;
  }

  function sayfaYolu() {
    return (location.pathname || '/').slice(0, 191);
  }

  /** Sunucu kaydı aldıysa (ya da yeniden denemenin anlamı yoksa) çerezdeki "gönderilmedi" işaretini kaldırır. */
  function ulasti(o) {
    var t = N.tercih;

    if (t && t.i === o.i && t.t === o.t && t.g) {
      delete t.g;
      tercihYaz(t);
    }
  }

  /** Rıza kaydını sunucuya gönderir (ADR-013). bitti(): istek sonuçlanınca ya da en geç 1 sn sonra, bir kez. */
  function gonder(o, sayfa, bitti) {
    var tamam = false;
    var son = function () {
      if (!tamam) {
        tamam = true;

        if (bitti) {
          bitti();
        }
      }
    };

    if (!V.k || !KIMLIK.test(o.i || '') || !EYLEM[o.e]) {
      son();
      return;
    }

    var govde = JSON.stringify({ i: o.i, t: o.t, v: o.v, e: EYLEM[o.e], k: o.k, a: o.a, p: o.p, d: V.dil === 'en' ? 'en' : 'tr', s: sayfa });
    w.setTimeout(son, 1000);

    try {
      if (w.fetch) {
        w.fetch(V.k, { method: 'POST', body: govde, headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, keepalive: true, credentials: 'same-origin' })
          .then(function (r) {
            // 2xx ulaştı; 4xx (429 hariç) yeniden denense de düzelmez. 5xx, 429 ve ağ hatasında işaret kalır.
            if (r.ok || (r.status >= 400 && r.status < 500 && r.status !== 429)) {
              ulasti(o);
            }
            son();
          }, son);
      } else if (navigator.sendBeacon && navigator.sendBeacon(V.k, govde)) {
        ulasti(o);
        son();
      } else {
        son();
      }
    } catch (e) {
      son();
    }
  }

  function alanlar() {
    var h = location.hostname;
    var parca = h.split('.');
    var liste = ['', h, '.' + h];

    if (parca.length > 2) {
      liste.push(parca.slice(-2).join('.'), '.' + parca.slice(-2).join('.'));
    }

    return liste;
  }

  function sil(desen) {
    d.cookie.split(';').forEach(function (c) {
      var ad = c.split('=')[0].replace(/^\s+|\s+$/g, '');

      if (ad && desen.test(ad)) {
        alanlar().forEach(function (alan) {
          d.cookie = ad + '=; Max-Age=0; Path=/' + (alan ? '; Domain=' + alan : '');
        });
      }
    });
  }

  /** İzin verilmeyen kategorinin çerezlerini siler (ilk açılışta eski çerezler dahil). */
  function temizle(a, p) {
    if (!a) {
      sil(/^(_ga($|_)|_gid$|_gat)/);
    }

    if (!p) {
      sil(/^_gcl_/);

      ['localStorage', 'sessionStorage'].forEach(function (tur) {
        try {
          var depo = w[tur];
          Object.keys(depo).forEach(function (k) {
            if (/^_gcl/.test(k)) {
              depo.removeItem(k);
            }
          });
        } catch (e) { /* depolama kapalı */ }
      });
    }
  }

  function kaydet(a, p, eylem, katman) {
    var once = N.tercih;
    var geriCekme = !!(once && ((once.a && !a) || (once.p && !p)));
    var o = {
      v: A.v,
      t: Date.now(),
      a: a ? 1 : 0,
      p: p ? 1 : 0,
      i: once && KIMLIK.test(once.i || '') ? once.i : yeniKimlik(),
      e: KOD[geriCekme ? 'geri_cekme' : eylem] || KOD.secim_kaydet,
      k: katman === 1 ? 1 : 2
    };

    if (V.k) {
      o.g = 1;
    }

    tercihYaz(o);
    N.tercih = o;
    N.guncelle(a, p);
    temizle(a, p);
    kapat();

    if (geriCekme) {
      // GTM bellekte kalmasın: çerezler silindi; kayıt gönderilince (en geç 1 sn) sayfa yeniden yüklenir.
      gonder(o, sayfaYolu(), function () {
        w.location.reload();
      });
      return;
    }

    gonder(o, sayfaYolu());
  }

  // --- arayüz -------------------------------------------------------------

  function ogeYap(etiket, nitelik, metin) {
    var o = d.createElement(etiket);

    Object.keys(nitelik || {}).forEach(function (k) {
      o.setAttribute(k, nitelik[k]);
    });

    if (metin) {
      o.textContent = metin;
    }

    return o;
  }

  function dugme(eylem, metin) {
    var b = ogeYap('button', { type: 'button', 'class': 'ncz-dugme', 'data-ncz': eylem }, metin);
    return b;
  }

  function cerezTablosu(kategori) {
    var liste = C.filter(function (c) { return c.kategori === kategori; });

    if (!liste.length) {
      return null;
    }

    var ac = ogeYap('details', { 'class': 'ncz-liste' });
    ac.appendChild(ogeYap('summary', {}, (E.cerezler || 'Cookies') + ' (' + liste.length + ')'));

    var tablo = ogeYap('table');
    var bas = ogeYap('tr');
    ['ad', 'saglayici', 'amac', 'sure'].forEach(function (k) {
      bas.appendChild(ogeYap('th', { scope: 'col' }, E[k] || k));
    });
    var thead = ogeYap('thead');
    thead.appendChild(bas);
    tablo.appendChild(thead);

    var govde = ogeYap('tbody');
    liste.forEach(function (c) {
      var satir = ogeYap('tr');
      [c.ad, c.saglayici, c.amac, c.sure].forEach(function (x, i) {
        satir.appendChild(ogeYap(i === 0 ? 'th' : 'td', i === 0 ? { scope: 'row' } : {}, x || '–'));
      });
      govde.appendChild(satir);
    });
    tablo.appendChild(govde);
    ac.appendChild(tablo);

    return ac;
  }

  function kategori(anahtar, ad, aciklama, kilitli) {
    var kap = ogeYap('div', { 'class': 'ncz-kat' });
    var etiket = ogeYap('label', { 'class': 'ncz-anahtar' });
    var kutucuk = ogeYap('input', { type: 'checkbox', 'data-ncz-kat': anahtar });

    if (kilitli) {
      kutucuk.checked = true;
      kutucuk.disabled = true;
    }

    etiket.appendChild(kutucuk);
    etiket.appendChild(ogeYap('span', {}, ad));
    kap.appendChild(etiket);

    if (aciklama) {
      kap.appendChild(ogeYap('p', {}, aciklama));
    }

    var tablo = cerezTablosu(anahtar);

    if (tablo) {
      kap.appendChild(tablo);
    }

    return kap;
  }

  function kur() {
    kutu = ogeYap('div', {
      id: 'ncz',
      'class': 'ncz',
      role: 'dialog',
      'aria-modal': 'false',
      'aria-labelledby': 'ncz-b1',
      lang: V.dil || 'tr',
      hidden: ''
    });
    var ic = ogeYap('div', { 'class': 'ncz-ic' });

    // 1. katman
    var k1 = ogeYap('section', { 'class': 'ncz-k1' });
    k1.appendChild(ogeYap('h2', { id: 'ncz-b1', 'class': 'ncz-baslik', tabindex: '-1' }, M.banner_baslik));
    var p1 = ogeYap('p', { 'class': 'ncz-metin' }, M.banner_metin);

    if (V.politika && M.politika_baglanti_metin) {
      p1.appendChild(d.createTextNode(' '));
      p1.appendChild(ogeYap('a', { href: V.politika }, M.politika_baglanti_metin));
    }

    k1.appendChild(p1);
    var s1 = ogeYap('div', { 'class': 'ncz-dugmeler' });
    s1.appendChild(dugme('kabul', M.btn_kabul));
    s1.appendChild(dugme('ret', M.btn_ret));
    s1.appendChild(dugme('ayarlar', M.btn_ayarlar));
    k1.appendChild(s1);

    // 2. katman (tercih merkezi)
    var k2 = ogeYap('section', { 'class': 'ncz-k2', hidden: '' });
    k2.appendChild(ogeYap('h2', { id: 'ncz-b2', 'class': 'ncz-baslik', tabindex: '-1' }, M.ayar_baslik));

    if (M.ayar_giris) {
      k2.appendChild(ogeYap('p', { 'class': 'ncz-metin' }, M.ayar_giris));
    }

    k2.appendChild(kategori('zorunlu', M.kat_zorunlu_ad, M.kat_zorunlu_aciklama, true));
    k2.appendChild(kategori('analitik', M.kat_analitik_ad, M.kat_analitik_aciklama, false));
    k2.appendChild(kategori('pazarlama', M.kat_pazarlama_ad, M.kat_pazarlama_aciklama, false));
    var s2 = ogeYap('div', { 'class': 'ncz-dugmeler' });
    s2.appendChild(dugme('kabul', M.btn_kabul));
    s2.appendChild(dugme('ret', M.btn_ret));
    s2.appendChild(dugme('kaydet', M.btn_kaydet));
    k2.appendChild(s2);
    var kp = ogeYap('p', { 'class': 'ncz-kimlik', hidden: '' });
    kp.appendChild(d.createTextNode((E.kimlik || 'ID') + ': '));
    kp.appendChild(ogeYap('code'));
    k2.appendChild(kp);

    ic.appendChild(k1);
    ic.appendChild(k2);
    kutu.appendChild(ic);
    d.body.appendChild(kutu);

    kutu.addEventListener('click', function (olay) {
      var hedef = olay.target.closest ? olay.target.closest('[data-ncz]') : null;

      if (!hedef) {
        return;
      }

      var eylem = hedef.getAttribute('data-ncz');
      var katman = hedef.closest('.ncz-k1') ? 1 : 2;

      if (eylem === 'kabul') {
        kaydet(true, true, 'tumunu_kabul', katman);
      } else if (eylem === 'ret') {
        kaydet(false, false, 'tumunu_ret', katman);
      } else if (eylem === 'ayarlar') {
        ac(2, true);
      } else if (eylem === 'kaydet') {
        kaydet(secili('analitik'), secili('pazarlama'), 'secim_kaydet', katman);
      }
    });

    kutu.addEventListener('keydown', function (olay) {
      if (olay.key !== 'Escape' && olay.key !== 'Esc') {
        return;
      }

      if (!kutu.querySelector('.ncz-k2').hidden) {
        olay.preventDefault();

        if (N.tercih) {
          kapat();
        } else {
          ac(1, true);
        }
      }
    });
  }

  function secili(anahtar) {
    var k = kutu.querySelector('[data-ncz-kat="' + anahtar + '"]');
    return !!(k && k.checked);
  }

  function ac(katman, odak) {
    if (!kutu) {
      kur();
    }

    var k1 = kutu.querySelector('.ncz-k1');
    var k2 = kutu.querySelector('.ncz-k2');

    if (kutu.hidden && odak && !sonOdak) {
      sonOdak = d.activeElement;
    }

    // Kutucuklar kayıtlı tercihi gösterir; tercih yoksa boş (önceden işaretli kutu yok).
    var t = N.tercih || {};
    kutu.querySelector('[data-ncz-kat="analitik"]').checked = !!t.a;
    kutu.querySelector('[data-ncz-kat="pazarlama"]').checked = !!t.p;

    var kp = kutu.querySelector('.ncz-kimlik');

    if (KIMLIK.test(t.i || '')) {
      kp.querySelector('code').textContent = t.i;
      kp.hidden = false;
    } else {
      kp.hidden = true;
    }

    k1.hidden = katman !== 1;
    k2.hidden = katman !== 2;
    kutu.setAttribute('aria-labelledby', katman === 1 ? 'ncz-b1' : 'ncz-b2');
    kutu.classList.toggle('ncz-genis', katman === 2);
    kutu.hidden = false;

    // İlk açılışta odak sayfada kalır (pencere içeriği kapatmaz); kullanıcı açtıysa başlığa geçer.
    if (odak) {
      (katman === 1 ? k1 : k2).querySelector('.ncz-baslik').focus();
    }
  }

  function kapat() {
    if (!kutu) {
      return;
    }

    kutu.hidden = true;

    if (sonOdak && sonOdak.focus && d.contains(sonOdak)) {
      sonOdak.focus();
    }

    sonOdak = null;
  }

  // Sitedeki her "Çerez ayarları" bağlantısı tercih merkezini açar.
  d.addEventListener('click', function (olay) {
    var hedef = olay.target.closest ? olay.target.closest('a[href="#cerez-ayarlari"], [data-ncz-ac]') : null;

    if (!hedef) {
      return;
    }

    olay.preventDefault();
    sonOdak = hedef;
    ac(2, true);
  });

  // Açılış: izin verilmeyen kategorilerin eski çerezlerini sil; tercih yoksa pencereyi göster.
  temizle(N.tercih && N.tercih.a, N.tercih && N.tercih.p);

  // Önceki sayfada sunucuya ulaşmayan kayıt bir kez yeniden gönderilir (o anki sayfa bilinmiyor → yol null).
  if (N.tercih && N.tercih.g) {
    gonder(N.tercih, null);
  }

  if (!N.tercih) {
    ac(1, false);
  }

  if (location.hash === '#cerez-ayarlari') {
    ac(2, true);
  }

  N.ac = ac;
})(window, document);
