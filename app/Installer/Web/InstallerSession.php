<?php

declare(strict_types=1);

namespace App\Installer\Web;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;

/**
 * The wizard's own session (INSTALLER.md § 4): encrypted, in files (the
 * database does not exist yet, and `.env.example` asks for database
 * sessions), under its own cookie scoped to /install, Strict same-site,
 * Secure whenever the page is on https://.
 */
final class InstallerSession
{
    public function __construct(private readonly Config $config) {}

    public function configure(Request $request): void
    {
        $cookie = $this->config->get('installer.session_cookie', 'gp_install_session');
        $files = $this->config->get('installer.session_files');

        $this->config->set([
            'session.driver' => 'file',
            'session.files' => is_string($files) && $files !== '' ? $files : storage_path('framework/sessions'),
            'session.encrypt' => true,
            'session.cookie' => is_string($cookie) ? $cookie : 'gp_install_session',
            'session.lifetime' => max(60, (int) $this->config->get('installer.grant_minutes', 60)),
            'session.expire_on_close' => false,
            'session.path' => $request->getBaseUrl().'/install',
            'session.domain' => null,
            'session.secure' => $request->isSecure(),
            'session.http_only' => true,
            'session.same_site' => 'strict',
            'session.partitioned' => false,
        ]);
    }
}
