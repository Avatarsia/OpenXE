<?php

declare(strict_types=1);

namespace Xentral\Modules\LexwareOffice\Service;

use Xentral\Modules\LexwareOffice\Exception\LexwareOfficeException;
use Xentral\Modules\SystemConfig\SystemConfigModule;

final class LexwareOfficeConfigService
{
    private const NAMESPACE = 'lexwareoffice';
    private const KEY_API = 'api_key';
    private const KEY_SALT = 'encryption_salt';
    private const KEY_DEFAULT_CATEGORY = 'default_category_id';
    // Neutrale Default-Erloeskategorie (Einnahmen) — nur ein Platzhalter, der
    // User bucht die Belege manuell in Lexware um.
    private const DEFAULT_CATEGORY_ID = '8f8664a1-fd86-11e1-a21f-0800200c9a66';
    // Lexware categoryIds sind UUIDs (8-4-4-4-12 Hex).
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    // B6: Defense in depth gegen Header-Injection und Schema-Overflow.
    // Lexware API-Keys sind JWT-aehnlich / opaque Base64-URL-Tokens.
    private const MAX_API_KEY_LENGTH = 512;
    private const API_KEY_PATTERN = '/^[A-Za-z0-9._\-]+$/';

    public function __construct(private SystemConfigModule $config)
    {
    }

    public function saveApiKey(string $apiKey): void
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new LexwareOfficeException('Der Lexware Office API-Schlüssel darf nicht leer sein.');
        }
        if (strlen($apiKey) > self::MAX_API_KEY_LENGTH) {
            throw new LexwareOfficeException(
                sprintf(
                    'Der Lexware Office API-Schlüssel ist zu lang (max. %d Zeichen).',
                    self::MAX_API_KEY_LENGTH
                )
            );
        }
        if (!preg_match(self::API_KEY_PATTERN, $apiKey)) {
            throw new LexwareOfficeException(
                'Der Lexware Office API-Schlüssel enthaelt ungueltige Zeichen. Erlaubt sind A-Z, a-z, 0-9 sowie . _ -'
            );
        }

        $salt = $this->getOrCreateSalt();
        $encrypted = $this->encrypt($apiKey, $salt);
        $this->config->setValue(self::NAMESPACE, self::KEY_API, $encrypted);
    }

    public function getApiKey(): ?string
    {
        $encrypted = $this->config->tryGetValue(self::NAMESPACE, self::KEY_API);
        if (empty($encrypted)) {
            return null;
        }

        $salt = $this->config->tryGetValue(self::NAMESPACE, self::KEY_SALT);
        if (empty($salt)) {
            return null;
        }

        return $this->decrypt($encrypted, $salt);
    }

    public function hasApiKey(): bool
    {
        return $this->config->isKeyExisting(self::NAMESPACE, self::KEY_API);
    }

    public function deleteApiKey(): void
    {
        try {
            $this->config->deleteKey(self::NAMESPACE, self::KEY_API);
            // Salt is optional; remove to enforce fresh encryption material on next save.
            if ($this->config->isKeyExisting(self::NAMESPACE, self::KEY_SALT)) {
                $this->config->deleteKey(self::NAMESPACE, self::KEY_SALT);
            }
        } catch (\Throwable $exception) {
            throw new LexwareOfficeException('API-Schlüssel konnte nicht gelöscht werden.', 0, $exception);
        }
    }

    /**
     * Liefert die konfigurierte Default-Erloeskategorie (Voucher categoryId).
     *
     * Faellt auf DEFAULT_CATEGORY_ID zurueck, wenn kein Wert gespeichert ist
     * oder der gespeicherte Wert kein gueltiges UUID-Format hat.
     */
    public function getDefaultCategoryId(): string
    {
        $value = trim((string)$this->config->tryGetValue(self::NAMESPACE, self::KEY_DEFAULT_CATEGORY));
        if ($value === '' || !preg_match(self::UUID_PATTERN, $value)) {
            return self::DEFAULT_CATEGORY_ID;
        }

        return $value;
    }

    /**
     * Speichert die Default-Erloeskategorie. Leerer String setzt auf den
     * neutralen Default zurueck. Ein nicht-UUID-Wert wird abgelehnt.
     */
    public function saveDefaultCategoryId(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            // Reset auf Default: gespeicherten Wert entfernen (getDefaultCategoryId
            // liefert dann wieder DEFAULT_CATEGORY_ID).
            if ($this->config->isKeyExisting(self::NAMESPACE, self::KEY_DEFAULT_CATEGORY)) {
                $this->config->deleteKey(self::NAMESPACE, self::KEY_DEFAULT_CATEGORY);
            }

            return;
        }
        if (!preg_match(self::UUID_PATTERN, $id)) {
            throw new LexwareOfficeException(
                'Die Erloeskategorie muss eine gueltige UUID sein (Format: 8-4-4-4-12 Hex).'
            );
        }

        $this->config->setValue(self::NAMESPACE, self::KEY_DEFAULT_CATEGORY, $id);
    }

    private function encrypt(string $value, string $salt): string
    {
        $cipher = 'AES-256-CBC';
        $key = hash('sha256', $salt, true);
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivLength);

        $ciphertext = openssl_encrypt($value, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new LexwareOfficeException('API-Schlüssel konnte nicht verschlüsselt werden.');
        }

        $hmac = hash_hmac('sha256', $ciphertext, $key, true);

        return base64_encode($iv . $hmac . $ciphertext);
    }

    private function decrypt(string $encoded, string $salt): ?string
    {
        $cipher = 'AES-256-CBC';
        $payload = base64_decode($encoded, true);
        if ($payload === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length($cipher);
        $key = hash('sha256', $salt, true);

        $iv = substr($payload, 0, $ivLength);
        $hmac = substr($payload, $ivLength, 32);
        $ciphertext = substr($payload, $ivLength + 32);

        if ($iv === false || $hmac === false || $ciphertext === false) {
            return null;
        }

        $calcHmac = hash_hmac('sha256', $ciphertext, $key, true);
        if (!hash_equals($hmac, $calcHmac)) {
            return null;
        }

        $plain = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);

        return $plain === false ? null : $plain;
    }

    private function getOrCreateSalt(): string
    {
        $salt = $this->config->tryGetValue(self::NAMESPACE, self::KEY_SALT);
        if (!empty($salt)) {
            return $salt;
        }

        $salt = bin2hex(random_bytes(32));
        $this->config->setValue(self::NAMESPACE, self::KEY_SALT, $salt);

        return $salt;
    }
}
