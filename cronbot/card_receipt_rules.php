<?php

function cardReceiptAutoConfirmEligible($receipt, $now, $delayMinutes, $exceptions)
{
    if (($receipt['payment_Status'] ?? '') !== 'waiting') return false;
    $submittedAt = strtotime((string) ($receipt['at_updated'] ?? ''));
    if ($submittedAt === false) return false;
    $elapsed = (int) $now - $submittedAt;
    if ($elapsed < 0 || $elapsed >= 3600 || $elapsed <= max(0, (int) $delayMinutes) * 60) return false;
    return !in_array((string) ($receipt['id_user'] ?? ''), array_map('strval', (array) $exceptions), true);
}
