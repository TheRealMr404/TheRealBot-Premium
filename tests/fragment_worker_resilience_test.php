<?php

function expectTrue($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$fragment = file_get_contents($root . '/telegram_fragment.php');
$worker = file_get_contents($root . '/cronbot/fragment_orders.php');
$kit = file_get_contents($root . '/fragment-kit/php/FragmentKit.php');

// The user callback handler runs discount/cancel queries and must declare $pdo.
preg_match('/function telegramFragmentHandleUserRequestInner\(\)\s*\{\s*global ([^;]+);/', $fragment, $match);
expectTrue(isset($match[1]) && preg_match('/\$pdo\b/', $match[1]) === 1, 'The Fragment user handler does not declare $pdo (discount and cancel buttons break).');

// A paid order must start immediately instead of waiting for the next cron tick.
$payStart = strpos($fragment, 'function telegramFragmentPayOrder');
$pay = substr($fragment, $payStart, strpos($fragment, 'function telegramFragmentStatusLabel') - $payStart);
expectTrue(strpos($pay, 'telegramFragmentKickWorker()') !== false, 'Payment does not start the order worker.');
expectTrue(strpos($fragment, 'function telegramFragmentRunWorkerAfterResponse') !== false, 'Deferred worker fallback is missing.');

// Delivered orders must not be pushed back to the retry queue by a notification failure.
expectTrue(strpos($fragment, 'function telegramFragmentNotifySafe') !== false, 'Order notifications are not isolated from order state.');
$procStart = strpos($fragment, 'function telegramFragmentProcessOrder');
$processor = substr($fragment, $procStart, strpos($fragment, 'function telegramFragmentProcessPendingOrders') - $procStart);
expectTrue(strpos($processor, "telegramFragmentNotifySafe(\$order, 'completed')") !== false, 'Completion notice is not isolated.');

// Paid orders are delivered even when new sales are switched off.
$queueStart = strpos($fragment, 'function telegramFragmentProcessPendingOrders');
$queue = substr($fragment, $queueStart, 900);
expectTrue(strpos($queue, "telegramFragmentSetting('enabled', '0') !== '1') return 0") === false, 'Paid orders are not processed while sales are disabled.');

// Session and signer problems hold the order instead of refunding it.
foreach (['session_expired', 'login_failed', 'blocked', 'signer_auth', 'page_changed'] as $code) {
    $holdStart = strpos($fragment, 'function telegramFragmentHoldCodes');
    $hold = substr($fragment, $holdStart, 700);
    expectTrue(strpos($hold, "'{$code}'") !== false, "{$code} must keep the paid order queued.");
}

// Orders come first; the exchange-rate poll must not delay them.
expectTrue(strpos($worker, 'telegramFragmentProcessPendingOrders(5)') < strpos($worker, 'telegramFragmentRefreshRateIfDue()'), 'The worker polls the exchange rate before delivering orders.');

// An expired transaction must be resendable.
expectTrue(strpos($kit, "\$e->errCode === 'tx_failed'") !== false && strpos($kit, 'tx_hash = NULL') !== false, 'An expired transaction can never be resent.');

// Failures are reported with full technical detail to the group's error topic (like config-creation errors).
expectTrue(strpos($fragment, 'function telegramFragmentReportError') !== false && strpos($fragment, "report='errorreport'") !== false, 'Fragment failures are not reported to the group error topic.');

echo "fragment worker resilience tests: OK\n";
