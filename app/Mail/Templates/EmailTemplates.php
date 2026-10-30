<?php

declare(strict_types=1);

namespace App\Mail\Templates;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateVersion;
use App\Models\PlatformStaff;
use Illuminate\Support\Facades\DB;

/**
 * GoProperty's email templates (P05-04; PLATFORM_CONSOLE.md § 5): the only
 * writer of `email_templates` and `email_template_versions`, and what code
 * calls to fill a template in.
 *
 * - Every save adds a version and makes it current; a rollback adds a copy
 *   of the version it goes back to, so history is never rewritten. Both
 *   are audited (log `email`) with the staff member as causer.
 * - A key in the catalog that is not seeded yet, or whose template was
 *   switched off, is sent with the catalog's wording, so a message is
 *   never lost for want of a row.
 * - The whitelist is the catalog's (the code's): a placeholder the code
 *   does not fill can never be added from the console.
 */
final readonly class EmailTemplates
{
    public const string LOG = 'email';

    public const int SUBJECT_MAX = 200;

    public const int BODY_MAX = 20000;

    public const int LABEL_MAX = 60;

    public function __construct(
        private TemplateCatalog $catalog,
        private TemplateRenderer $renderer,
    ) {}

    /**
     * The template filled in with the sender's plain values.
     *
     * @param  array<string, string|int|null>  $values
     */
    public function render(string $key, array $values): RenderedEmail
    {
        return $this->renderer->render($this->content($key), $this->catalog->get($key)->placeholderNames(), $values);
    }

    /** The wording sent now for the key. */
    public function content(string $key): TemplateContent
    {
        $template = EmailTemplate::query()->where('key', $key)->with('currentVersion')->first();

        if ($template !== null && $template->active && $template->currentVersion !== null) {
            return $template->currentVersion->content();
        }

        return $this->catalog->get($key)->content;
    }

    /** Fills a version in with the catalog's sample values, for the preview and the test email. */
    public function sample(EmailTemplate $template, TemplateContent $content): RenderedEmail
    {
        $definition = $this->catalog->find($template->key);

        return $this->renderer->render($content, $definition?->placeholderNames() ?? [], $definition?->samples() ?? []);
    }

    /**
     * Saves new wording as the next version. Unchanged wording adds nothing.
     *
     * @throws TemplateRefused
     */
    public function save(EmailTemplate $template, TemplateContent $content, PlatformStaff $staff): EmailTemplateVersion
    {
        $content = self::checked($content);

        return DB::transaction(function () use ($template, $content, $staff): EmailTemplateVersion {
            $locked = $this->locked($template);
            $current = $locked->currentVersion;

            if ($current !== null && $current->content()->equals($content)) {
                return $current;
            }

            $version = $this->append($locked, $content, $staff, null);

            activity(self::LOG)
                ->event('email_template.saved')
                ->performedOn($locked)
                ->causedBy($staff)
                ->withProperties(['key' => $locked->key, 'version' => $version->version, 'previous_version' => $current?->version])
                ->log("Email template {$locked->key} saved as version {$version->version}");

            return $version;
        }, 3);
    }

    /**
     * Makes an earlier version current again, as a new version copying it.
     *
     * @throws TemplateRefused
     */
    public function rollback(EmailTemplate $template, int $version, PlatformStaff $staff): EmailTemplateVersion
    {
        return DB::transaction(function () use ($template, $version, $staff): EmailTemplateVersion {
            $locked = $this->locked($template);
            $current = $locked->currentVersion;
            $target = $locked->versions()->where('version', $version)->first();

            if (! $target instanceof EmailTemplateVersion) {
                throw new TemplateRefused("Version {$version} of this template does not exist.");
            }

            if ($current !== null && $current->id === $target->id) {
                throw new TemplateRefused("Version {$version} is already the one being sent.");
            }

            $copy = $this->append($locked, $target->content(), $staff, $target->version);

            activity(self::LOG)
                ->event('email_template.rolled_back')
                ->performedOn($locked)
                ->causedBy($staff)
                ->withProperties(['key' => $locked->key, 'version' => $copy->version, 'restored_from_version' => $target->version, 'previous_version' => $current?->version])
                ->log("Email template {$locked->key} rolled back to version {$target->version} (now version {$copy->version})");

            return $copy;
        }, 3);
    }

    /** Switches a template on or off (off: the code's own wording is sent). */
    public function setActive(EmailTemplate $template, bool $active, PlatformStaff $staff): void
    {
        DB::transaction(function () use ($template, $active, $staff): void {
            $locked = $this->locked($template);

            if ($locked->active === $active) {
                return;
            }

            $locked->forceFill(['active' => $active])->save();

            activity(self::LOG)
                ->event($active ? 'email_template.activated' : 'email_template.deactivated')
                ->performedOn($locked)
                ->causedBy($staff)
                ->withProperties(['key' => $locked->key])
                ->log("Email template {$locked->key} switched ".($active ? 'on' : 'off'));
        }, 3);
    }

    /**
     * Writes the catalog into `email_templates` (EmailTemplatesSeeder):
     * a new key gets version 1 with the code's wording; a known one keeps
     * its wording and takes the code's name, category and whitelist.
     * Safe to run again.
     */
    public function seed(): void
    {
        foreach ($this->catalog->all() as $definition) {
            DB::transaction(function () use ($definition): void {
                $template = EmailTemplate::query()->where('key', $definition->key)->lockForUpdate()->first()
                    ?? EmailTemplate::query()->forceCreate([
                        'key' => $definition->key,
                        'name' => $definition->name,
                        'description' => $definition->description,
                        'category' => $definition->category,
                        'allowed_placeholders' => $definition->placeholderNames(),
                        'active' => true,
                    ]);

                $template->forceFill([
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'category' => $definition->category,
                    'allowed_placeholders' => $definition->placeholderNames(),
                ]);

                if ($template->isDirty()) {
                    $template->save();
                }

                if ($template->current_version_id === null) {
                    $this->append($template, $definition->content, null, null);
                }
            }, 3);
        }
    }

    /** @throws TemplateRefused */
    private static function checked(TemplateContent $content): TemplateContent
    {
        $subject = trim($content->subject);
        $body = trim(str_replace("\r\n", "\n", $content->body));
        $label = $content->actionLabel !== null && trim($content->actionLabel) !== '' ? trim($content->actionLabel) : null;

        if ($subject === '' || $body === '') {
            throw new TemplateRefused('A template needs a subject and a body.');
        }

        if (mb_strlen($subject) > self::SUBJECT_MAX || mb_strlen($body) > self::BODY_MAX || ($label !== null && mb_strlen($label) > self::LABEL_MAX)) {
            throw new TemplateRefused('The subject may have up to '.self::SUBJECT_MAX.' characters, the body '.self::BODY_MAX.' and the button '.self::LABEL_MAX.'.');
        }

        return new TemplateContent($subject, $body, $label);
    }

    private function locked(EmailTemplate $template): EmailTemplate
    {
        return EmailTemplate::query()->whereKey($template->id)->lockForUpdate()->with('currentVersion')->firstOrFail();
    }

    private function append(EmailTemplate $template, TemplateContent $content, ?PlatformStaff $staff, ?int $restoredFrom): EmailTemplateVersion
    {
        $next = (int) $template->versions()->max('version') + 1;

        $version = EmailTemplateVersion::query()->forceCreate([
            'email_template_id' => $template->id,
            'version' => $next,
            'subject' => $content->subject,
            'body_markdown' => $content->body,
            'action_label' => $content->actionLabel,
            'restored_from_version' => $restoredFrom,
            'created_by_staff_id' => $staff?->id,
        ]);

        $template->forceFill(['current_version_id' => $version->id])->save();
        $template->setRelation('currentVersion', $version);

        return $version;
    }
}
