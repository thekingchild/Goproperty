<?php

declare(strict_types=1);

namespace App\Media;

use RuntimeException;

/** An upload the media library will not store; the message is for the person uploading. */
final class UploadRefused extends RuntimeException {}
