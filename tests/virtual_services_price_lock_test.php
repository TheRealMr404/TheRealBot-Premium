<?php

require_once dirname(__DIR__) . '/telegram_products.php';

function priceLockExpect($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

priceLockExpect(
    telegramProductsInvoicePriceLocked(['created_at' => date('Y-m-d H:i:s', time() - 599)], 10),
    'An invoice younger than ten minutes must keep its quoted price.'
);
priceLockExpect(
    !telegramProductsInvoicePriceLocked(['created_at' => date('Y-m-d H:i:s', time() - 601)], 10),
    'An invoice older than ten minutes must no longer lock an outdated price.'
);
priceLockExpect(
    !telegramProductsInvoicePriceLocked(['created_at' => 'invalid'], 10),
    'An invalid invoice timestamp must not lock a price.'
);

echo "virtual_services_price_lock_test: OK\n";
