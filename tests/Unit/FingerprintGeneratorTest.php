<?php

declare(strict_types=1);

namespace DeferQ\Tests\Unit;

use DeferQ\Fingerprint\DefaultFingerprintGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FingerprintGeneratorTest extends TestCase
{
    private DefaultFingerprintGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new DefaultFingerprintGenerator();
    }

    #[Test]
    public function same_params_different_key_order_produce_same_fingerprint(): void
    {
        $fp1 = $this->generator->generate('report', ['year' => 2024, 'format' => 'xlsx']);
        $fp2 = $this->generator->generate('report', ['format' => 'xlsx', 'year' => 2024]);

        self::assertSame($fp1, $fp2);
    }

    #[Test]
    public function different_params_produce_different_fingerprint(): void
    {
        $fp1 = $this->generator->generate('report', ['year' => 2024]);
        $fp2 = $this->generator->generate('report', ['year' => 2025]);

        self::assertNotSame($fp1, $fp2);
    }

    #[Test]
    public function different_names_produce_different_fingerprint(): void
    {
        $fp1 = $this->generator->generate('report.generate', ['year' => 2024]);
        $fp2 = $this->generator->generate('report.export', ['year' => 2024]);

        self::assertNotSame($fp1, $fp2);
    }

    #[Test]
    public function fingerprint_is_sha256_hex(): void
    {
        $fp = $this->generator->generate('test', ['a' => 1]);

        self::assertSame(64, strlen($fp));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fp);
    }

    #[Test]
    public function nested_params_with_different_key_order_produce_same_fingerprint(): void
    {
        $fp1 = $this->generator->generate('report', [
            'filters' => ['status' => 'active', 'type' => 'premium'],
            'year' => 2024,
        ]);
        $fp2 = $this->generator->generate('report', [
            'year' => 2024,
            'filters' => ['type' => 'premium', 'status' => 'active'],
        ]);

        self::assertSame($fp1, $fp2);
    }

    #[Test]
    public function empty_params_produce_consistent_fingerprint(): void
    {
        $fp1 = $this->generator->generate('task', []);
        $fp2 = $this->generator->generate('task', []);

        self::assertSame($fp1, $fp2);
    }
}
