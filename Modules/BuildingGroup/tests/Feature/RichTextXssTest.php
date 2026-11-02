<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Posts\RichText;

require_once __DIR__.'/group-world.php';

/*
 * P07-02 acceptance: "XSS suite: script/iframe/onload payloads
 * neutralized" (ARCHITECTURE.md § 6.8, § 10). Every payload goes through
 * the real writer (Posts\GroupPosts) as a post from the portal and as a
 * comment from the workspace, and through the Livewire page as a tenant
 * types it; what is stored and what both pages print carries no script,
 * frame, event handler, style, foreign markup or dangerous address. What
 * the toolbar makes (bold, italic, lists, links) is kept. A body that
 * reached the table some other way is cleaned again on the way out.
 */

/** What must never reach a page from a post or comment. */
const XSS_MARKERS = '/<\s*(script|iframe|frame|object|embed|svg|math|img|style|link|meta|base|form|input|button|textarea|select|template|noscript|video|audio|details|body|html)\b|\son[a-z]+\s*=|javascript:|vbscript:|data:text|srcdoc|formaction|style\s*=|xlink:href/i';

dataset('xss payloads', [
    'script' => ['<script>alert(1)</script><p>Water is off on Friday.</p>'],
    'script with attributes' => ['<script src="https://evil.example/x.js"></script>Water is off'],
    'iframe' => ['<iframe src="javascript:alert(1)"></iframe>Water is off'],
    'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>Water is off'],
    'body onload' => ['<body onload="alert(1)"><p>Water is off</p></body>'],
    'img onerror' => ['<img src="x" onerror="alert(1)">Water is off'],
    'svg onload' => ['<svg onload="alert(1)"><circle r="1"/></svg>Water is off'],
    'svg script' => ['<svg><script>alert(1)</script></svg>Water is off'],
    'paragraph onclick and style' => ['<p onclick="alert(1)" style="background:url(javascript:alert(1))">Water is off</p>'],
    'link to javascript' => ['<a href="javascript:alert(1)">Water is off</a>'],
    'link to javascript, mixed case' => ['<a href="JaVaScRiPt:alert(1)">Water is off</a>'],
    'link to javascript, encoded' => ['<a href="&#106;&#97;vascript:alert(1)">Water is off</a>'],
    'link to javascript, tab inside' => ["<a href=\"java\tscript:alert(1)\">Water is off</a>"],
    'link to a data address' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">Water is off</a>'],
    'link to vbscript' => ['<a href="vbscript:msgbox(1)">Water is off</a>'],
    'link with onmouseover' => ['<a href="https://example.com" onmouseover="alert(1)">Water is off</a>'],
    'object' => ['<object data="javascript:alert(1)"></object>Water is off'],
    'embed' => ['<embed src="https://evil.example/x.swf">Water is off'],
    'form and autofocus' => ['<form action="javascript:alert(1)"><input onfocus="alert(1)" autofocus><button formaction="javascript:alert(1)">Go</button></form>Water is off'],
    'details ontoggle' => ['<details open ontoggle="alert(1)"><summary>Water is off</summary></details>'],
    'style element' => ['<style>body{background:url(javascript:alert(1))}</style>Water is off'],
    'meta refresh' => ['<meta http-equiv="refresh" content="0;url=javascript:alert(1)">Water is off'],
    'base href' => ['<base href="javascript:alert(1)//">Water is off'],
    'math mutation' => ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)></style></mglyph></table></mtext></math>Water is off'],
    // These two swallow whatever follows them, so the words come first.
    'noscript mutation' => ['<p>Water is off</p><noscript><p title="</noscript><img src=x onerror=alert(1)>"></p></noscript>'],
    'broken-up script tag' => ['<p>Water is off</p><scr<script>ipt>alert(1)</scr</script>ipt>'],
    'attribute breakout' => ['"><script>alert(1)</script><p title="x">Water is off</p>'],
    'template' => ['<template><script>alert(1)</script></template>Water is off'],
    'xlink' => ['<svg><a xlink:href="javascript:alert(1)"><text>Water is off</text></a></svg>Water is off'],
]);

beforeEach(function () {
    groupWorld();
});

afterEach(fn () => groupsFreshRequest());

it('acceptance: neutralizes the payload in a post and a comment, in the table and on both pages', function (string $payload) {
    $post = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, $payload);
    $comment = commentIn($this->acme, $this->agent, AuthorKind::Staff, $post, $payload);

    $stored = inOrganization($this->acme, fn (): array => [
        GroupPost::query()->whereKey($post->id)->value('body'),
        GroupComment::query()->whereKey($comment->id)->value('body'),
    ]);

    foreach ($stored as $body) {
        expect((string) $body)->not->toMatch(XSS_MARKERS)
            ->and(RichText::text($body))->toContain('Water is off');
    }

    // The workspace's page and the tenant's both print the words without any of it.
    foreach ([workspaceGroupPage($this->agent, $this->palmGroup), portalGroupPage($this->tunde, $this->palmGroup)] as $page) {
        $shown = shownRichText($page->assertOk());

        expect(substr_count($shown, 'Water is off'))->toBeGreaterThanOrEqual(2)
            ->and($shown)->not->toMatch(XSS_MARKERS)
            ->and(htmlOf($page))->not->toContain('alert(1)</script>')->not->toContain('onerror="alert(1)"');
    }
})->with('xss payloads');

it('acceptance: neutralizes a payload typed into the portal\'s post form and comment box', function () {
    $page = portalFeed($this->kemi, $this->palmGroup)
        ->set('body', '<p>Generator <strong>service</strong> today</p><img src=x onerror=alert(1)><script>alert(1)</script><iframe src="javascript:alert(1)"></iframe>')
        ->call('publish')
        ->assertHasNoErrors()
        ->assertSee('Posted.');

    $post = inOrganization($this->acme, fn (): GroupPost => GroupPost::query()->latest('id')->firstOrFail());
    expect($post->body)->toBe('<p>Generator <strong>service</strong> today</p>')
        ->and($post->author_kind)->toBe(AuthorKind::Tenant)
        ->and($post->author_tenant_profile_id)->toBe($this->kemiAtAcme->id);

    $page->set("comments.{$post->id}", '<p onmouseover="alert(1)">Thanks <a href="javascript:alert(1)">for this</a></p><svg onload=alert(1)>')
        ->call('comment', $post->id)
        ->assertHasNoErrors();

    $comment = inOrganization($this->acme, fn (): GroupComment => GroupComment::query()->latest('id')->firstOrFail());
    expect($comment->body)->toBe('<p>Thanks for this</p>')
        ->and(shownRichText($page->html()))->not->toMatch(XSS_MARKERS);
});

it('keeps what the toolbar makes: bold, italic, lists and links that open safely', function () {
    $post = postIn($this->acme, $this->admin, AuthorKind::Staff, $this->palmGroup,
        '<div>Notice for <b>all</b> <i>residents</i>:</div><ul><li>Water off <strong>Friday</strong></li><li>Back <em>Saturday</em></li></ul>'
        .'<ol><li>Fill your tanks</li></ol><p>Details: <a href="https://example.com/notice?day=friday&amp;time=9">the notice</a>, '
        .'questions to <a href="mailto:estate@example.com">the estate</a>.</p><h2>Thanks</h2><span style="color:red">Seyi</span>');

    expect($post->body)->toBe(
        '<p>Notice for <b>all</b> <i>residents</i>:</p><ul><li>Water off <strong>Friday</strong></li><li>Back <em>Saturday</em></li></ul>'
        .'<ol><li>Fill your tanks</li></ol><p>Details: <a href="https://example.com/notice?day&#61;friday&amp;time&#61;9" rel="nofollow noopener noreferrer ugc" target="_blank">the notice</a>, '
        .'questions to <a href="mailto:estate&#64;example.com" rel="nofollow noopener noreferrer ugc" target="_blank">the estate</a>.</p><p>Thanks</p>Seyi'
    );

    $shown = shownRichText(portalGroupPage($this->kemi, $this->palmGroup)->assertOk());
    expect($shown)->toContain('<ul><li>Water off <strong>Friday</strong></li>')
        ->toContain('target="_blank">the notice</a>');
});

it('keeps text that only looks like markup as text', function () {
    $post = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Rent < 2m & service charge > 100k? <3');

    expect($post->body)->toBe('Rent &lt; 2m &amp; service charge &gt; 100k? &lt;3')
        ->and(RichText::text($post->body))->toBe('Rent < 2m & service charge > 100k? <3');
});

it('cleans again on the way out a body that reached the table without the writer', function () {
    $post = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Water is off');
    DB::table('group_posts')->where('id', $post->id)->update(['body' => '<p onclick="alert(1)">Water is off</p><script>alert(1)</script><img src=x onerror=alert(1)>']);

    $shown = shownRichText(portalGroupPage($this->tunde, $this->palmGroup)->assertOk());

    expect($shown)->toContain('<p>Water is off</p>')->not->toMatch(XSS_MARKERS);
});

it('refuses words that are only markup, and more HTML than a post may carry, without cutting it', function () {
    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, '<script>alert(1)</script><p><br></p><img src=x>'))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['body' => ['Write something or add a photo.']]));

    $huge = '<p>'.str_repeat('<b></b>', 3000).'Water is off</p>';
    expect(strlen($huge))->toBeGreaterThan(RichText::MAX_INPUT_BYTES)
        ->and(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, $huge))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['body' => ['This is too long. Shorten it and try again.']]));

    expect(inOrganization($this->acme, fn (): int => GroupPost::query()->count()))->toBe(0);
});
