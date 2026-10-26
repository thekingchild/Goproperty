<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * An in-memory Google Drive v3 behind Http::fake(), enough for the backup
 * adapter: service-account token, list with q=, folders, resumable uploads,
 * download, delete. Records token requests to check the signed JWT.
 */
final class FakeGoogleDrive
{
    public const string DRIVE = 'shared-drive-1';

    /** @var array<string, array{id: string, name: string, mimeType: string, parents: list<string>, content: string, modifiedTime: string}> */
    public array $files = [];

    /** @var list<string> */
    public array $assertions = [];

    /** @var array<string, array{name: string, parents: list<string>, content: string}> */
    private array $sessions = [];

    public int $uploadRequests = 0;

    public ?int $failWith = null;

    public static function install(): self
    {
        $drive = new self;
        Http::fake(fn (Request $request) => $drive->handle($request));

        return $drive;
    }

    /** A service account key with a real RSA key pair. @return array{account: array<string, string>, public: string} */
    public static function serviceAccount(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];

        return [
            'account' => ['type' => 'service_account', 'client_email' => 'backups@goproperty-test.iam.gserviceaccount.com', 'private_key' => $private, 'token_uri' => 'https://oauth2.googleapis.com/token'],
            'public' => $public,
        ];
    }

    /** @return list<string> paths of files, "folder/name" */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->files as $file) {
            if ($file['mimeType'] !== 'application/vnd.google-apps.folder') {
                $paths[] = ltrim($this->pathOf($file['parents'][0] ?? '').'/'.$file['name'], '/');
            }
        }

        sort($paths);

        return $paths;
    }

    public function contentOf(string $path): ?string
    {
        foreach ($this->files as $file) {
            if (ltrim($this->pathOf($file['parents'][0] ?? '').'/'.$file['name'], '/') === $path) {
                return $file['content'];
            }
        }

        return null;
    }

    private function handle(Request $request): mixed
    {
        $url = $request->url();

        if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
            $this->assertions[] = (string) ($request->data()['assertion'] ?? '');

            return Http::response(['access_token' => 'token-'.count($this->assertions), 'expires_in' => 3600]);
        }

        if ($this->failWith !== null) {
            return Http::response(['error' => ['message' => 'The user does not have sufficient permissions for this file.']], $this->failWith);
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (str_starts_with($url, 'https://upload.fake/session/')) {
            return $this->uploadChunk(substr($path, strlen('/session/')), $request);
        }

        if ($path === '/upload/drive/v3/files' && $request->method() === 'POST') {
            $id = Str::random(10);
            $this->sessions[$id] = ['name' => (string) $request['name'], 'parents' => (array) $request['parents'], 'content' => ''];

            return Http::response('', 200, ['Location' => 'https://upload.fake/session/'.$id]);
        }

        if ($path === '/drive/v3/files' && $request->method() === 'GET') {
            return Http::response(['files' => array_values(array_map(fn (array $file): array => [
                'id' => $file['id'], 'name' => $file['name'], 'mimeType' => $file['mimeType'], 'size' => (string) strlen($file['content']), 'modifiedTime' => $file['modifiedTime'],
            ], $this->matching((string) ($query['q'] ?? ''))))]);
        }

        if ($path === '/drive/v3/files' && $request->method() === 'POST') {
            $id = Str::random(12);
            $this->files[$id] = ['id' => $id, 'name' => (string) $request['name'], 'mimeType' => (string) $request['mimeType'], 'parents' => (array) $request['parents'], 'content' => '', 'modifiedTime' => now()->toIso8601ZuluString('millisecond')];

            return Http::response(['id' => $id]);
        }

        if (preg_match('#^/drive/v3/files/([^/]+)$#', $path, $match) === 1) {
            $id = rawurldecode($match[1]);

            if (! isset($this->files[$id])) {
                return Http::response(['error' => ['message' => 'File not found']], 404);
            }

            if ($request->method() === 'DELETE') {
                unset($this->files[$id]);

                return Http::response('', 204);
            }

            return Http::response($this->files[$id]['content']);
        }

        return Http::response(['error' => ['message' => 'Unexpected request '.$request->method().' '.$url]], 400);
    }

    private function uploadChunk(string $session, Request $request): mixed
    {
        $this->uploadRequests++;
        $this->sessions[$session]['content'] .= $request->body();
        $range = $request->header('Content-Range')[0] ?? '';

        if (str_ends_with($range, '/*')) {
            return Http::response('', 308);
        }

        $id = Str::random(12);
        $this->files[$id] = ['id' => $id, 'name' => $this->sessions[$session]['name'], 'mimeType' => 'application/octet-stream', 'parents' => $this->sessions[$session]['parents'], 'content' => $this->sessions[$session]['content'], 'modifiedTime' => now()->toIso8601ZuluString('millisecond')];

        return Http::response(['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    private function matching(string $q): array
    {
        preg_match("/'([^']+)' in parents/", $q, $parent);
        preg_match("/name = '((?:[^'\\\\]|\\\\.)*)'/", $q, $name);
        preg_match("/mimeType = '([^']+)'/", $q, $mime);

        return array_values(array_filter($this->files, fn (array $file): bool => in_array($parent[1] ?? '', $file['parents'], true)
            && (! isset($name[1]) || $file['name'] === stripslashes($name[1]))
            && (! isset($mime[1]) || $file['mimeType'] === $mime[1])));
    }

    private function pathOf(string $folderId): string
    {
        if (! isset($this->files[$folderId])) {
            return '';
        }

        return ltrim($this->pathOf($this->files[$folderId]['parents'][0] ?? '').'/'.$this->files[$folderId]['name'], '/');
    }
}
