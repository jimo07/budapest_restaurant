<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\domain\order\OrderPriceCalculator;
use app\domain\order\OrderStatusMachine;
use app\domain\order\OrderCancellationPolicy;
use app\domain\order\FulfillmentPolicy;
use app\domain\order\OrderAvailabilityPolicy;
use app\domain\order\PaymentPolicy;
use app\domain\auth\AdminPermissionPolicy;
use app\exception\BusinessException;
use app\support\QueryToken;

$tests = [];
$test = function (string $name, callable $callback) use (&$tests): void {
    try { $callback(); $tests[] = [$name, true, null]; }
    catch (Throwable $e) { $tests[] = [$name, false, $e->getMessage()]; }
};
$assertSame = function (mixed $expected, mixed $actual): void {
    if ($expected !== $actual) throw new RuntimeException('expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};
$assertThrows = function (callable $callback): void {
    try { $callback(); } catch (BusinessException) { return; }
    throw new RuntimeException('expected BusinessException');
};
$assertTrue = function (bool $actual): void {
    if (!$actual) throw new RuntimeException('expected true, got false');
};
$assertFalse = function (bool $actual): void {
    if ($actual) throw new RuntimeException('expected false, got true');
};

$test('price calculator uses cents and quantity', function () use ($assertSame): void {
    $result = (new OrderPriceCalculator())->calculate([['unit_price' => '19.90', 'quantity' => 3]], '8.00', '2.00');
    $assertSame('65.70', $result['payable_amount']);
});
$test('price calculator rejects empty cart', fn () => $assertThrows(fn () => (new OrderPriceCalculator())->calculate([])));
$test('price calculator rejects excessive discount', fn () => $assertThrows(fn () => (new OrderPriceCalculator())->calculate([['unit_price' => 10, 'quantity' => 1]], 0, 11)));
$test('price calculator rejects invalid quantity', fn () => $assertThrows(fn () => (new OrderPriceCalculator())->calculate([['unit_price' => 10, 'quantity' => 100]])));
$test('status machine accepts normal transition', fn () => (new OrderStatusMachine())->assertCanTransition('pending', 'confirmed'));
$test('status machine permits cancellation before preparation', fn () => (new OrderStatusMachine())->assertCanTransition('confirmed', 'cancelled'));
$test('status machine rejects skipping states', fn () => $assertThrows(fn () => (new OrderStatusMachine())->assertCanTransition('pending', 'completed')));
$test('status machine rejects changes after completion', fn () => $assertThrows(fn () => (new OrderStatusMachine())->assertCanTransition('completed', 'cancelled')));
$test('customer may cancel pending order', fn () => (new OrderCancellationPolicy())->assertCustomerCanCancel('pending'));
$test('customer may cancel confirmed order', fn () => (new OrderCancellationPolicy())->assertCustomerCanCancel('confirmed'));
$test('customer cannot cancel preparing order', fn () => $assertThrows(fn () => (new OrderCancellationPolicy())->assertCustomerCanCancel('preparing')));
$test('delivery starts with waiting delivery', fn () => $assertSame('waiting_delivery', (new FulfillmentPolicy())->initialStatus('delivery')));
$test('pickup starts with waiting pickup', fn () => $assertSame('waiting_pickup', (new FulfillmentPolicy())->initialStatus('takeaway')));
$test('dine in starts with waiting arrival', fn () => $assertSame('waiting_arrival', (new FulfillmentPolicy())->initialStatus('dine_in')));
$test('delivery cannot start before food is ready', fn () => $assertThrows(fn () => (new FulfillmentPolicy())->assertCanTransition('delivery', 'waiting_delivery', 'delivering', 'preparing')));
$test('delivery completes the order after delivery', function () use ($assertSame): void {
    $policy = new FulfillmentPolicy();
    $policy->assertCanTransition('delivery', 'delivering', 'delivered', 'fulfilling');
    $assertSame('completed', $policy->resultingOrderStatus('fulfilling', 'delivered'));
});
$test('dine in can seat after confirmation', fn () => (new FulfillmentPolicy())->assertCanTransition('dine_in', 'waiting_arrival', 'seated', 'confirmed'));
$test('dine in cannot serve before food is ready', fn () => $assertThrows(fn () => (new FulfillmentPolicy())->assertCanTransition('dine_in', 'seated', 'served', 'preparing')));
$test('fulfillment rejects another method flow', fn () => $assertThrows(fn () => (new FulfillmentPolicy())->assertCanTransition('takeaway', 'waiting_delivery', 'delivering', 'ready')));
$test('capacity accepts exact remaining units', fn () => (new OrderAvailabilityPolicy())->assertCapacity(8, 10, 2));
$test('capacity rejects overbooking', fn () => $assertThrows(fn () => (new OrderAvailabilityPolicy())->assertCapacity(9, 10, 2)));
$test('stock accepts unlimited inventory', fn () => (new OrderAvailabilityPolicy())->assertStock(null, 999, 5, 'item'));
$test('stock rejects overselling', fn () => $assertThrows(fn () => (new OrderAvailabilityPolicy())->assertStock(10, 8, 3, 'item')));
$test('paid order may be refunded', fn () => (new PaymentPolicy())->assertCanChange('completed', 'paid', 'refunded'));
$test('unpaid order cannot be refunded', fn () => $assertThrows(fn () => (new PaymentPolicy())->assertCanChange('completed', 'unpaid', 'refunded')));
$test('cancelled order cannot be marked paid', fn () => $assertThrows(fn () => (new PaymentPolicy())->assertCanChange('cancelled', 'unpaid', 'paid')));
$test('super admin may access settings', fn () => $assertTrue((new AdminPermissionPolicy())->allows('super_admin', 'PUT', 'api/v1/admin/settings')));
$test('order clerk may manage orders', fn () => $assertTrue((new AdminPermissionPolicy())->allows('order_clerk', 'PATCH', 'api/v1/admin/orders/12/status', 'confirmed')));
$test('order clerk cannot manage users', fn () => $assertFalse((new AdminPermissionPolicy())->allows('order_clerk', 'GET', 'api/v1/admin/users')));
$test('kitchen may start preparation', fn () => $assertTrue((new AdminPermissionPolicy())->allows('kitchen', 'PATCH', 'api/v1/admin/orders/12/status', 'preparing')));
$test('kitchen cannot confirm orders', fn () => $assertFalse((new AdminPermissionPolicy())->allows('kitchen', 'PATCH', 'api/v1/admin/orders/12/status', 'confirmed')));
$test('fulfillment may update fulfillment state', fn () => $assertTrue((new AdminPermissionPolicy())->allows('fulfillment', 'PATCH', 'api/v1/admin/orders/12/fulfillment')));
$test('fulfillment cannot update payment', fn () => $assertFalse((new AdminPermissionPolicy())->allows('fulfillment', 'PATCH', 'api/v1/admin/orders/12/payment')));
$test('query token round trip', function () use ($assertSame): void {
    $token = QueryToken::generate(); $assertSame(true, QueryToken::verify($token, QueryToken::hash($token)));
});
$test('query token rejects another token', function () use ($assertSame): void {
    $assertSame(false, QueryToken::verify(QueryToken::generate(), QueryToken::hash(QueryToken::generate())));
});

$failed = 0;
foreach ($tests as [$name, $passed, $error]) {
    echo ($passed ? 'PASS' : 'FAIL') . "  {$name}" . ($error ? ": {$error}" : '') . PHP_EOL;
    if (!$passed) $failed++;
}
echo sprintf("%d tests, %d failed\n", count($tests), $failed);
exit($failed === 0 ? 0 : 1);
