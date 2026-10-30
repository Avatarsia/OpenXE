<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Api;

use Xentral\Modules\RepairIntegration\Exception\AuthenticationException;

final class RepairApiAuth
{
    private const TIMESTAMP_TOLERANCE = 300; // @php83: add type int
    private const HASH_ALGORITHM = 'sha256'; // @php83: add type string

    public function validateRequest(
        string $payload,
        string $signature,
        string $timestamp,
        string $sharedSecret,
    ): bool {
        if ($sharedSecret === '') {
            throw new AuthenticationException('SHARED_SECRET_NOT_CONFIGURED');
        }

        $requestTime = (int)$timestamp;
        if ($requestTime === 0 || abs(time() - $requestTime) > self::TIMESTAMP_TOLERANCE) {
            throw new AuthenticationException('TIMESTAMP_EXPIRED');
        }

        $expected = hash_hmac(
            self::HASH_ALGORITHM,
            $timestamp . '.' . $payload,
            $sharedSecret,
        );

        if (!hash_equals($expected, $signature)) {
            throw new AuthenticationException('INVALID_SIGNATURE');
        }

        return true;
    }

    public function generateSignature(
        string $payload,
        string $timestamp,
        string $sharedSecret,
    ): string {
        return hash_hmac(
            self::HASH_ALGORITHM,
            $timestamp . '.' . $payload,
            $sharedSecret,
        );
    }
}
