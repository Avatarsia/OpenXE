<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Enum;

enum ServiceType: string
{
    case Reparatur = 'reparatur';
    case Wartung = 'wartung';
    case ReverseEngineering = 'reverse_engineering';
    case Individualisierung = 'individualisierung';

    public function label(): string
    {
        return match ($this) {
            self::Reparatur => 'Reparatur',
            self::Wartung => 'Wartung',
            self::ReverseEngineering => 'Reverse Engineering',
            self::Individualisierung => 'Individualisierung',
        };
    }

    public function subjectTag(): string
    {
        return match ($this) {
            self::Reparatur => '[REP]',
            self::Wartung => '[WRT]',
            self::ReverseEngineering => '[REV]',
            self::Individualisierung => '[IND]',
        };
    }

    public static function fromSubjectTag(string $tag): ?self
    {
        return match (strtoupper(trim($tag))) {
            '[REP]' => self::Reparatur,
            '[WRT]' => self::Wartung,
            '[REV]' => self::ReverseEngineering,
            '[IND]' => self::Individualisierung,
            default => null,
        };
    }

    public function statusCategory(): string
    {
        return match ($this) {
            self::Reparatur => 'repair',
            self::Wartung => 'maintenance',
            self::ReverseEngineering => 'reverse_engineering',
            self::Individualisierung => 'individualization',
        };
    }
}
