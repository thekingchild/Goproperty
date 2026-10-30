<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Meta;

/** The call broke off before Meta answered (timeout, connection reset): whether Meta took a message is unknown. */
final class MetaUnreachable extends MetaUnavailable {}
