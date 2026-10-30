<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Mail\Templates\EmailTemplates;
use Illuminate\Database\Seeder;

/**
 * GoProperty's email templates (P05-04; PLATFORM_CONSOLE.md § 5): every
 * key in App\Mail\Templates\TemplateCatalog, with the code's wording as
 * version 1. Idempotent: a template already there keeps its wording (staff
 * edits are never undone) and takes the code's name, category and
 * placeholders.
 */
final class EmailTemplatesSeeder extends Seeder
{
    public function run(EmailTemplates $templates): void
    {
        $templates->seed();
    }
}
