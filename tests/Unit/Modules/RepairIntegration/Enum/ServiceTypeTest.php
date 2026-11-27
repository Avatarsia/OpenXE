<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Enum;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Enum\ServiceType;

class ServiceTypeTest extends TestCase
{
    public function testFromSubjectTagParsesRepairTag(): void
    {
        $result = ServiceType::fromSubjectTag('[REP]');
        self::assertSame(ServiceType::Reparatur, $result);
    }

    public function testFromSubjectTagParsesLowercase(): void
    {
        $result = ServiceType::fromSubjectTag('[rep]');
        self::assertSame(ServiceType::Reparatur, $result);
    }

    public function testFromSubjectTagParsesAllTypes(): void
    {
        self::assertSame(ServiceType::Wartung, ServiceType::fromSubjectTag('[WRT]'));
        self::assertSame(ServiceType::ReverseEngineering, ServiceType::fromSubjectTag('[REV]'));
        self::assertSame(ServiceType::Individualisierung, ServiceType::fromSubjectTag('[IND]'));
    }

    public function testFromSubjectTagReturnsNullForUnknown(): void
    {
        self::assertNull(ServiceType::fromSubjectTag('[XXX]'));
        self::assertNull(ServiceType::fromSubjectTag(''));
        self::assertNull(ServiceType::fromSubjectTag('REP'));
    }

    public function testRoundTripTagParsing(): void
    {
        foreach (ServiceType::cases() as $type) {
            $parsed = ServiceType::fromSubjectTag($type->subjectTag());
            self::assertSame($type, $parsed, "Round-trip failed for {$type->value}");
        }
    }

    public function testStatusCategoryMapsToValidDbValues(): void
    {
        $validCategories = ['repair', 'maintenance', 'reverse_engineering', 'individualization'];
        foreach (ServiceType::cases() as $type) {
            self::assertContains($type->statusCategory(), $validCategories);
        }
    }

    public function testAllTypesHaveNonEmptyLabel(): void
    {
        foreach (ServiceType::cases() as $type) {
            self::assertNotEmpty($type->label());
        }
    }

    public function testSubjectTagFormat(): void
    {
        foreach (ServiceType::cases() as $type) {
            $tag = $type->subjectTag();
            self::assertStringStartsWith('[', $tag);
            self::assertStringEndsWith(']', $tag);
            self::assertGreaterThanOrEqual(5, strlen($tag));
        }
    }

    public function testLabelReturnsExpectedValues(): void
    {
        self::assertSame('Reparatur', ServiceType::Reparatur->label());
        self::assertSame('Wartung', ServiceType::Wartung->label());
        self::assertSame('Reverse Engineering', ServiceType::ReverseEngineering->label());
        self::assertSame('Individualisierung', ServiceType::Individualisierung->label());
    }

    public function testStatusCategoryExactMapping(): void
    {
        self::assertSame('repair', ServiceType::Reparatur->statusCategory());
        self::assertSame('maintenance', ServiceType::Wartung->statusCategory());
        self::assertSame('reverse_engineering', ServiceType::ReverseEngineering->statusCategory());
        self::assertSame('individualization', ServiceType::Individualisierung->statusCategory());
    }

    public function testFromSubjectTagTrimsWhitespace(): void
    {
        self::assertSame(ServiceType::Reparatur, ServiceType::fromSubjectTag('  [REP]  '));
        self::assertSame(ServiceType::Wartung, ServiceType::fromSubjectTag("\t[WRT]\n"));
    }
}
