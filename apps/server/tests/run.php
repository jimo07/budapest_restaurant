<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\domain\order\OrderPriceCalculator;
use app\domain\order\OrderStatusMachine;
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

$test('price calculator uses cents and quantity', function () use ($assertSame): void {
    $result = (new OrderPriceCalculator())->calculate([['unit_price' => '19.90', 'quantity' => 3]], '8.00', '2.00');
    $assertSame('65.70', $result['payable_amount']);
});
$test('price calculator rejects empty cart', fn () => $assertThrows(fn () => (new OrderPriceCalculator())->calculate([])));
$test('status machine accepts normal transition', fn () => (new OrderStatusMachine())->assertCanTransition('pending', 'confirmed'));
$test('status machine rejects skipping states', fn () => $assertThrows(fn () => (new OrderStatusMachine())->assertCanTransition('pending', 'completed')));
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
