<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The curated emoji set for reactions (ARCHITECTURE.md § 6.8 "fixed
 * curated emoji set"; `group_reactions.emoji` keeps the key, never the
 * character, so nothing a person sends is stored or shown).
 */
enum Reaction: string
{
    use HasValues;

    case ThumbsUp = 'thumbs_up';
    case Heart = 'heart';
    case Laugh = 'laugh';
    case Wow = 'wow';
    case Sad = 'sad';
    case Thanks = 'thanks';

    public function symbol(): string
    {
        return match ($this) {
            self::ThumbsUp => '👍',
            self::Heart => '❤️',
            self::Laugh => '😂',
            self::Wow => '😮',
            self::Sad => '😢',
            self::Thanks => '🙏',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ThumbsUp => 'Like',
            self::Heart => 'Love',
            self::Laugh => 'Funny',
            self::Wow => 'Wow',
            self::Sad => 'Sad',
            self::Thanks => 'Thanks',
        };
    }
}
