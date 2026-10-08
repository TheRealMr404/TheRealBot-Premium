<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/telegram_products.php';
require_once dirname(__DIR__) . '/telegram_products_features.php';

function identityExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

identityExpect(telegramProductsIdentityModeLabel('phone') === 'تأیید شماره', 'Phone mode label is missing.');
identityExpect(telegramProductsIdentityNormalizePhone('+989121234567') === '+989121234567', 'A valid shared phone was rejected.');
identityExpect(telegramProductsIdentityNormalizePhone('abc123') === '', 'Invalid phone was accepted.');
identityExpect(telegramProductsIdentityNationalIdValid('1234567891'), 'Valid national ID checksum was rejected.');
identityExpect(!telegramProductsIdentityNationalIdValid('1234567890'), 'Invalid national ID checksum was accepted.');
identityExpect(!telegramProductsIdentityNationalIdValid('1111111111'), 'Repeated national ID was accepted.');

$phone = ['phone_verified_at' => '2026-10-08 12:00:00', 'status' => 'none'];
identityExpect(telegramProductsIdentitySatisfied('none', null), 'Optional identity mode should not block purchases.');
identityExpect(!telegramProductsIdentitySatisfied('phone', null), 'Missing phone was accepted.');
identityExpect(telegramProductsIdentitySatisfied('phone', $phone), 'Verified phone was rejected.');
identityExpect(!telegramProductsIdentitySatisfied('full', $phone), 'Phone-only identity was accepted for full mode.');
$phone['status'] = 'pending';
identityExpect(!telegramProductsIdentitySatisfied('full', $phone), 'Pending full identity was accepted.');
$phone['status'] = 'approved';
identityExpect(telegramProductsIdentitySatisfied('full', $phone), 'Approved full identity was rejected.');
identityExpect(telegramProductsAdminPermissionForRequest('vsa_identity_view_123') === 'identity', 'Identity review is not permission-protected.');
identityExpect(telegramProductsAdminPermissionForRequest('vsa_identity_set_2_full') === 'catalog', 'Identity mode editing is not catalog-protected.');

$source = file_get_contents(dirname(__DIR__) . '/telegram_products.php');
$fragmentSource = file_get_contents(dirname(__DIR__) . '/telegram_fragment.php');
identityExpect(str_contains($source, 'telegramProductsIdentityGate($product)'), 'Product purchase does not check identity.');
identityExpect(str_contains($source, 'telegramProductsIdentityGet($from_id, true)'), 'Payment does not lock the identity row.');
identityExpect(str_contains($fragmentSource, "telegramProductsIdentityProduct('fg')"), 'Fragment purchases are not linked to virtual-services identity.');
identityExpect(str_contains($fragmentSource, 'telegramProductsIdentityGet($from_id, true)'), 'Fragment payment does not lock the identity row.');
echo "virtual services identity tests: OK\n";
