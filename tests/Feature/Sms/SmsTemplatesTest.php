<?php

declare(strict_types=1);

use App\Mail\Templates\EmailTemplates;
use App\Mail\Templates\Placeholder;
use App\Mail\Templates\TemplateCatalog;
use App\Mail\Templates\TemplateCategory;
use App\Mail\Templates\TemplateChannel;
use App\Mail\Templates\TemplateContent;
use App\Mail\Templates\TemplateDefinition;
use App\Mail\Templates\TemplateRefused;
use App\Models\EmailTemplate;
use App\Platform\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Sms\SmsText;
use Database\Seeders\EmailTemplatesSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * P05-02: SMS templates in the template engine (GoProperty's wording,
 * docs/plans/sms-provider-2026-10-29.md decision 3). Acceptance: "SMS
 * template stays under 160 chars" — with any values, since every value is
 * cut to its placeholder's longest length and a template is saved only if
 * it fits with all of them at their longest.
 */

beforeEach(function () {
    seedStaffRoles();
    $this->chika = staffNamed('Chika', ['content-manager']);
    $this->seed(EmailTemplatesSeeder::class);
    $this->templates = app(EmailTemplates::class);
    $this->sms = EmailTemplate::query()->where('key', 'reminder.rent_due.sms')->sole();
});

/** @return list<TemplateDefinition> */
function smsDefinitions(): array
{
    return array_values(array_filter(app(TemplateCatalog::class)->all(), fn (TemplateDefinition $d): bool => $d->channel === TemplateChannel::Sms));
}

describe('acceptance: an SMS template stays under 160 characters', function () {
    it('ships SMS templates that fit one page with every value at its longest, on a long site address too', function () {
        config(['app.url' => 'https://portal.goproperty-lagos.ng']);

        expect(smsDefinitions())->toHaveCount(6);   // 4 reminders (P05-02), suspension and its reminder (P06-05)

        foreach (smsDefinitions() as $definition) {
            expect($this->templates->longestSms($definition, $definition->content))->toBeLessThanOrEqual(SmsText::PAGE, $definition->key)
                ->and(EmailTemplate::query()->where('key', $definition->key)->sole()->channel)->toBe(TemplateChannel::Sms);
        }
    });

    it('sends at most 160 GSM characters whatever the values: long names cut, accents and the naira sign made plain', function () {
        $text = $this->templates->renderSms('reminder.rent_due.sms', [
            'agency.name' => 'Ọláyínká & Adébáyọ̀ Property Management and Estate Surveyors Nigeria Limited 🏠',
            'bill.owed' => '₦999,999,999.99',
            'bill.due_short' => 'tomorrow (1 Jan)',
            'bill.payment_reference' => 'GP-7K3M9QXYZ12345',
            'pay_link' => route('pay.show', ['reference' => 'GP-7K3M9QXYZ1234']),
            'tenant.secret' => 'leaked',
        ]);

        expect(strlen($text))->toBeLessThanOrEqual(160)
            ->and(SmsText::outside($text))->toBe([])
            ->and($text)->toStartWith('Olayinka & Adebayo Prope: rent of NGN 999,999,999.99 is due tomorrow (1 Jan). Pay to ref GP-7K3M9QXYZ1234: ')
            ->and($text)->toEndWith(route('pay.show', ['reference' => 'GP-7K3M9QXYZ1234']))
            ->and($text)->not->toContain('leaked');
    });

    it('refuses to save an SMS that could pass 160 characters, or that uses characters outside one GSM page', function (string $body, string $why) {
        expect(fn () => $this->templates->save($this->sms, new TemplateContent('', $body), $this->chika))
            ->toThrow(TemplateRefused::class, $why)
            ->and($this->sms->versions()->count())->toBe(1);
    })->with([
        'too long with the longest values' => ['{{ agency.name }}: rent of {{ bill.owed }} is due {{ bill.due_short }}. Please pay with reference {{ bill.payment_reference }} at {{ pay_link }} today. Thank you!', 'can reach'],
        'naira sign' => ['{{ agency.name }}: rent of ₦{{ bill.owed }} is due.', 'Write NGN'],
        'emoji' => ['{{ agency.name }}: rent due 🏠', 'Not allowed'],
        'empty' => [' ', 'needs some text'],
    ]);

    it('saves a short SMS as a new version, on one line, and sends it', function () {
        $this->templates->save($this->sms, new TemplateContent('ignored', "{{ agency.name }}: rent {{ bill.owed }}\n due {{ bill.due_short }}.", 'ignored'), $this->chika);

        $version = $this->sms->refresh()->currentVersion;
        expect([$version->version, $version->subject, $version->body_markdown, $version->action_label])->toBe([2, '', '{{ agency.name }}: rent {{ bill.owed }} due {{ bill.due_short }}.', null])
            ->and($this->templates->renderSms('reminder.rent_due.sms', ['agency.name' => 'Adeyemi Realty', 'bill.owed' => '₦10', 'bill.due_short' => 'today (1 Jan)']))
            ->toBe('Adeyemi Realty: rent NGN 10 due today (1 Jan).');
    });

    it('allows SMS only for transactional and reminder messages, each placeholder with a longest length', function () {
        $catalog = app(TemplateCatalog::class);
        $sms = fn (TemplateCategory $category, ?int $max) => new TemplateDefinition('test.promo', 'Promo', '', $category, [new Placeholder('agency.name', 'Agency', 'Adeyemi', $max)], new TemplateContent('', 'Hi'), TemplateChannel::Sms);

        expect(fn () => $catalog->add($sms(TemplateCategory::Marketing, 20)))->toThrow(InvalidArgumentException::class, 'transactional or a reminder')
            ->and(fn () => $catalog->add($sms(TemplateCategory::Security, 20)))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $catalog->add($sms(TemplateCategory::Reminder, null)))->toThrow(InvalidArgumentException::class, 'longest length');
    });
});

it('edits an SMS in the console with its length against one page, and no subject, button or test email', function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    $this->actingAs($this->chika, 'staff');

    Livewire::test(EditEmailTemplate::class, ['record' => $this->sms->getRouteKey()])
        ->assertSee('data-test="sms-preview"', false)
        ->assertSee('Adeyemi Realty: rent of NGN 1,500,000 is due in 7 days (1 Jan). Pay to ref GP-7K3M9Q:')
        ->assertFormFieldHidden('subject')
        ->assertFormFieldHidden('action_label')
        ->assertActionHidden('sendTest')
        ->fillForm(['body' => '{{ agency.name }}: rent of {{ bill.owed }} is due {{ bill.due_short }}. Please pay with reference {{ bill.payment_reference }} at {{ pay_link }} today. Thank you!'])
        ->assertSee('too long to save')
        ->call('save')
        ->assertNotified();

    expect($this->sms->refresh()->currentVersion->version)->toBe(1);

    Livewire::test(EditEmailTemplate::class, ['record' => $this->sms->getRouteKey()])
        ->fillForm(['body' => '{{ agency.name }}: rent of {{ bill.owed }} due {{ bill.due_short }}. Ref {{ bill.payment_reference }}'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved as version 2');
});
