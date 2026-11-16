#!/usr/bin/env bash
# Çerez onayı uçtan uca denemesi (başsız Chrome + DevTools protokolü, yerel kopya).
# Canlı ana sayfanın (TR + EN) HTML'i eklentiden geçirilir, /tr/app sayfası numistrapp'ten üretilir,
# 127.0.0.1'de sunulur. GA4/Ads ölçüm adresleri engellenir (canlı mülke isabet düşmez), istekler yine sayılır.
# Gerekli: php, node ≥ 22 (yerleşik WebSocket), Google Chrome. Ekran görüntüleri: $NCZ_DIR/cikti/
set -euo pipefail
cd "$(dirname "$0")"
export NCZ_DIR="${NCZ_DIR:-$(mktemp -d /tmp/ncz_e2e.XXXX)}"
SITE_PORT="${NCZ_SITE_PORT:-18791}"; CDP_PORT="${NCZ_CDP:-19333}"
export NCZ_SITE="http://127.0.0.1:$SITE_PORT" NCZ_CDP="$CDP_PORT"
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
mkdir -p "$NCZ_DIR/site/tr/app" "$NCZ_DIR/site/en" "$NCZ_DIR/site/media/plg_system_numistrconsent"
curl -fsS "https://www.numistr.org/tr/?nc=$(date +%s)" -o "$NCZ_DIR/ham_tr.html"
curl -fsS "https://www.numistr.org/en/?nc=$(date +%s)" -o "$NCZ_DIR/ham_en.html"
cp ../../media/* "$NCZ_DIR/site/media/plg_system_numistrconsent/"
php kur.php "$NCZ_DIR"
if lsof -nP -iTCP:"$SITE_PORT" -sTCP:LISTEN >/dev/null 2>&1; then echo "kapı $SITE_PORT dolu (NCZ_SITE_PORT ile değiştir)"; exit 2; fi
php -S "127.0.0.1:$SITE_PORT" -t "$NCZ_DIR/site" >"$NCZ_DIR/php.log" 2>&1 & PHP=$!
"$CHROME" --headless=new --remote-debugging-port="$CDP_PORT" --user-data-dir="$NCZ_DIR/prof" --no-first-run \
  --no-default-browser-check --disable-extensions about:blank >"$NCZ_DIR/chrome.log" 2>&1 & CH=$!
trap 'kill $PHP $CH 2>/dev/null || true' EXIT
curl -s -o /dev/null --retry 30 --retry-connrefused --retry-delay 1 "http://127.0.0.1:$CDP_PORT/json/version"
curl -s -o /dev/null --retry 10 --retry-connrefused --retry-delay 1 "$NCZ_SITE/tr/"
node cdp.mjs
echo "çıktı: $NCZ_DIR/cikti"
