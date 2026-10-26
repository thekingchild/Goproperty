<?php

declare(strict_types=1);

namespace App\Installer\Web;

/** The web installer's steps, in order (INSTALLER.md § 5). */
enum WizardStep: string
{
    case Unlock = 'unlock';
    case Server = 'server';
    case Site = 'site';
    case Database = 'database';
    case Services = 'services';
    case Admin = 'admin';
    case Finish = 'finish';

    public function label(): string
    {
        return match ($this) {
            self::Unlock => 'Unlock',
            self::Server => 'Server check',
            self::Site => 'Site & database',
            self::Database => 'Build the database',
            self::Services => 'Email & file storage',
            self::Admin => 'First super-admin',
            self::Finish => 'Finish',
        };
    }

    public function number(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /** Path under the site root. */
    public function path(): string
    {
        return $this === self::Unlock ? 'install' : 'install/'.$this->value;
    }
}
