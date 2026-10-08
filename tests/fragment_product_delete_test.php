<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/telegram_fragment.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE telegram_fragment_products (id INTEGER PRIMARY KEY, title TEXT)');
$pdo->exec('CREATE TABLE telegram_fragment_orders (id INTEGER PRIMARY KEY, product_id INTEGER, product_title TEXT, status TEXT)');
$pdo->exec("INSERT INTO telegram_fragment_products VALUES (1, 'Stars 50')");
$pdo->exec("INSERT INTO telegram_fragment_orders VALUES (1, 1, 'Stars 50', 'draft'), (2, 1, 'Stars 50', 'completed')");

telegramFragmentDeleteProduct(1);

if ((int) $pdo->query('SELECT COUNT(*) FROM telegram_fragment_products')->fetchColumn() !== 0) {
    throw new RuntimeException('The product was not deleted.');
}
$orders = $pdo->query('SELECT product_id, product_title, status FROM telegram_fragment_orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if (count($orders) !== 2 || $orders[0]['status'] !== 'cancelled' || $orders[1]['status'] !== 'completed') {
    throw new RuntimeException('Draft cancellation or paid order history failed.');
}
if ($orders[0]['product_id'] !== null || $orders[1]['product_id'] !== null || $orders[1]['product_title'] !== 'Stars 50') {
    throw new RuntimeException('Order snapshots were not preserved correctly.');
}

echo "fragment_product_delete_test: OK\n";
