<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Enum;

enum WarrantyStatus: string
{
    case Yes = 'yes';
    case No = 'no';
    case Unknown = 'unknown';
}
