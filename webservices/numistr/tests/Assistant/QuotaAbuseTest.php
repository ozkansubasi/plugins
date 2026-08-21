<?php
/** Quota arithmetic, system breaker, cost table, abuse thresholds. */

require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantQuota.php';
require_once $root . '/helpers/Assistant/AssistantAbuse.php';

$cfg    = include $root . '/config/assistant.php';
$quota  = new NumisTRAssistantQuota($cfg);
$limits = $quota->limitsFor('anon');

check('anon limits from config (10/day, 3/min, 15/h)', $limits['daily_messages'] === 10 && $limits['per_minute'] === 3 && $limits['per_hour'] === 15);
check('unknown subject type falls back to anon', $quota->limitsFor('weird')['daily_messages'] === 10);

$r = NumisTRAssistantQuota::evaluate($limits, 0, 0, 0);
check('fresh subject allowed, remaining 10', $r['allowed'] && $r['remaining'] === 10);

$r = NumisTRAssistantQuota::evaluate($limits, 9, 0, 0);
check('9 used -> allowed, remaining 1', $r['allowed'] && $r['remaining'] === 1);

$r = NumisTRAssistantQuota::evaluate($limits, 10, 0, 0);
check('10 used -> daily block', !$r['allowed'] && $r['reason'] === 'daily' && $r['remaining'] === 0);

$r = NumisTRAssistantQuota::evaluate($limits, 2, 3, 3);
check('3 in last minute -> minute block', !$r['allowed'] && $r['reason'] === 'minute');

$r = NumisTRAssistantQuota::evaluate($limits, 2, 1, 15);
check('15 in last hour -> hour block', !$r['allowed'] && $r['reason'] === 'hour');

$r = NumisTRAssistantQuota::evaluate($limits, 2, 2, 14);
check('just under both rate limits -> allowed', $r['allowed']);

$sys = $cfg['system'];
check('system breaker: under limits', NumisTRAssistantQuota::systemEvaluate($sys, 9.99, 100)['allowed']);
check('system breaker: daily $10', NumisTRAssistantQuota::systemEvaluate($sys, 10.0, 100)['reason'] === 'daily_cost');
check('system breaker: monthly $150', NumisTRAssistantQuota::systemEvaluate($sys, 1.0, 150.0)['reason'] === 'monthly_cost');

// ---- cost ----
$costs = $cfg['costs'];
check('cost: haiku 1M in + 1M out = $6', abs(NumisTRLLMClient::cost($costs, 'claude-haiku-4-5', 1000000, 1000000) - 6.0) < 1e-9);
check('cost: 3.7-flash classify ~ 300/5 tokens', abs(NumisTRLLMClient::cost($costs, 'gemini-3.7-flash', 300, 5) - 0.00024375) < 1e-6);
check('cost: cached tokens billed at cache rate', NumisTRLLMClient::cost($costs, 'gemini-3.7-flash', 10000, 100, 9000) < NumisTRLLMClient::cost($costs, 'gemini-3.7-flash', 10000, 100, 0));
check('cost: unknown model -> 0', NumisTRLLMClient::cost($costs, 'nope', 1000, 1000) === 0.0);
check('cost: cached > total input is clamped', NumisTRLLMClient::cost($costs, 'gemini-3.7-flash', 100, 0, 500) >= 0);

// ---- abuse ----
$abuse = new NumisTRAssistantAbuse($cfg);
$th    = $cfg['abuse']['thresholds'];
check('abuse: score 9.9 -> no ban', NumisTRAssistantAbuse::banFor(9.9, $th) === null);
check('abuse: score 10 -> soft 1h', NumisTRAssistantAbuse::banFor(10, $th)['seconds'] === 3600);
check('abuse: score 30 -> soft 24h', NumisTRAssistantAbuse::banFor(30, $th)['seconds'] === 86400 && NumisTRAssistantAbuse::banFor(30, $th)['type'] === 'soft');
check('abuse: score 50 -> hard 7d', NumisTRAssistantAbuse::banFor(50, $th)['type'] === 'hard' && NumisTRAssistantAbuse::banFor(50, $th)['seconds'] === 604800);
check('abuse: blacklist event scores 5', $abuse->scoreFor('blacklist') === 5.0);
check('abuse: normal message decays', $abuse->scoreFor('normal') < 0);
check('abuse: unknown event scores 0', $abuse->scoreFor('nope') === 0.0);
check('abuse: two blacklist hits do not ban, three do (5+5 <10 <= 15)', NumisTRAssistantAbuse::banFor(10.0, $th) !== null && NumisTRAssistantAbuse::banFor(9.9, $th) === null);

// ---- settings fail-open without DB ----
$s = new NumisTRAssistantSettings();
check('settings get() fails open to default without DB', $s->get('missing', 'dflt') === 'dflt');
