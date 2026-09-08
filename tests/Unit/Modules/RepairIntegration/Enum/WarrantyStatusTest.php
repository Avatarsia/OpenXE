<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Enum;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Enum\WarrantyStatus;

class WarrantyStatusTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $cases = WarrantyStatus::cases();
        self::assertCount(3, $cases);
    }

    public function testBackedValues(): void
    {
        self::assertSame('yes', WarrantyStatus::Yes->value);
        self::assertSame('no', WarrantyStatus::No->value);
        self::assertSame('unknown', WarrantyStatus::Unknown->value);
    }

    public function testFromValidValue(): void
    {
        self::assertSame(WarrantyStatus::Yes, WarrantyStatus::from('yes'));
        self::assertSame(WarrantyStatus::No, WarrantyStatus::from('no'));
        self::assertSame(WarrantyStatus::Unknown, WarrantyStatus::from('unknown'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(WarrantyStatus::tryFrom('maybe'));
        self::assertNull(WarrantyStatus::tryFrom(''));
        self::assertNull(WarrantyStatus::tryFrom('Yes'));
    }
}
