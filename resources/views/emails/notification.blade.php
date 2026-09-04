<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:40px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="width:480px; max-width:480px; background-color:#ffffff; border:1px solid #e4e4e7; border-radius:20px; overflow:hidden;">

                <tr>
                    <td style="height:4px; line-height:4px; font-size:0; background-color:{{ $urgent ? '#f97316' : '#22c55e' }}; background-image:linear-gradient(90deg,{{ $urgent ? '#fb923c,#f97316 45%,#ea580c' : '#2ee577,#22c55e 45%,#16a34a' }});">&nbsp;</td>
                </tr>

                <tr>
                    <td style="padding:32px 40px 0 40px;" align="center">
                        <img src="{{ $logoUrl }}" alt="WERO" height="24" style="height:24px; width:auto; display:block; border:0;">
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 40px 0 40px;" align="center">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="width:36px; height:36px; border-radius:999px; background-color:#dcfce7; text-align:center; vertical-align:middle; font-family:Georgia,'Times New Roman',serif; font-size:16px; font-weight:700; color:#15803d;">В</td>
                                <td style="padding-left:10px; text-align:left;">
                                    <p style="margin:0; font-size:13px; font-weight:600; color:#18181b;">Вера{{ $companyName ? ' · '.$companyName : '' }}</p>
                                    <p style="margin:0; font-size:11px; color:#a1a1aa;">Автоматические уведомления WERO</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:22px 40px 0 40px;" align="center">
                        <h1 style="margin:0; color:#18181b; font-size:20px; line-height:1.35; font-weight:700; letter-spacing:-0.01em; text-align:center;">{{ $title }}</h1>
                    </td>
                </tr>

                @if ($body)
                <tr>
                    <td style="padding:10px 40px 0 40px;" align="center">
                        <p style="margin:0; color:#52525b; font-size:14px; line-height:1.6; text-align:center;">{{ $body }}</p>
                    </td>
                </tr>
                @endif

                @if ($actionUrl)
                <tr>
                    <td style="padding:26px 40px 0 40px;" align="center">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="border-radius:999px; background-color:#16a34a;">
                                    <a href="{{ $actionUrl }}" style="display:inline-block; padding:12px 30px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:999px;">
                                        Открыть в WERO
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                @endif

                <tr>
                    <td style="padding:28px 40px 0 40px;">
                        <div style="border-top:1px solid #e4e4e7; font-size:0; line-height:0;">&nbsp;</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 40px 28px 40px;" align="center">
                        <p style="margin:0; color:#a1a1aa; font-size:11px; line-height:1.6;">
                            Настройки уведомлений — в WERO, раздел «Компания → Настройки».
                        </p>
                        <p style="margin:10px 0 0 0; color:#d4d4d8; font-size:11px;">
                            WERO &mdash; Omnichannel AI CRM
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
