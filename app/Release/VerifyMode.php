<?php

declare(strict_types=1);

namespace App\Release;

enum VerifyMode
{
    /** A release zip as built by CI: nothing but package files. */
    case Package;

    /**
     * An unpacked layout or a live installation: its own `.env` and the files
     * hosting panels add (cPanel's PHP handler block in .htaccess, error_log,
     * .user.ini, .well-known/) are expected.
     */
    case Installation;
}
