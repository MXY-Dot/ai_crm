<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Подтверждение почты WERO</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:40px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="width:480px; max-width:480px; background-color:#ffffff; border:1px solid #e4e4e7; border-radius:20px; overflow:hidden;">

                <tr>
                    <td style="height:4px; line-height:4px; font-size:0; background-color:#22c55e; background-image:linear-gradient(90deg,#2ee577,#22c55e 45%,#16a34a);">&nbsp;</td>
                </tr>

                <tr>
                    <td style="padding:36px 40px 8px 40px;" align="center">
                        <img src="{{ $logoUrl }}" alt="WERO" height="26" style="height:26px; width:auto; display:block; border:0;">
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 40px 0 40px;" align="center">
                        <span style="display:inline-block; padding:6px 14px; border-radius:999px; background-color:#dcfce7; color:#15803d; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase;">
                            Полный доступ
                        </span>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 40px 0 40px;" align="center">
                        <h1 style="margin:0; color:#18181b; font-size:24px; line-height:1.3; font-weight:700; letter-spacing:-0.01em;">
                            Откройте доступ к CRM
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10px 40px 0 40px;" align="center">
                        <p style="margin:0; color:#71717a; font-size:14px; line-height:1.6;">
                            Это последний шаг: подтвердите почту по ссылке ниже, чтобы открыть полный доступ к WERO — сейчас доступен только раздел профиля.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px 40px 0 40px;" align="center">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="border-radius:999px; background-color:#16a34a;">
                                    <a href="{{ $verifyUrl }}" style="display:inline-block; padding:14px 36px; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:999px;">
                                        Подтвердить почту
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 40px 0 40px;" align="center">
                        <p style="margin:0; color:#a1a1aa; font-size:12px; line-height:1.6;">
                            Или скопируйте ссылку в браузер:<br>
                            <a href="{{ $verifyUrl }}" style="color:#16a34a; word-break:break-all;">{{ $verifyUrl }}</a>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:12px 40px 0 40px;" align="center">
                        <p style="margin:0; color:#a1a1aa; font-size:12px;">Ссылка действует 30 минут</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:32px 40px 0 40px;">
                        <div style="border-top:1px solid #e4e4e7; font-size:0; line-height:0;">&nbsp;</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 40px 32px 40px;" align="center">
                        <p style="margin:0; color:#a1a1aa; font-size:12px; line-height:1.6;">
                            Если вы не запрашивали это письмо — просто проигнорируйте его, доступ останется ограничен разделом профиля.
                        </p>
                        <p style="margin:12px 0 0 0; color:#d4d4d8; font-size:11px;">
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
