<?php
declare(strict_types=1);

function cardReceiptExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$functions = file_get_contents($root . '/function.php');
$index = file_get_contents($root . '/index.php');
$admin = file_get_contents($root . '/admin.php');
$cron = file_get_contents($root . '/cronbot/croncard.php');
$keyboard = file_get_contents($root . '/keyboard.php');

cardReceiptExpect(str_contains($keyboard, 'بررسی رسید در کانال'), 'Card settings entry is missing.');
cardReceiptExpect(str_contains($keyboard, 'بررسی رسید در گروه'), 'Group settings entry is missing.');
cardReceiptExpect(str_contains($admin, 'card_receipt_channel_input'), 'Channel configuration flow is missing.');
foreach (['admins', 'channel', 'group'] as $mode) {
    cardReceiptExpect(str_contains($admin, "cardReceiptReviewSaveSetting('card_receipt_review_mode', '" . $mode . "')"), 'Review mode is not selectable: ' . $mode);
}
cardReceiptExpect(str_contains($functions, "getPaySettingValue('card_receipt_review_mode', 'admins')"), 'Legacy admin delivery is not the default.');
cardReceiptExpect(str_contains($functions, "cardReceiptReviewGroupTarget()"), 'Group topic validation is missing.');
cardReceiptExpect(str_contains($functions, "cardReceiptReviewChatId()"), 'Channel validation is missing.');
cardReceiptExpect(str_contains($admin, "telegram('createForumTopic'"), 'Group topic creation is missing.');
cardReceiptExpect(str_contains($admin, "card_receipt_review_topic_id"), 'Topic id is not persisted.');
cardReceiptExpect(str_contains($index, "cardReceiptSendToReviewDestination(\$photoid, \$caption, \$textsendrasid, \$Confirm_pay, \$PaymentReport['id_order'])"), 'Card receipt destination delivery is missing.');
cardReceiptExpect(str_contains($index, 'if (!$sentToReviewDestination)'), 'Admin fallback is missing.');
cardReceiptExpect(!str_contains($cron, '$manualReview'), 'The destination mode still disables automatic card approval.');
cardReceiptExpect(str_contains($cron, 'cardReceiptAutoConfirmEligible('), 'Receipt delay and exceptions are not applied uniformly.');
cardReceiptExpect(str_contains($cron, 'cardReceiptMarkAutoReviewedAtDestination('), 'Automatic approval leaves destination buttons active.');
cardReceiptExpect(str_contains($admin, "payment_Status='paid' WHERE id_order=? AND payment_Status='waiting'"), 'Manual approval can race automatic approval.');
cardReceiptExpect(str_contains($admin, "payment_Status='reject' WHERE id_order=? AND payment_Status='waiting'"), 'Manual rejection can race automatic approval.');
cardReceiptExpect(str_contains($functions, 'card_receipt_review_messages'), 'Destination message tracking is missing.');
cardReceiptExpect(str_contains($admin, 'cardReceiptMarkReviewedAtDestination($update, $datain'), 'Destination review actions are not finalized.');

require_once $root . '/cronbot/card_receipt_rules.php';
$receipt = ['payment_Status' => 'waiting', 'id_user' => '123', 'at_updated' => date('Y-m-d H:i:s', time() - 180)];
cardReceiptExpect(cardReceiptAutoConfirmEligible($receipt, time(), 2, []), 'An eligible receipt was skipped.');
cardReceiptExpect(!cardReceiptAutoConfirmEligible($receipt, time(), 5, []), 'Receipt was approved before the delay.');
cardReceiptExpect(!cardReceiptAutoConfirmEligible($receipt, time(), 2, [123]), 'Excluded user was auto approved.');
$receipt['payment_Status'] = 'paid';
cardReceiptExpect(!cardReceiptAutoConfirmEligible($receipt, time(), 2, []), 'Paid receipt was approved again.');

echo "card receipt channel tests: OK\n";
