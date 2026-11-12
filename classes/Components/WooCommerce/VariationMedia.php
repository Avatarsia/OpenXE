<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

/** Adapts the existing remote matrix file format to the connector's WordPress uploader. */
class VariationMedia
{
    public function upload(array $article, array $types, callable $upload): array
    {
        $variants = [];
        foreach ($article['matrix_varianten']['artikel'] ?? [] as $id => $rows) {
            $variants[$id] = $rows[0] ?? [];
        }
        foreach ($article['artikel_varianten'] ?? [] as $variant) {
            $id = $variant['artikel'];
            $variants[$id] = array_replace($variants[$id] ?? [], $variant);
        }
        $payloads = [];
        $temporary = [];
        try {
            foreach ($variants as $id => $variant) {
                $source = $variant['Dateien'] ?? [];
                $files = [];
                foreach ($source['datei'] ?? [] as $index => $encoded) {
                    $type = strtolower((string) ($source['stichwort'][$index] ?? ''));
                    // Remote source already gates Shopbild through shopbilderuebertragen.
                    if ($type !== 'shopbild' && !in_array($type, $types, true)) {
                        continue;
                    }
                    $bytes = base64_decode($encoded, true);
                    if ($bytes === false) {
                        throw new \UnexpectedValueException('Invalid base64 variation media');
                    }
                    $path = tempnam(sys_get_temp_dir(), 'openxe-wc-');
                    if ($path === false) {
                        throw new \RuntimeException('Cannot create variation media temporary file');
                    }
                    $temporary[] = $path;
                    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                        throw new \RuntimeException('Cannot write variation media temporary file');
                    }
                    $filename = basename(str_replace('\\', '/', (string) ($source['filename'][$index] ?? 'download.bin')));
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf'][$extension] ?? 'application/octet-stream';
                    $files[] = ['dateipfad' => $path, 'filename' => $filename, 'stichwort' => $type, 'mimetype' => $mime];
                }
                if ($files === []) {
                    continue;
                }
                $result = $upload($files);
                if (empty($result['success'])) {
                    throw new \RuntimeException('WooCommerce variation media upload failed');
                }
                $attachments = [];
                foreach ($result['files'] ?? [] as $file) {
                    if (empty($file['wordpressid'])) {
                        throw new \UnexpectedValueException('Variation upload returned no WordPress media ID');
                    }
                    if ($file['type'] === 'shopbild' && !isset($payloads[$id]['image'])) {
                        $payloads[$id]['image'] = ['id' => (int) $file['wordpressid']];
                    } elseif ($file['type'] !== 'shopbild') {
                        $attachments[] = ['name' => $file['name'], 'wp_media_id' => (int) $file['wordpressid'], 'typ' => $file['type']];
                    }
                }
                if ($attachments !== []) {
                    $payloads[$id]['meta_data'] = [['key' => 'openxe_product_attachments', 'value' => $attachments]];
                }
            }
        } finally {
            foreach ($temporary as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        return $payloads;
    }
}
