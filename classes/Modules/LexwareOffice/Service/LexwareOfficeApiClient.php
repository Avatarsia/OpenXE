<?php

declare(strict_types=1);

namespace Xentral\Modules\LexwareOffice\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Xentral\Modules\LexwareOffice\Exception\LexwareOfficeException;

final class LexwareOfficeApiClient
{
    private const BASE_URI = 'https://api.lexware.io/v1/';
    private const MAX_ERROR_DETAILS = 5;
    private const MAX_RETRIES = 3;
    private const RETRY_BASE_DELAY_MS = 1000;
    // B2: Obergrenze fuer server-seitig gesendetes Retry-After. Ohne Cap
    // koennte ein hostiler oder defekter Server (Retry-After: 86400) einen
    // Worker fuer MAX_RETRIES * 24h blockieren.
    private const MAX_RETRY_AFTER_SECONDS = 60;
    // Lexware-Limit fuer Voucher-File-Uploads: 5 MB pro Datei (PDF/JPG/PNG/XML).
    private const MAX_UPLOAD_FILE_BYTES = 5 * 1024 * 1024;

    public function __construct(private ?Client $client = null)
    {
        if ($this->client === null) {
            $handlerStack = HandlerStack::create();
            $handlerStack->push(Middleware::retry(
                $this->retryDecider(),
                $this->retryDelay()
            ));
            $this->client = new Client([
                'base_uri' => self::BASE_URI,
                'timeout' => 20,
                'handler' => $handlerStack,
            ]);
        }
    }

    /**
     * @return callable
     */
    private function retryDecider(): callable
    {
        return function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Throwable $exception = null
        ): bool {
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }
            if ($response !== null && $response->getStatusCode() === 429) {
                return true;
            }

            return false;
        };
    }

    /**
     * @return callable
     */
    private function retryDelay(): callable
    {
        return function (int $retries, ?ResponseInterface $response = null): int {
            if ($response !== null && $response->hasHeader('Retry-After')) {
                $after = $response->getHeaderLine('Retry-After');
                if (is_numeric($after)) {
                    $seconds = max(1, min(self::MAX_RETRY_AFTER_SECONDS, (int)$after));
                    return $seconds * 1000;
                }
            }

            return self::RETRY_BASE_DELAY_MS * (2 ** max(0, $retries - 1));
        };
    }

    /**
     * @param string $apiKey
     * @param array  $query
     *
     * @return array
     */
    public function searchContacts(string $apiKey, array $query): array
    {
        return $this->request('GET', 'contacts', $apiKey, ['query' => $query]);
    }

    /**
     * @param string $apiKey
     * @param array  $payload
     *
     * @return array
     */
    public function createContact(string $apiKey, array $payload): array
    {
        return $this->request('POST', 'contacts', $apiKey, ['json' => $payload]);
    }

    /**
     * Legt einen Voucher (Sales-Invoice oder Sales-Credit-Note) in Lexware
     * Office an.
     *
     * POST /v1/vouchers liefert eine echte Voucher-ID zurueck, die anschliessend
     * fuer uploadFileToVoucher() (/v1/vouchers/{id}/files) verwendbar ist. Die
     * frueher genutzten /v1/invoices bzw. /v1/credit-notes liefern dagegen
     * Invoice-/CreditNote-IDs, die am File-Endpoint mit HTTP 404 scheitern.
     *
     * @param string      $apiKey
     * @param array       $payload         Voucher-Body (siehe LexwareOfficePayloadMapper::mapVoucherPayload).
     * @param string|null $idempotencyKey  Optional deterministischer Key; wenn gesetzt
     *                                     wird er als Idempotency-Key Header mitgeschickt.
     *                                     Ist in der public Lexware-API-Doku nicht
     *                                     explizit dokumentiert, folgt aber der verbreiteten
     *                                     Konvention und ist andernfalls ein No-Op.
     *
     * @return array
     */
    public function createVoucher(string $apiKey, array $payload, ?string $idempotencyKey = null): array
    {
        $options = ['json' => $payload];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $options['headers'] = ['Idempotency-Key' => $idempotencyKey];
        }

        return $this->request('POST', 'vouchers', $apiKey, $options);
    }

    /**
     * Laedt eine Datei (PDF/JPG/PNG/XML, max. 5 MB) zu einem bestehenden Voucher hoch.
     *
     * Erwartet eine echte Voucher-ID, wie sie createVoucher() (POST /v1/vouchers)
     * zurueckliefert. Achtung: Invoice-IDs aus /v1/invoices oder CreditNote-IDs
     * aus /v1/credit-notes sind hier NICHT verwendbar — der /vouchers/{id}/files-
     * Endpoint quittiert sie mit HTTP 404 (das war der urspruengliche Bug).
     *
     * Multipart-Feldname ist 'file' (Lexware-Vorgabe). Die bestehende
     * Retry-/429-Mechanik aus dem Konstruktor greift auch hier.
     *
     * @param string $apiKey
     * @param string $voucherId
     * @param string $filePath  Absoluter Pfad zur lokalen Datei.
     *
     * @return array Geparste Lexware-Response (mind. 'id').
     */
    public function uploadFileToVoucher(string $apiKey, string $voucherId, string $filePath): array
    {
        if ($voucherId === '') {
            throw new LexwareOfficeException('Voucher-ID fuer File-Upload ist leer.');
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new LexwareOfficeException(
                sprintf('PDF-Datei fuer Lexware-Upload nicht gefunden oder nicht lesbar: %s', $filePath)
            );
        }
        $size = @filesize($filePath);
        if ($size === false) {
            throw new LexwareOfficeException(
                sprintf('Dateigroesse von "%s" konnte nicht ermittelt werden.', $filePath)
            );
        }
        if ($size <= 0) {
            throw new LexwareOfficeException(
                sprintf('Datei "%s" ist leer und kann nicht hochgeladen werden.', $filePath)
            );
        }
        if ($size > self::MAX_UPLOAD_FILE_BYTES) {
            throw new LexwareOfficeException(sprintf(
                'Datei ist zu gross fuer Lexware Office (max. 5 MB, vorhanden: %.2f MB).',
                $size / (1024 * 1024)
            ));
        }

        $stream = @fopen($filePath, 'rb');
        if ($stream === false) {
            throw new LexwareOfficeException(
                sprintf('PDF-Datei "%s" konnte nicht geoeffnet werden.', $filePath)
            );
        }

        try {
            return $this->request(
                'POST',
                sprintf('vouchers/%s/files', rawurlencode($voucherId)),
                $apiKey,
                [
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => $stream,
                            'filename' => basename($filePath),
                        ],
                    ],
                ]
            );
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
    }

    /**
     * @param string $method
     * @param string $path
     * @param string $apiKey
     * @param array  $options
     *
     * @return array
     */
    private function request(string $method, string $path, string $apiKey, array $options = []): array
    {
        $headers = [
            'Authorization' => sprintf('Bearer %s', $apiKey),
            'Accept' => 'application/json',
        ];

        $options['headers'] = array_merge($headers, $options['headers'] ?? []);

        try {
            $response = $this->client->request($method, ltrim($path, '/'), $options);
        } catch (GuzzleException $exception) {
            $statusCode = (int)$exception->getCode();
            $errorText = '';
            if ($exception instanceof RequestException && $exception->hasResponse()) {
                $statusCode = $exception->getResponse()->getStatusCode();
                $body = (string)$exception->getResponse()->getBody();
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $errorText = $decoded['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? '';
                    $detailText = $this->formatErrorDetails($decoded);
                    if ($detailText !== '') {
                        $errorText = $errorText !== '' ? ($errorText.' | '.$detailText) : $detailText;
                    }
                }
                if ($errorText === '' && $body !== '') {
                    // B4: Keine strukturierten Details parsebar -> generische Meldung.
                    // Raw Body NICHT an die UI leaken (koennte Stack-Traces oder
                    // interne Details enthalten). Strukturierte Errors werden
                    // weiterhin via formatErrorDetails() sichtbar gemacht.
                    $errorText = sprintf('Unerwartete Antwort von Lexware Office (HTTP %d)', $statusCode);
                }
            }
            if ($errorText === '') {
                $errorText = $exception->getMessage();
            }
            // D4: Nach MAX_RETRIES liefert Guzzle die urspruengliche 429-Response durch.
            // Sprechende Meldung statt generischem "HTTP 429"-Text, damit der User
            // weiss, dass es sich um ein transientes Rate-Limit handelt.
            if ($statusCode === 429) {
                throw new LexwareOfficeException(
                    'Lexware Office ist gerade ausgelastet (Rate-Limit erreicht). Bitte in 1–2 Minuten erneut versuchen.',
                    $statusCode,
                    $exception
                );
            }
            throw new LexwareOfficeException(
                sprintf(
                    'Lexware Office API Fehler%s: %s',
                    $statusCode > 0 ? sprintf(' (HTTP %d)', $statusCode) : '',
                    $errorText
                ),
                $statusCode,
                $exception
            );
        }

        $content = (string)$response->getBody();
        $decoded = json_decode($content, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new LexwareOfficeException('Die Antwort von Lexware Office konnte nicht gelesen werden.');
        }

        return $decoded;
    }

    private function formatErrorDetails(array $decoded): string
    {
        $parts = [];
        $details = [];
        if (isset($decoded['details']) && is_array($decoded['details'])) {
            $details = $decoded['details'];
        } elseif (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $details = $decoded['errors'];
        }

        $count = 0;
        foreach ($details as $detail) {
            if ($count >= self::MAX_ERROR_DETAILS) {
                $parts[] = sprintf('... (%d weitere)', count($details) - self::MAX_ERROR_DETAILS);
                break;
            }
            if (is_string($detail)) {
                $parts[] = $detail;
            } elseif (is_array($detail)) {
                $msg = $detail['message'] ?? $detail['detail'] ?? '';
                $field = $detail['field'] ?? $detail['path'] ?? '';
                if ($field !== '' && $msg !== '') {
                    $parts[] = sprintf('%s: %s', $field, $msg);
                } elseif ($msg !== '') {
                    $parts[] = $msg;
                } elseif ($field !== '') {
                    $parts[] = $field;
                }
            }
            $count++;
        }

        return implode('; ', array_filter($parts, static fn($p) => $p !== ''));
    }
}
