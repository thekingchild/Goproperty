<?php

declare(strict_types=1);

namespace App\Backup\GoogleDrive;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Just enough of Google Drive v3 for backups (PLATFORM_CONSOLE.md § 4): a
 * service account signs in with a JWT, and files live in a folder of a
 * Shared Drive (service accounts have no storage of their own). Built on
 * Laravel's HTTP client rather than google/apiclient, which does not
 * support this app's Guzzle version and is very large.
 */
final class DriveClient
{
    public const string API = 'https://www.googleapis.com/drive/v3';

    public const string UPLOAD = 'https://www.googleapis.com/upload/drive/v3';

    public const string FOLDER = 'application/vnd.google-apps.folder';

    /** 8 MiB: a multiple of 256 KiB, as resumable uploads require. */
    public const int CHUNK = 8 * 1024 * 1024;

    private ?string $token = null;

    private int $tokenExpires = 0;

    /**
     * @param  array<string, mixed>  $serviceAccount  the key file Google issues (JSON)
     */
    public function __construct(
        private readonly Http $http,
        private readonly array $serviceAccount,
        private readonly string $sharedDriveId,
        private readonly int $chunkSize = self::CHUNK,
    ) {
        foreach (['client_email', 'private_key'] as $field) {
            if (! is_string($serviceAccount[$field] ?? null) || $serviceAccount[$field] === '') {
                throw new RuntimeException("The Google service account key has no {$field}.");
            }
        }
    }

    /**
     * Files and folders directly inside $parentId.
     *
     * @return list<array{id: string, name: string, mimeType: string, size?: string, modifiedTime: string}>
     */
    public function children(string $parentId, ?string $name = null, ?string $mimeType = null): array
    {
        $query = sprintf("'%s' in parents and trashed = false", $this->escape($parentId));
        $query .= $name !== null ? sprintf(" and name = '%s'", $this->escape($name)) : '';
        $query .= $mimeType !== null ? sprintf(" and mimeType = '%s'", $this->escape($mimeType)) : '';

        $files = [];
        $pageToken = null;

        do {
            $response = $this->api()->get(self::API.'/files', array_filter([
                'q' => $query,
                'fields' => 'nextPageToken, files(id, name, mimeType, size, modifiedTime)',
                'pageSize' => 1000,
                'corpora' => 'drive',
                'driveId' => $this->sharedDriveId,
                'includeItemsFromAllDrives' => 'true',
                'supportsAllDrives' => 'true',
                'pageToken' => $pageToken,
            ]));
            $this->ensureOk($response, 'list files');

            foreach ((array) $response->json('files', []) as $file) {
                if (! is_array($file) || ! is_string($file['id'] ?? null) || ! is_string($file['name'] ?? null)) {
                    continue;
                }

                $entry = [
                    'id' => $file['id'],
                    'name' => $file['name'],
                    'mimeType' => is_string($file['mimeType'] ?? null) ? $file['mimeType'] : '',
                    'modifiedTime' => is_string($file['modifiedTime'] ?? null) ? $file['modifiedTime'] : '',
                ];

                if (is_scalar($file['size'] ?? null)) {
                    $entry['size'] = (string) $file['size'];
                }

                $files[] = $entry;
            }

            $pageToken = $response->json('nextPageToken');
        } while (is_string($pageToken) && $pageToken !== '');

        return $files;
    }

    public function createFolder(string $parentId, string $name): string
    {
        $response = $this->api()->post(self::API.'/files?supportsAllDrives=true&fields=id', [
            'name' => $name,
            'mimeType' => self::FOLDER,
            'parents' => [$parentId],
        ]);
        $this->ensureOk($response, 'create a folder');

        return (string) $response->json('id');
    }

    /**
     * Uploads a stream as a new file in chunks (resumable upload).
     *
     * @param  resource  $stream
     */
    public function upload(string $parentId, string $name, $stream): string
    {
        $start = $this->api()->withHeaders(['X-Upload-Content-Type' => 'application/octet-stream'])
            ->post(self::UPLOAD.'/files?uploadType=resumable&supportsAllDrives=true&fields=id', ['name' => $name, 'parents' => [$parentId]]);
        $this->ensureOk($start, 'start an upload');

        $session = $start->header('Location');

        if ($session === '') {
            throw new RuntimeException('Google Drive did not return an upload session.');
        }

        $offset = 0;
        $id = null;

        do {
            $chunk = (string) fread($stream, max(1, $this->chunkSize));
            $last = feof($stream);
            $length = strlen($chunk);
            $range = $length === 0
                ? 'bytes */'.$offset
                : sprintf('bytes %d-%d/%s', $offset, $offset + $length - 1, $last ? (string) ($offset + $length) : '*');

            $response = $this->api()->withHeaders(['Content-Range' => $range])->withBody($chunk, 'application/octet-stream')->put($session);
            $offset += $length;

            if ($response->status() === 308) {
                continue;
            }

            $this->ensureOk($response, 'upload a file');
            $id = (string) $response->json('id');
        } while (! $last && $id === null);

        if ($id === null || $id === '') {
            throw new RuntimeException('Google Drive did not confirm the upload.');
        }

        return $id;
    }

    /** @return resource */
    public function download(string $fileId)
    {
        $response = $this->api()->get(self::API.'/files/'.rawurlencode($fileId), ['alt' => 'media', 'supportsAllDrives' => 'true']);
        $this->ensureOk($response, 'download a file');

        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('No temporary stream.');
        }

        fwrite($stream, $response->body());
        rewind($stream);

        return $stream;
    }

    /** Deletes for good; trashes instead where the account may not delete. */
    public function delete(string $fileId): void
    {
        $response = $this->api()->delete(self::API.'/files/'.rawurlencode($fileId).'?supportsAllDrives=true');

        if ($response->status() === 403) {
            $response = $this->api()->patch(self::API.'/files/'.rawurlencode($fileId).'?supportsAllDrives=true', ['trashed' => true]);
        }

        if ($response->status() !== 404) {
            $this->ensureOk($response, 'delete a file');
        }
    }

    private function api(): PendingRequest
    {
        return $this->http->withToken($this->accessToken())->timeout(120)->retry(2, 500, throw: false);
    }

    /** OAuth 2.0 for service accounts: a signed JWT for an access token. */
    private function accessToken(): string
    {
        if ($this->token !== null && $this->tokenExpires > time() + 60) {
            return $this->token;
        }

        $tokenUri = is_string($this->serviceAccount['token_uri'] ?? null) ? $this->serviceAccount['token_uri'] : 'https://oauth2.googleapis.com/token';
        $now = time();
        $segments = [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode([
                'iss' => $this->serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/drive',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $key = openssl_pkey_get_private((string) $this->serviceAccount['private_key']);

        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The Google service account key cannot sign: is it the JSON key Google issued?');
        }

        $response = $this->http->asForm()->timeout(30)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => implode('.', $segments).'.'.$this->base64Url($signature),
        ]);
        $this->ensureOk($response, 'sign in');

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Google did not return an access token.');
        }

        $this->token = $token;
        $this->tokenExpires = $now + (int) ($response->json('expires_in') ?? 3600);

        return $token;
    }

    private function ensureOk(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message') ?? $response->json('error_description') ?? $response->json('error');

        throw new RuntimeException(sprintf('Google Drive could not %s (HTTP %d%s).', $what, $response->status(), is_string($message) ? ': '.$message : ''));
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
