<?php
/**
 * Paneldeki "Safe Cap" ayarı (1.16.4). 2026-09-27: alan bağlı değildi, panelde 4000
 * kaydedilse de sınır 2000 kalıyordu → Misya bölge listesi 422.
 */

require_once $root . '/helpers/ConfigParams.php';

check('kaydedilmemiş (null) → sabit', NumisTRConfigParams::safeCap(null, 2000) === 2000);
check('boş metin → sabit', NumisTRConfigParams::safeCap('', 2000) === 2000);
check('sayı olmayan → sabit', NumisTRConfigParams::safeCap('abc', 2000) === 2000);
check('panel değeri (metin) uygulanır', NumisTRConfigParams::safeCap('4000', 2000) === 4000);
check('panel değeri (sayı) uygulanır', NumisTRConfigParams::safeCap(3000, 2000) === 3000);
check('alt sınıra kırpılır', NumisTRConfigParams::safeCap('5', 2000) === NumisTRConfigParams::SAFE_CAP_MIN);
check('üst sınıra kırpılır', NumisTRConfigParams::safeCap('999999', 2000) === NumisTRConfigParams::SAFE_CAP_MAX);
check('Misya (tümü 2800) 4000 sınırın altında', 2800 <= NumisTRConfigParams::safeCap('4000', 2000));
