<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Verify your SofiaCart merchant email address</title>
</head>
<body style="margin:0; padding:0; background-color:#f6f5fb; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#160f35;">
    <span style="display:none; max-height:0; overflow:hidden; opacity:0;">Confirm your email address to finish setting up your SofiaCart merchant account.</span>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f6f5fb;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;">
                    <tr>
                        <td style="padding:0 0 20px 0;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="width:40px; height:40px; border-radius:10px; background-color:#7c3aed; background-image:linear-gradient(135deg, #7c3aed 0%, #925cff 50%, #ff8f3d 100%); color:#ffffff; font-size:15px; font-weight:700; text-align:center; vertical-align:middle;">SC</td>
                                    <td style="padding-left:12px; font-size:20px; font-weight:700; color:#160f35;">SofiaCart</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#ffffff; border:1px solid #e9e5f5; border-radius:20px; padding:36px 32px;">
                            <p style="margin:0 0 8px 0; font-size:12px; font-weight:600; letter-spacing:0.16em; text-transform:uppercase; color:#7c3aed;">Merchant onboarding</p>
                            <h1 style="margin:0 0 20px 0; font-size:24px; line-height:1.3; color:#160f35;">Verify your email address</h1>
                            <p style="margin:0 0 16px 0; font-size:15px; line-height:1.6;">{{ filled($name) ? 'Hi '.$name.',' : 'Hello,' }}</p>
                            <p style="margin:0 0 24px 0; font-size:15px; line-height:1.6;">
                                Thanks for registering your store with SofiaCart. Please confirm that this is your email address so we can finish setting up your merchant account.
                            </p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;">
                                <tr>
                                    <td style="border-radius:12px; background-color:#7c3aed;">
                                        <a href="{{ $verificationUrl }}" target="_blank" rel="noopener" style="display:inline-block; padding:14px 28px; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:12px;">Verify email address</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:#4b4669;">
                                This link expires in <strong>{{ $expiresIn }}</strong> and can be used once. If it expires, you can request a new one from the verification page.
                            </p>
                            <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:#4b4669;">
                                Verifying your email does not approve your store. The SofiaCart team still reviews your business documents, and we'll let you know once your application has been reviewed.
                            </p>
                            <p style="margin:0 0 6px 0; font-size:13px; line-height:1.6; color:#6b6789;">If the button doesn't work, copy and paste this link into your browser:</p>
                            <p style="margin:0 0 24px 0; font-size:13px; line-height:1.5; word-break:break-all;"><a href="{{ $verificationUrl }}" target="_blank" rel="noopener" style="color:#6422d0;">{{ $verificationUrl }}</a></p>
                            <hr style="border:none; border-top:1px solid #eeeaf7; margin:0 0 20px 0;">
                            <p style="margin:0; font-size:13px; line-height:1.6; color:#6b6789;">
                                Didn't register for SofiaCart? You can safely ignore this email. No account will be activated without verification.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 8px 0 8px; font-size:12px; line-height:1.6; color:#8a86a6; text-align:center;">
                            &copy; {{ date('Y') }} SofiaCart. This is an automated message about your merchant account; please don't reply.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
