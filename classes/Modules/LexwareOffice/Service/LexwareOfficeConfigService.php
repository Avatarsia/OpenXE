<?php

declare(strict_types=1);

namespace Xentral\Modules\LexwareOffice\Service;

use Xentral\Modules\LexwareOffice\Bootstrap;
use Xentral\Modules\LexwareOffice\Exception\LexwareOfficeException;
use Xentral\Modules\SystemConfig\SystemConfigModule;

final class LexwareOfficeConfigService
{
    private const NAMESPACE = 'lexwareoffice';
    private const KEY_API = 'api_key';
    private const KEY_SALT = 'encryption_salt';
    private const SCHEMA_V2_PREFIX = 'v2:';
    // Defense in depth gegen Header-Injection und Schema-Overflow.
    // Lexware API-Keys sind JWT-aehnlich / opaque Base64-URL-Tokens.
    private const MAX_API_KEY_LENGTH = 512;
    private const API_KEY_PATTERN = '/^[A-Za-z0-9._\-]+$/';

    public function __construct(
        private SystemConfigModule $config,
        private string $masterKeyPath
    ) {
    }

    public function getMasterKeyPath(): string
    {
        return $this->masterKeyPath;
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

        $masterKey = $this->getOrCreateMasterKey();
        $encrypted = $this->encryptV2($apiKey, $masterKey);
        $this->config->setValue(self::NAMESPACE, self::KEY_API, $encrypted);
        // V2 braucht den DB-Salt nicht mehr; wegraeumen, falls Legacy-Wert da war.
        if ($this->config->isKeyExisting(self::NAMESPACE, self::KEY_SALT)) {
            $this->config->deleteKey(self::NAMESPACE, self::KEY_SALT);
        }
    }

    public function getApiKey(): ?string
    {
        $stored = $this->config->tryGetValue(self::NAMESPACE, self::KEY_API);
        if (empty($stored)) {
            return null;
        }

        // V2: Master-Key aus Datei
        if (str_starts_with($stored, self::SCHEMA_V2_PREFIX)) {
            $masterKey = $this->readMasterKey();
            if ($masterKey === null) {
                return null;
            }
            return $this->decryptV2(substr($stored, strlen(self::SCHEMA_V2_PREFIX)), $masterKey);
        }

        // Legacy (V1): DB-Salt
        $salt = $this->config->tryGetValue(self::NAMESPACE, self::KEY_SALT);
        if (empty($salt)) {
            return null;
        }
        $plain = $this->decryptV1($stored, $salt);
        if ($plain === null) {
            return null;
        }

        // Smooth Migration: wenn Master-Key existiert, beim ersten Lesen
        // auf V2 umstellen. Best-effort - bei Fehler ungemuetlich, aber
        // nicht den Lesen-Pfad blockieren.
        $masterKey = $this->readMasterKey();
        if ($masterKey !== null) {
            try {
                $newCipher = $this->encryptV2($plain, $masterKey);
                $this->config->setValue(self::NAMESPACE, self::KEY_API, $newCipher);
                $this->config->deleteKey(self::NAMESPACE, self::KEY_SALT);
            } catch (\Throwable $ignored) {
                // Migration nicht hart machen.
            }
        }

        return $plain;
    }

    public function hasApiKey(): bool
    {
        return $this->config->isKeyExisting(self::NAMESPACE, self::KEY_API);
    }

    public function deleteApiKey(): void
    {
        try {
            $this->config->deleteKey(self::NAMESPACE, self::KEY_API);
            if ($this->config->isKeyExisting(self::NAMESPACE, self::KEY_SALT)) {
                $this->config->deleteKey(self::NAMESPACE, self::KEY_SALT);
            }
        } catch (\Throwable $exception) {
            throw new LexwareOfficeException('API-Schlüssel konnte nicht gelöscht werden.', 0, $exception);
        }
    }

    private function encryptV2(string $value, string $masterKeyHex): string
    {
        $cipher = 'AES-256-CBC';
        $masterKeyBin = $this->hexToBin($masterKeyHex);
        $key = hash('sha256', $masterKeyBin, true);
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivLength);

        $ciphertext = openssl_encrypt($value, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new LexwareOfficeException('API-Schlüssel konnte nicht verschlüsselt werden.');
        }

        $hmac = hash_hmac('sha256', $ciphertext, $key, true);

        return self::SCHEMA_V2_PREFIX . base64_encode($iv . $hmac . $ciphertext);
    }

    private function decryptV2(string $encoded, string $masterKeyHex): ?string
    {
        $cipher = 'AES-256-CBC';
        $payload = base64_decode($encoded, true);
        if ($payload === false) {
            return null;
        }

        $masterKeyBin = $this->hexToBin($masterKeyHex);
        $key = hash('sha256', $masterKeyBin, true);
        $ivLength = openssl_cipher_iv_length($cipher);

        if (strlen($payload) < $ivLength + 32) {
            return null;
        }
        $iv = substr($payload, 0, $ivLength);
        $hmac = substr($payload, $ivLength, 32);
        $ciphertext = substr($payload, $ivLength + 32);

        $calcHmac = hash_hmac('sha256', $ciphertext, $key, true);
        if (!hash_equals($hmac, $calcHmac)) {
            return null;
        }

        $plain = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);

        return $plain === false ? null : $plain;
    }

    private function decryptV1(string $encoded, string $salt): ?string
    {
        $cipher = 'AES-256-CBC';
        $payload = base64_decode($encoded, true);
        if ($payload === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length($cipher);
        $key = hash('sha256', $salt, true);

        if (strlen($payload) < $ivLength + 32) {
            return null;
        }
        $iv = substr($payload, 0, $ivLength);
        $hmac = substr($payload, $ivLength, 32);
        $ciphertext = substr($payload, $ivLength + 32);

        $calcHmac = hash_hmac('sha256', $ciphertext, $key, true);
        if (!hash_equals($hmac, $calcHmac)) {
            return null;
        }

        $plain = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);

        return $plain === false ? null : $plain;
    }

    /**
     * Liest den Master-Key aus der Datei. Returnt null, wenn die Datei
     * fehlt, nicht lesbar ist, oder einen offensichtlich invaliden Inhalt
     * hat (zu kurz, kein Hex). Wirft NICHT, damit der Lesen-Pfad nicht
     * mit Exceptions ueberzogen wird, wenn der Master-Key noch nicht
     * existiert.
     */
    private function readMasterKey(): ?string
    {
        if (!is_file($this->masterKeyPath) || !is_readable($this->masterKeyPath)) {
            return null;
        }
        $contents = @file_get_contents($this->masterKeyPath);
        if ($contents === false) {
            return null;
        }
        $contents = trim($contents);
        if (strlen($contents) < 32 || !ctype_xdigit($contents)) {
            return null;
        }
        return $contents;
    }

    /**
     * Liest den Master-Key oder erzeugt ihn lazy, falls die Datei fehlt.
     * Wird nur beim Save-Pfad aufgerufen, damit ein versehentlich
     * geloeschter Init-Setup-Schritt nicht zu unverstaendlichen Fehlern
     * fuehrt.
     */
    private function getOrCreateMasterKey(): string
    {
        $key = $this->readMasterKey();
        if ($key !== null) {
            return $key;
        }

        try {
            Bootstrap::ensureMasterKeyFile($this->masterKeyPath);
        } catch (\Throwable $exception) {
            throw new LexwareOfficeException(
                'Master-Key-Datei konnte nicht erzeugt werden: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $key = $this->readMasterKey();
        if ($key === null) {
            throw new LexwareOfficeException('Master-Key-Datei wurde erzeugt, ist aber nicht lesbar.');
        }
        return $key;
    }

    private function hexToBin(string $hex): string
    {
        $bin = @hex2bin($hex);
        if ($bin === false) {
            throw new LexwareOfficeException('Master-Key hat ein ungueltiges Format.');
        }
        return $bin;
    }
}
