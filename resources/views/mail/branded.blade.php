{{--
    The trusted layout around GoProperty's database email templates
    (P05-04; PLATFORM_CONSOLE.md § 5). $email->html is TemplateRenderer's
    output: Markdown with every typed tag escaped, so it is printed as is.
    Everything else comes from code (App\Mail\Templates\BrandedMail).
    Table layout and inline styles only, for mail clients.
--}}
@php
    /** @var \App\Mail\Templates\RenderedEmail $email */
    /** @var \App\Mail\Templates\EmailBrand $brand */
    $logoSrc = $logoSrc ?? (isset($message) && ($logo ?? null) !== null ? $message->embedData($logo, 'logo.png', 'image/png') : null);
    $colour = $brand->primaryColor;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $email->subject }}</title>
<style>
    .gp-body p { margin: 0 0 14px; }
    .gp-body a { color: {{ $colour }}; }
    .gp-body ul, .gp-body ol { margin: 0 0 14px; padding-left: 22px; }
    .gp-body h1, .gp-body h2, .gp-body h3 { margin: 0 0 12px; font-size: 18px; line-height: 1.3; color: #111827; }
    .gp-body blockquote { margin: 0 0 14px; padding: 4px 12px; border-left: 3px solid #E5E7EB; color: #4B5563; }
    .gp-body code { font-family: Menlo, Consolas, monospace; font-size: 13px; background: #F3F4F6; padding: 1px 4px; }
    @media (max-width: 620px) { .gp-card { width: 100% !important; } .gp-pad { padding-left: 20px !important; padding-right: 20px !important; } }
</style>
</head>
<body style="margin:0;padding:0;background:#F3F4F6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1F2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F6;" data-test="branded-email">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" class="gp-card" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#FFFFFF;border-radius:8px;overflow:hidden;">
                <tr><td style="height:6px;background:{{ $colour }};font-size:0;line-height:0;">&nbsp;</td></tr>
                <tr>
                    <td class="gp-pad" style="padding:24px 32px 8px;">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                @if ($logoSrc !== null)
                                    <td style="padding-right:12px;"><img src="{{ $logoSrc }}" alt="{{ $brand->name }}" height="40" style="display:block;height:40px;max-width:160px;border:0;"></td>
                                @elseif ($initials !== '')
                                    <td style="padding-right:12px;"><div style="width:40px;height:40px;line-height:40px;border-radius:8px;background:{{ $colour }};color:#FFFFFF;font-weight:700;font-size:15px;text-align:center;">{{ $initials }}</div></td>
                                @endif
                                <td style="font-size:17px;font-weight:700;color:#111827;" data-test="email-brand">{{ $brand->name }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td class="gp-pad gp-body" style="padding:16px 32px 8px;font-size:15px;line-height:1.6;color:#1F2937;" data-test="email-body">
                        {!! $email->html !!}
                    </td>
                </tr>
                @if ($url !== null)
                    <tr>
                        <td class="gp-pad" style="padding:4px 32px 24px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-radius:6px;background:{{ $colour }};">
                                        <a href="{{ $url }}" style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:600;color:#FFFFFF;text-decoration:none;" data-test="email-action">{{ $email->actionLabel }}</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:12px 0 0;font-size:12px;line-height:1.5;color:#6B7280;word-break:break-all;">If the button does not work, open {{ $url }}</p>
                        </td>
                    </tr>
                @endif
                <tr>
                    <td class="gp-pad" style="padding:16px 32px 24px;border-top:1px solid #E5E7EB;font-size:12px;line-height:1.6;color:#6B7280;" data-test="email-footer">
                        <strong style="color:#374151;">{{ $brand->name }}</strong>
                        @if ($brand->address !== null)<br>{{ $brand->address }}@endif
                        @if ($brand->phone !== null || $brand->email !== null)<br>{{ implode(' · ', array_filter([$brand->phone, $brand->email])) }}@endif
                        @if ($brand->showPoweredBy)<br>Sent with {{ $brand->productName }} on behalf of {{ $brand->name }}.@endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
