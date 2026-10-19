@php
    /** @var \App\Services\Alerts\AlertMessage $alert */
    $color = $alert->color();
    $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $chip = $alert->icon().' '.$alert->label();
    $object = $alert->objectShort();
@endphp
<!DOCTYPE html>
<html lang="ru" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $alert->subject() }}</title>
<style>
    @media (max-width: 620px) {
        .sk-wrap { width: 100% !important; }
        .sk-pad { padding-left: 20px !important; padding-right: 20px !important; }
        .sk-label { display: block !important; width: 100% !important; padding-bottom: 0 !important; }
        .sk-value { display: block !important; width: 100% !important; padding-top: 2px !important; }
    }
    @media (prefers-color-scheme: dark) {
        .sk-page { background-color: #0f1115 !important; }
        .sk-card { background-color: #1a1d23 !important; }
        .sk-text { color: #f3f4f6 !important; }
        .sk-muted { color: #9ca3af !important; }
        .sk-line { border-color: #2d323b !important; }
        .sk-note { background-color: #23272f !important; }
    }
</style>
</head>
<body class="sk-page" style="margin:0;padding:0;background-color:#f3f4f6;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#f3f4f6;">{{ $alert->message }}</div>
<table role="presentation" class="sk-page" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f4f6" style="background-color:#f3f4f6;">
<tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" class="sk-wrap sk-card" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:600px;max-width:100%;background-color:#ffffff;border-radius:10px;overflow:hidden;">
        {{-- цветная полоса по важности --}}
        <tr><td height="6" bgcolor="{{ $color }}" style="height:6px;line-height:6px;font-size:0;background-color:{{ $color }};">&nbsp;</td></tr>

        {{-- заголовок события --}}
        <tr><td class="sk-pad" style="padding:28px 32px 0 32px;font-family:{{ $font }};">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td bgcolor="{{ $color }}" style="background-color:{{ $color }};border-radius:14px;padding:4px 12px;font-family:{{ $font }};font-size:12px;line-height:16px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#ffffff;">{{ $chip }}</td>
            </tr></table>
        </td></tr>

        {{-- сообщение --}}
        <tr><td class="sk-pad sk-text" style="padding:16px 32px 0 32px;font-family:{{ $font }};font-size:24px;line-height:32px;font-weight:700;color:#111827;">{{ $alert->message }}</td></tr>
        @if ($object)
            <tr><td class="sk-pad sk-muted" style="padding:6px 32px 0 32px;font-family:{{ $font }};font-size:15px;line-height:22px;color:#6b7280;">{{ $object }}@if ($alert->project) · {{ $alert->project }}@endif</td></tr>
        @endif

        @if ($note = $alert->testNote())
            <tr><td class="sk-pad" style="padding:18px 32px 0 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td class="sk-note sk-text" bgcolor="#f3f4f6" style="background-color:#f3f4f6;border-left:4px solid {{ $color }};border-radius:4px;padding:10px 14px;font-family:{{ $font }};font-size:14px;line-height:20px;color:#374151;">🧪 {{ $note }}</td>
                </tr></table>
            </td></tr>
        @endif

        {{-- детали --}}
        @if (count($alert->rows()))
            <tr><td class="sk-pad" style="padding:22px 32px 0 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;" class="sk-line">
                    @foreach ($alert->rows() as $row)
                        <tr>
                            <td class="sk-label sk-muted sk-line" width="130" valign="top" style="width:130px;padding:10px 12px 10px 0;border-bottom:1px solid #e5e7eb;font-family:{{ $font }};font-size:14px;line-height:20px;color:#6b7280;">{{ $row['label'] }}</td>
                            <td class="sk-value sk-text sk-line" valign="top" style="padding:10px 0;border-bottom:1px solid #e5e7eb;font-family:{{ $font }};font-size:14px;line-height:20px;color:#111827;word-break:break-word;">
                                @if ($row['url'])
                                    <a href="{{ $row['url'] }}" style="color:{{ $color }};text-decoration:underline;">{{ $row['value'] }}</a>
                                @else
                                    {{ $row['value'] }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td></tr>
        @endif

        {{-- кнопка --}}
        @if ($alert->url)
            <tr><td class="sk-pad" style="padding:28px 32px 0 32px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td align="center" bgcolor="{{ $color }}" style="background-color:{{ $color }};border-radius:6px;">
                        <a href="{{ $alert->url }}" style="display:inline-block;padding:12px 24px;font-family:{{ $font }};font-size:15px;line-height:20px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">Открыть в SystemKeeper</a>
                    </td>
                </tr></table>
            </td></tr>
        @endif

        {{-- подвал --}}
        <tr><td class="sk-pad sk-muted" style="padding:32px 32px 28px 32px;font-family:{{ $font }};font-size:12px;line-height:18px;color:#9ca3af;">
            SystemKeeper
            @if ($alert->ruleName !== null)
                · правило
                @if ($alert->ruleUrl)
                    <a href="{{ $alert->ruleUrl }}" style="color:#9ca3af;text-decoration:underline;">{{ $alert->ruleName }}</a>
                @else
                    {{ $alert->ruleName }}
                @endif
                · отключить можно в настройках правила
            @endif
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
