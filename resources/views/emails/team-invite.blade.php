<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Приглашение в WERO</title>
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
                    <td style="padding:32px 40px 0 40px;" align="center">
                        <img src="{{ $logoUrl }}" alt="WERO" height="24" style="height:24px; width:auto; display:block; border:0;">
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 40px 0 40px;" align="center">
                        <span style="display:inline-block; padding:6px 14px; border-radius:999px; background-color:#dcfce7; color:#15803d; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase;">
                            Приглашение в команду
                        </span>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 40px 0 40px;" align="center">
                        <h1 style="margin:0; color:#18181b; font-size:22px; line-height:1.3; font-weight:700; letter-spacing:-0.01em; text-align:center;">
                            {{ $inviteeName }}, вас ждут в {{ $companyName ?? 'WERO' }}
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10px 40px 0 40px;" align="center">
                        <p style="margin:0; color:#52525b; font-size:14px; line-height:1.6; text-align:center;">
                            {{ $inviterName ?? 'Владелец компании' }} пригласил(а) вас в команду{{ $companyName ? ' «'.$companyName.'»' : '' }} в WERO. Нажмите на кнопку ниже, чтобы войти — пароль не нужен, вы зададите свой на следующем шаге.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px 40px 0 40px;" align="center">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="border-radius:999px; background-color:#16a34a;">
                                    <a href="{{ $acceptUrl }}" style="display:inline-block; padding:13px 34px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:999px;">
                                        Войти в WERO
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 40px 0 40px;" align="center">
                        <p style="margin:0; color:#a1a1aa; font-size:12px; line-height:1.6;">
                            Ссылка действует 7 дней. После входа предложим задать пароль и, при желании, изменить имя.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px 40px 0 40px;">
                        <div style="border-top:1px solid #e4e4e7; font-size:0; line-height:0;">&nbsp;</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 40px 28px 40px;" align="center">
                        <p style="margin:0; color:#a1a1aa; font-size:11px; line-height:1.6;">
                            Если вы не ожидали это письмо — просто проигнорируйте его.
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
