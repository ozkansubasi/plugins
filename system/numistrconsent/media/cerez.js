/*!
 * plg_system_numistrconsent 1.0.0 — çerez onayı arayüzü (pencere + tercih merkezi).
 * Bağımlılık yok. <head> betiği window.NumistrCerez'i kurar; bu dosya ertelenmiş yüklenir.
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

  // --- çerez işleri -------------------------------------------------------

  function tercihYaz(a, p) {
    var deger = encodeURIComponent(JSON.stringify({ v: A.v, t: Date.now(), a: a ? 1 : 0, p: p ? 1 : 0 }));
    d.cookie = A.c + '=' + deger + '; Max-Age=' + A.s + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
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
      sil(/^_ga($|_)/);
    }

    if (!p) {
      sil(/^_gcl_/);

      try {
        Object.keys(w.localStorage).forEach(function (k) {
          if (/^_gcl/.test(k)) {
            w.localStorage.removeItem(k);
          }
        });
      } catch (e) { /* depolama kapalı */ }
    }
  }

  function kaydet(a, p) {
    var once = N.tercih;
    var geriCekme = !!(once && ((once.a && !a) || (once.p && !p)));

    tercihYaz(a, p);
    N.tercih = { v: A.v, a: a ? 1 : 0, p: p ? 1 : 0 };
    N.guncelle(a, p);
    temizle(a, p);

    if (geriCekme) {
      // GTM bellekte kalmasın: çerezler silindi, sayfa yeniden yüklenir.
      w.location.reload();
      return;
    }

    kapat();
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

      if (eylem === 'kabul') {
        kaydet(true, true);
      } else if (eylem === 'ret') {
        kaydet(false, false);
      } else if (eylem === 'ayarlar') {
        ac(2, true);
      } else if (eylem === 'kaydet') {
        kaydet(secili('analitik'), secili('pazarlama'));
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

  if (!N.tercih) {
    ac(1, false);
  }

  if (location.hash === '#cerez-ayarlari') {
    ac(2, true);
  }

  N.ac = ac;
})(window, document);
