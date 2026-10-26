<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Support\LocalHost;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * S3-compatible file storage (INSTALLER.md § 5, step 5): AWS S3,
 * Cloudflare R2, DigitalOcean Spaces, Wasabi or any other service with the
 * S3 API. Written to `.env` as the `s3` disk's AWS_* settings; the secret
 * never appears in messages, logs or dumps.
 */
final readonly class StorageSettings
{
    public string $endpoint;

    public function __construct(
        string $endpoint,
        public string $region,
        public string $bucket,
        public string $accessKey,
        #[SensitiveParameter] private string $secret,
        public bool $pathStyle,
    ) {
        $endpoint = rtrim(trim($endpoint), '/');

        if ($endpoint !== '') {
            $parts = parse_url($endpoint);
            $host = is_array($parts) ? ($parts['host'] ?? '') : '';
            $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';

            if ($host === '' || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
                || ! ($scheme === 'https' || ($scheme === 'http' && LocalHost::is($host)))
                || preg_match('/[\x00-\x20\x7f]/', $endpoint) === 1) {
                throw new InvalidArgumentException('The endpoint must be an https:// address, such as https://<account>.r2.cloudflarestorage.com. Leave it empty for AWS S3.');
            }
        }

        if (preg_match('/^[a-z0-9][a-z0-9\-]{0,40}$/D', $region) !== 1) {
            throw new InvalidArgumentException('The region must be a region name, such as eu-west-1 (R2 uses "auto").');
        }

        if (preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/D', $bucket) !== 1) {
            throw new InvalidArgumentException('The bucket name may use lowercase letters, numbers, "." and "-" (3 to 63).');
        }

        if (preg_match('/^[A-Za-z0-9+\/=_\-]{4,128}$/D', $accessKey) !== 1) {
            throw new InvalidArgumentException('The access key ID looks wrong: copy it again from the storage provider.');
        }

        if ($secret === '' || preg_match('/[\x00-\x20\x7f]/', $secret) === 1) {
            throw new InvalidArgumentException('Enter the secret access key, without spaces.');
        }

        $this->endpoint = $endpoint;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * The `s3` disk as config/filesystems.php builds it, throwing on errors
     * so a test reports what went wrong.
     *
     * @return array<string, mixed>
     */
    public function diskConfig(): array
    {
        return [
            'driver' => 's3',
            'key' => $this->accessKey,
            'secret' => $this->secret,
            'region' => $this->region,
            'bucket' => $this->bucket,
            'endpoint' => $this->endpoint !== '' ? $this->endpoint : null,
            'use_path_style_endpoint' => $this->pathStyle,
            'throw' => true,
            'report' => false,
        ];
    }

    /** @return array<string, string|bool> */
    public function environment(): array
    {
        return [
            'AWS_ACCESS_KEY_ID' => $this->accessKey,
            'AWS_SECRET_ACCESS_KEY' => $this->secret,
            'AWS_DEFAULT_REGION' => $this->region,
            'AWS_BUCKET' => $this->bucket,
            'AWS_ENDPOINT' => $this->endpoint,
            'AWS_USE_PATH_STYLE_ENDPOINT' => $this->pathStyle,
        ];
    }

    /** Removes the keys from a provider's message. */
    public function scrub(string $message): string
    {
        return str_replace(array_filter([$this->secret, $this->accessKey]), '***', $message);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint, 'region' => $this->region, 'bucket' => $this->bucket, 'key' => $this->accessKey, 'secret' => '***'];
    }
}
