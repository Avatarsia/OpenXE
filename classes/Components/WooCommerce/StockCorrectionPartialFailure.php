<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use DomainException;

/** Raised when at least one storage-place booking succeeded before a later one failed. */
final class StockCorrectionPartialFailure extends DomainException
{
}
