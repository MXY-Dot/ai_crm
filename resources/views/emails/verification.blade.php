<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Код подтверждения WERO</title>
</head>
<body style="margin:0; padding:0; background-color:#09090b; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#09090b; padding:40px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="width:480px; max-width:480px; background-color:#131316; border:1px solid #26262b; border-radius:20px; overflow:hidden;">

                <!-- glow header strip -->
                <tr>
                    <td style="height:4px; line-height:4px; font-size:0; background-color:#2ee577; background-image:linear-gradient(90deg,#2ee577,#22c55e 45%,#16a34a);">&nbsp;</td>
                </tr>

                <tr>
                    <td style="padding:36px 40px 8px 40px;" align="center">
                        <img src="{{ $logoUrl }}" alt="WERO" height="28" style="height:28px; width:auto; display:block; border:0;">
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 40px 0 40px;" align="center">
                        <span style="display:inline-block; padding:6px 14px; border-radius:999px; background-color:rgba(46,229,119,0.12); border:1px solid rgba(46,229,119,0.3); color:#2ee577; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase;">
                            Подтверждение почты
                        </span>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 40px 0 40px;" align="center">
                        <h1 style="margin:0; color:#fafafa; font-size:24px; line-height:1.25; font-weight:700; letter-spacing:-0.01em;">
                            Один шаг до старта
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10px 40px 0 40px;" align="center">
                        <p style="margin:0; color:#a1a1aa; font-size:14px; line-height:1.6;">
                            Введите этот код в WERO, чтобы подтвердить почту и продолжить регистрацию.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px 40px 0 40px;" align="center">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                @foreach (str_split($code) as $digit)
                                <td style="width:44px; height:56px; background-color:#1c1c21; border:1px solid #2ee577; border-radius:12px; text-align:center; vertical-align:middle; font-family:'Courier New',Courier,monospace; font-size:26px; font-weight:700; color:#2ee577; {{ !$loop->last ? 'padding-right:8px; -webkit-margin-end:8px;' : '' }}">
                                    <div style="width:44px; height:56px; line-height:56px; text-align:center;">{{ $digit }}</div>
                                </td>
                                @if (!$loop->last)
                                <td style="width:8px; font-size:0; line-height:0;">&nbsp;</td>
                                @endif
                                @endforeach
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:12px 40px 0 40px;" align="center">
                        <p style="margin:0; color:#71717a; font-size:12px;">Код действует 15 минут</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px 40px 0 40px;" align="center">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="border-radius:999px; background-color:#2ee577;">
                                    <a href="{{ $verifyUrl }}" style="display:inline-block; padding:13px 32px; font-size:14px; font-weight:700; color:#0a1f10; text-decoration:none; border-radius:999px;">
                                        Подтвердить одним нажатием
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 40px 0 40px;" align="center">
                        <p style="margin:0; color:#52525b; font-size:12px; line-height:1.6;">
                            Или скопируйте ссылку в браузер:<br>
                            <a href="{{ $verifyUrl }}" style="color:#3f9d64; word-break:break-all;">{{ $verifyUrl }}</a>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:32px 40px 0 40px;">
                        <div style="border-top:1px solid #26262b; font-size:0; line-height:0;">&nbsp;</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 40px 32px 40px;" align="center">
                        <p style="margin:0; color:#52525b; font-size:12px; line-height:1.6;">
                            Если вы не регистрировались в WERO — просто проигнорируйте это письмо.
                        </p>
                        <p style="margin:12px 0 0 0; color:#3f3f46; font-size:11px;">
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
