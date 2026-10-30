<?php

declare(strict_types=1);

namespace App\Sms\Credits;

use RuntimeException;

/** An SMS credit change that is not allowed (an adjustment below zero, no reason). The message is for staff. */
final class SmsCreditRefused extends RuntimeException {}
