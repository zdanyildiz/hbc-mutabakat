<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Minimal PHPUnit TestCase polyfill if PHPUnit is not loaded via vendor/autoload.php
if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    eval('
    namespace PHPUnit\Framework;
    abstract class TestCase {
        protected static int $assertionCount = 0;
        protected function setUp(): void {}
        protected function tearDown(): void {}
        public static function getAssertionCount(): int { return self::$assertionCount; }
        public function assertTrue(mixed $cond, string $msg = ""): void {
            self::$assertionCount++;
            if ($cond !== true) throw new \RuntimeException($msg ?: "Failed asserting that value is true.");
        }
        public function assertNull(mixed $val, string $msg = ""): void {
            self::$assertionCount++;
            if ($val !== null) throw new \RuntimeException($msg ?: "Failed asserting that value is null.");
        }
        public function assertNotNull(mixed $val, string $msg = ""): void {
            self::$assertionCount++;
            if ($val === null) throw new \RuntimeException($msg ?: "Failed asserting that value is not null.");
        }
        public function assertSame(mixed $expected, mixed $actual, string $msg = ""): void {
            self::$assertionCount++;
            if ($expected !== $actual) {
                throw new \RuntimeException($msg ?: sprintf("Failed asserting that %s is identical to %s.", var_export($actual, true), var_export($expected, true)));
            }
        }
        public function assertGreaterThan(int|float $expected, int|float $actual, string $msg = ""): void {
            self::$assertionCount++;
            if (!($actual > $expected)) {
                throw new \RuntimeException($msg ?: "Failed asserting that {$actual} is greater than {$expected}.");
            }
        }
        public function assertArrayHasKey(string|int $key, array $array, string $msg = ""): void {
            self::$assertionCount++;
            if (!array_key_exists($key, $array)) {
                throw new \RuntimeException($msg ?: "Failed asserting that array has key {$key}.");
            }
        }
        public function assertCount(int $expected, countable|array $haystack, string $msg = ""): void {
            self::$assertionCount++;
            if (count($haystack) !== $expected) {
                throw new \RuntimeException($msg ?: sprintf("Failed asserting count %d matches expected %d.", count($haystack), $expected));
            }
        }
        public function assertStringContainsString(string $needle, string $haystack, string $msg = ""): void {
            self::$assertionCount++;
            if (!str_contains($haystack, $needle)) {
                throw new \RuntimeException($msg ?: "Failed asserting that \"{$haystack}\" contains \"{$needle}\".");
            }
        }
        public function fail(string $msg = "Test failed."): never {
            throw new \RuntimeException($msg);
        }
    }
    ');
}

require_once __DIR__ . '/MultiTenantSaasTest.php';

$testClass = new \ReflectionClass(\Tests\MultiTenantSaasTest::class);
$methods = $testClass->getMethods(\ReflectionMethod::IS_PUBLIC);

$passed = 0;
$failed = 0;

echo "HBC Mutabakat Multi-Tenant SaaS Test Suite\n";
echo "==========================================\n";

foreach ($methods as $method) {
    if (!str_starts_with($method->getName(), 'test')) {
        continue;
    }

    $instance = new \Tests\MultiTenantSaasTest($method->getName());
    $setUp = $testClass->getMethod('setUp');
    $setUp->setAccessible(true);
    $tearDown = $testClass->getMethod('tearDown');
    $tearDown->setAccessible(true);

    try {
        $setUp->invoke($instance);
        $method->invoke($instance);
        $tearDown->invoke($instance);
        echo " [PASS] " . $method->getName() . "\n";
        $passed++;
    } catch (\Throwable $e) {
        echo " [FAIL] " . $method->getName() . " -> " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "------------------------------------------\n";
echo "Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";

exit($failed > 0 ? 1 : 0);
