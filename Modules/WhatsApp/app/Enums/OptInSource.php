<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Enums;

/**
 * How a tenant's WhatsApp consent was given or taken back
 * (`whatsapp_opt_ins.source`, `opt_out_source`): in the portal by the
 * tenant (`portal`), recorded by an agency member who asked the tenant in
 * person (`agent_recorded`), confirmed with an import (`import_confirmation`,
 * kept for imports; not written yet), or closed because the tenant opted
 * in again at another number (`replaced`, opt-out only).
 */
enum OptInSource: string
{
    case Portal = 'portal';
    case AgentRecorded = 'agent_recorded';
    case ImportConfirmation = 'import_confirmation';
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Portal => 'by the tenant in the portal',
            self::AgentRecorded => 'recorded by the agency in person',
            self::ImportConfirmation => 'confirmed with an import',
            self::Replaced => 'replaced by a newer number',
        };
    }
}
