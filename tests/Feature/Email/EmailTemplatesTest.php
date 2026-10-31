<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Mail\Templates\BrandedMail;
use App\Mail\Templates\EmailTemplates;
use App\Mail\Templates\Placeholder;
use App\Mail\Templates\TemplateCatalog;
use App\Mail\Templates\TemplateCategory;
use App\Mail\Templates\TemplateContent;
use App\Mail\Templates\TemplateDefinition;
use App\Mail\Templates\TemplatePreview;
use App\Mail\Templates\TemplateRefused;
use App\Mail\Templates\TemplateTestEmail;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateVersion;
use App\Platform\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Platform\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Platform\Filament\Resources\EmailTemplates\RelationManagers\VersionsRelationManager;
use Database\Seeders\EmailTemplatesSeeder;
use Filament\Facades\Filament;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * P05-04: the email template system (PLATFORM_CONSOLE.md § 5; AGENT.md § 3
 * rule 10). Acceptance: "Blade/PHP in a template renders as plain text;
 * rollback restores the previous version" (suppression: EmailSuppressionTest).
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $this->console = '/'.config('app.admin_path');
    $this->chika = staffNamed('Chika', ['content-manager']);

    app(TemplateCatalog::class)->add(new TemplateDefinition(
        key: 'test.notice',
        name: 'Test notice',
        description: 'A template only these tests send.',
        category: TemplateCategory::Transactional,
        placeholders: [
            new Placeholder('recipient.name', 'Who it is for', 'Femi Okon'),
            new Placeholder('bill.owed', 'Amount owed', '₦1,500,000'),
            new Placeholder('pay_link', 'Where to pay', 'https://goproperty.test/pay/GP-1'),
        ],
        content: new TemplateContent('You owe {{ bill.owed }}', "Hello {{ recipient.name }},\n\nYou owe **{{ bill.owed }}**.", 'Pay now'),
    ));

    $this->seed(EmailTemplatesSeeder::class);
    $this->templates = app(EmailTemplates::class);
    $this->template = EmailTemplate::query()->where('key', 'test.notice')->sole();
});

function words(string $subject, string $body, ?string $label = null): TemplateContent
{
    return new TemplateContent($subject, $body, $label);
}

describe('acceptance: Blade/PHP in a template renders as plain text', function () {
    it('fills in only whitelisted placeholders and shows Blade, PHP, HTML and unknown placeholders exactly as typed, escaped', function () {
        $body = implode("\n\n", [
            'Hello {{ recipient.name }}, you owe {{ bill.owed }}.',
            'Blade: {{ $user->password }} and {{ 7 * 7 }} and {!! phpinfo() !!}',
            "@php echo 'ran'; @endphp @if(true) yes @endif",
            "<?php echo 'ran'; ?> <?= 'ran' ?>",
            '<script>alert(1)</script><img src=x onerror=alert(1)>',
            'Unknown: {{ tenant.password }} {{ app.key }} {{recipient.name}}',
            '[Click](javascript:alert(1)) [Pay]({{ pay_link }})',
        ]);
        $this->templates->save($this->template, words('{{ recipient.name }}: {{ $secret }} @php', $body), $this->chika);

        $email = $this->templates->render('test.notice', [
            'recipient.name' => 'Femi',
            'bill.owed' => '₦1,500,000',
            'pay_link' => 'https://goproperty.test/pay/GP-1?a=1&b=2',
            'tenant.password' => 'leaked',
        ]);

        expect($email->subject)->toBe('Femi: {{ $secret }} @php')
            ->and($email->html)->toContain('Hello Femi, you owe ₦1,500,000.')
            ->and($email->html)->toContain('{{ $user-&gt;password }}')
            ->and($email->html)->toContain('{{ 7 * 7 }}')
            ->and($email->html)->not->toContain('49')
            ->and($email->html)->toContain('{!! phpinfo() !!}')
            ->and($email->html)->toContain("@php echo 'ran'; @endphp @if(true) yes @endif")
            ->and($email->html)->toContain('&lt;?php echo')
            ->and($email->html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt;')
            ->and($email->html)->not->toContain('<script')
            ->and($email->html)->not->toContain('<img')
            ->and($email->html)->not->toContain('javascript:')
            ->and($email->html)->toContain('{{ tenant.password }} {{ app.key }} Femi')
            ->and($email->html)->not->toContain('leaked')
            ->and($email->html)->toContain('<a href="https://goproperty.test/pay/GP-1?a=1&amp;b=2">Pay</a>')
            ->and($email->text)->toContain('Blade: {{ $user->password }} and {{ 7 * 7 }} and {!! phpinfo() !!}')
            ->and($email->text)->toContain('Pay (https://goproperty.test/pay/GP-1?a=1&b=2)');
    });

    it('never lets a value become Markdown or HTML', function () {
        $email = $this->templates->render('test.notice', [
            'recipient.name' => '<b>Femi</b> **bold** [x](javascript:alert(1)) {{ bill.owed }}',
            'bill.owed' => '_₦5_',
        ]);

        expect($email->html)->toContain('Hello &lt;b&gt;Femi&lt;/b&gt; **bold** [x](javascript:alert(1)) {{ bill.owed }},')
            ->and($email->html)->toContain('You owe <strong>_₦5_</strong>.')
            ->and($email->html)->not->toContain('<b>')
            ->and($email->html)->not->toContain('<em>');
    });

    it('sends a tenant reminder in the trusted layout with the code typed into the template as text', function () {
        $template = EmailTemplate::query()->where('key', 'reminder.rent_due')->sole();
        $this->templates->save($template, words('Rent {{ bill.owed }}', "Hi {{ recipient.name }} {{ \$tenant->user->password }} <?php system('id'); ?>"), $this->chika);

        $html = app(BrandedMail::class)->html(
            $this->templates->render('reminder.rent_due', ['recipient.name' => 'Kemi', 'bill.owed' => '₦10']),
            app(TemplatePreview::class)->brand($template),
        );

        expect($html)->toContain("Hi Kemi {{ \$tenant-&gt;user-&gt;password }} &lt;?php system('id'); ?&gt;")
            ->and($html)->toContain('data-test="branded-email"');
    });

    it('warns in the editor that typed code and unknown placeholders are shown as typed', function () {
        $this->actingAs($this->chika, 'staff');

        Livewire::test(EditEmailTemplate::class, ['record' => $this->template->getRouteKey()])
            ->fillForm(['subject' => 'Owed: {{ bill.owed }}', 'body' => 'Hi {{ recipient.name }} {{ tenant.password }} @php echo 1; @endphp'])
            ->assertSee('Owed: ₦1,500,000')
            ->assertSee('data-test="unknown-placeholders"', false)
            ->assertSee('{{ tenant.password }}')
            ->assertSee('data-test="code-warning"', false)
            ->assertDontSee('data-test="unknown-placeholders"><code>{{ recipient.name', false);
    });
});

describe('acceptance: rollback restores the previous version', function () {
    it('rolls back to the version before, as a new version, keeping every version as it was', function () {
        $first = $this->template->currentVersion;
        $this->templates->save($this->template, words('Second: {{ bill.owed }}', 'Second body', 'Pay'), $this->chika);

        expect($this->templates->render('test.notice', ['bill.owed' => '₦9'])->subject)->toBe('Second: ₦9');

        $copy = $this->templates->rollback($this->template->refresh(), 1, $this->chika);

        $rendered = $this->templates->render('test.notice', ['recipient.name' => 'Femi', 'bill.owed' => '₦9']);
        expect($rendered->subject)->toBe('You owe ₦9')
            ->and($rendered->html)->toContain('<strong>₦9</strong>')
            ->and($rendered->actionLabel)->toBe('Pay now')
            ->and($copy->version)->toBe(3)
            ->and($copy->restored_from_version)->toBe(1)
            ->and($copy->created_by_staff_id)->toBe($this->chika->id)
            ->and($this->template->refresh()->current_version_id)->toBe($copy->id)
            ->and(EmailTemplateVersion::query()->where('email_template_id', $this->template->id)->orderBy('version')->pluck('subject', 'version')->all())
            ->toBe([1 => 'You owe {{ bill.owed }}', 2 => 'Second: {{ bill.owed }}', 3 => 'You owe {{ bill.owed }}'])
            ->and($first->refresh()->subject)->toBe('You owe {{ bill.owed }}');

        $entry = AuditEntry::query()->where('event', 'email_template.rolled_back')->sole();
        expect($entry->causer_id)->toBe($this->chika->id)
            ->and($entry->properties['restored_from_version'])->toBe(1)
            ->and($entry->properties['previous_version'])->toBe(2);
    });

    it('rolls back from the versions table in the console', function () {
        $this->actingAs($this->chika, 'staff');
        $this->templates->save($this->template, words('Second', 'Second body'), $this->chika);
        $v1 = $this->template->versions()->where('version', 1)->sole();
        $v2 = $this->template->versions()->where('version', 2)->sole();

        Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $this->template->refresh(), 'pageClass' => EditEmailTemplate::class])
            ->assertCanSeeTableRecords([$v1, $v2])
            ->assertTableActionHidden('rollback', $v2)
            ->callTableAction('rollback', $v1);

        expect($this->template->refresh()->currentVersion->version)->toBe(3)
            ->and($this->template->currentVersion->subject)->toBe('You owe {{ bill.owed }}')
            ->and($this->templates->content('test.notice')->body)->toBe("Hello {{ recipient.name }},\n\nYou owe **{{ bill.owed }}**.");
    });

    it('refuses a version that does not exist or is already being sent, changing nothing', function () {
        expect(fn () => $this->templates->rollback($this->template, 9, $this->chika))->toThrow(TemplateRefused::class)
            ->and(fn () => $this->templates->rollback($this->template, 1, $this->chika))->toThrow(TemplateRefused::class)
            ->and($this->template->versions()->count())->toBe(1);
    });
});

describe('versions', function () {
    it('adds a version for every change, audited, and none for unchanged wording', function () {
        $this->templates->save($this->template, words('A', 'Body A'), $this->chika);
        $this->templates->save($this->template, words(' A ', "Body A\r\n"), $this->chika);
        $this->templates->save($this->template, words('B', 'Body A'), $this->chika);

        expect($this->template->versions()->pluck('version')->all())->toBe([1, 2, 3])
            ->and(AuditEntry::query()->where('event', 'email_template.saved')->count())->toBe(2)
            ->and(AuditEntry::query()->where('event', 'email_template.saved')->latest('id')->first()->causer_id)->toBe($this->chika->id)
            ->and(fn () => $this->template->currentVersion->forceFill(['subject' => 'x'])->save())->toThrow(LogicException::class)
            ->and(fn () => $this->template->currentVersion->delete())->toThrow(LogicException::class)
            ->and(fn () => $this->template->delete())->toThrow(LogicException::class);
    });

    it('refuses an empty subject or body and over-long text', function (string $subject, string $body) {
        expect(fn () => $this->templates->save($this->template, words($subject, $body), $this->chika))->toThrow(TemplateRefused::class)
            ->and($this->template->versions()->count())->toBe(1);
    })->with([
        'no subject' => [' ', 'Body'],
        'no body' => ['Subject', "\n"],
        'subject too long' => [str_repeat('s', 201), 'Body'],
        'body too long' => ['Subject', str_repeat('b', 20001)],
    ]);

    it('seeds every template in the catalog once, with the code\'s wording, and never undoes staff edits', function () {
        $this->templates->save($this->template, words('Edited', 'Edited body'), $this->chika);

        $this->seed(EmailTemplatesSeeder::class);
        $this->seed(EmailTemplatesSeeder::class);

        expect(EmailTemplate::query()->pluck('key')->sort()->values()->all())->toBe(collect(array_keys(app(TemplateCatalog::class)->all()))->sort()->values()->all())
            ->and(EmailTemplate::query()->where('key', 'reminder.rent_due')->sole()->category)->toBe(TemplateCategory::Reminder)
            ->and($this->template->refresh()->currentVersion->subject)->toBe('Edited')
            ->and($this->template->versions()->count())->toBe(2)
            ->and($this->template->allowed_placeholders)->toBe(['recipient.name', 'bill.owed', 'pay_link']);
    });

    it('sends the code\'s wording while a template is switched off or not seeded yet', function () {
        $this->templates->save($this->template, words('Edited', 'Edited body'), $this->chika);
        $this->templates->setActive($this->template, false, $this->chika);

        expect($this->templates->render('test.notice', ['bill.owed' => '₦1'])->subject)->toBe('You owe ₦1')
            ->and(AuditEntry::query()->where('event', 'email_template.deactivated')->count())->toBe(1);

        $this->templates->setActive($this->template, true, $this->chika);
        expect($this->templates->render('test.notice', [])->subject)->toBe('Edited');

        app(TemplateCatalog::class)->add(new TemplateDefinition('test.unseeded', 'Unseeded', '', TemplateCategory::Security, [], words('Code wording', 'Body')));
        expect($this->templates->render('test.unseeded', [])->subject)->toBe('Code wording');
    });
});

describe('the console', function () {
    it('opens the templates for content managers and super-admins only', function () {
        $this->actingAs($this->chika, 'staff');
        $this->get($this->console.'/email-templates')->assertOk()->assertSee('Rent due')->assertSee('Reminders');
        $this->get($this->console.'/email-templates/'.$this->template->id.'/edit')->assertOk()->assertSee('data-test="email-preview"', false);

        // A fresh session per staff member (the console checks the session's password hash).
        $this->flushSession();
        $this->actingAs(staffNamed('Ada', ['super-admin']), 'staff');
        $this->get($this->console.'/email-templates/'.$this->template->id.'/edit')->assertOk();

        foreach (['support-agent', 'analyst', 'technical-admin', 'finance-admin'] as $role) {
            $this->flushSession();
            $this->actingAs(staffNamed(ucfirst(str_replace('-', '', $role)), [$role]), 'staff');
            $this->get($this->console.'/email-templates')->assertForbidden();
            $this->get($this->console.'/email-templates/'.$this->template->id.'/edit')->assertForbidden();
        }

        auth('staff')->logout();
        $this->get($this->console.'/email-templates')->assertRedirect($this->console.'/login');
    });

    it('lists templates by category', function () {
        $this->actingAs($this->chika, 'staff');
        $reminder = EmailTemplate::query()->where('key', 'reminder.rent_due')->sole();

        Livewire::test(ListEmailTemplates::class)
            ->set('tableRecordsPerPage', 50)
            ->assertCanSeeTableRecords([$this->template, $reminder])
            ->filterTable('category', 'reminder')
            ->assertCanSeeTableRecords([$reminder])
            ->assertCanNotSeeTableRecords([$this->template]);
    });

    it('saves the form as a new version and shows the live preview of unsaved wording', function () {
        $this->actingAs($this->chika, 'staff');

        Livewire::test(EditEmailTemplate::class, ['record' => $this->template->getRouteKey()])
            ->assertSchemaStateSet(['subject' => 'You owe {{ bill.owed }}', 'action_label' => 'Pay now', 'active' => true])
            ->fillForm(['subject' => 'Balance {{ bill.owed }} for {{ recipient.name }}', 'body' => 'Please pay **today**.'])
            ->assertSee('Balance ₦1,500,000 for Femi Okon')
            ->assertSee('&lt;strong&gt;today&lt;/strong&gt;', false)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Saved as version 2');

        expect($this->template->refresh()->currentVersion->subject)->toBe('Balance {{ bill.owed }} for {{ recipient.name }}')
            ->and($this->template->currentVersion->created_by_staff_id)->toBe($this->chika->id);

        Livewire::test(EditEmailTemplate::class, ['record' => $this->template->getRouteKey()])
            ->fillForm(['subject' => '', 'body' => ''])
            ->call('save')
            ->assertHasFormErrors(['subject' => 'required', 'body' => 'required']);

        expect($this->template->versions()->count())->toBe(2);
    });

    it('sends a test of the wording on screen to the staff member only, with sample values, a few times at most', function () {
        Notification::fake();
        $this->actingAs($this->chika, 'staff');

        $page = Livewire::test(EditEmailTemplate::class, ['record' => $this->template->getRouteKey()])
            ->fillForm(['subject' => 'Unsaved {{ bill.owed }}'])
            ->callAction('sendTest')
            ->assertNotified('Test sent to chika@goproperty.ng');

        Notification::assertSentOnDemand(TemplateTestEmail::class, function (TemplateTestEmail $notification, array $channels, AnonymousNotifiable $notifiable): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'chika@goproperty.ng'
                && $mail->subject === '[Test] Unsaved ₦1,500,000'
                && $notification->brand->name === 'Adeyemi Realty';
        });
        expect($this->template->versions()->count())->toBe(1);

        foreach (range(2, EditEmailTemplate::TEST_SENDS) as $ignored) {
            $page->callAction('sendTest');
        }
        $page->callAction('sendTest')->assertNotified('Too many test emails. Try again in a few minutes.');

        Notification::assertSentOnDemandTimes(TemplateTestEmail::class, EditEmailTemplate::TEST_SENDS);
    });
});
