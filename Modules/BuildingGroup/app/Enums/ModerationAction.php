<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What a moderator did (`group_moderation_events.action`, DATABASE_SCHEMA.md
 * § 6; P07-04). Hiding can be undone; removing cannot (the item stays for
 * moderators, with its trail).
 */
enum ModerationAction: string
{
    use HasValues;

    case Hide = 'hide';
    case Unhide = 'unhide';
    case Remove = 'remove';
    case Pin = 'pin';
    case Unpin = 'unpin';
    case MuteMember = 'mute_member';
    case UnmuteMember = 'unmute_member';
    case DismissReports = 'dismiss_reports';
    case ChangeSettings = 'change_settings';

    /** How the trail shows it ("Hidden by Seyi Admin"). */
    public function label(): string
    {
        return match ($this) {
            self::Hide => __('Hidden'),
            self::Unhide => __('Shown again'),
            self::Remove => __('Removed'),
            self::Pin => __('Pinned'),
            self::Unpin => __('Unpinned'),
            self::MuteMember => __('Author muted'),
            self::UnmuteMember => __('Author unmuted'),
            self::DismissReports => __('Reports dismissed'),
            self::ChangeSettings => __('Settings changed'),
        };
    }
}
