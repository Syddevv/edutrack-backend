<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/mail.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function create_mailer(): PHPMailer
{
    static $autoloadLoaded = false;

    if (!$autoloadLoaded) {
        $autoloadPath = __DIR__ . '/../vendor/autoload.php';

        if (!is_file($autoloadPath)) {
            throw new RuntimeException('PHPMailer is not installed.');
        }

        require_once $autoloadPath;
        $autoloadLoaded = true;
    }

    $config = mail_config();
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = (string) $config['host'];
    $mailer->Port = (int) $config['port'];
    $mailer->SMTPAuth = true;
    $mailer->Username = (string) $config['username'];
    $mailer->Password = (string) $config['password'];
    $mailer->CharSet = 'UTF-8';

    $encryption = strtolower((string) $config['encryption']);
    if ($encryption === 'ssl') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mailer->setFrom(
        (string) $config['from_email'],
        (string) $config['from_name']
    );
    $mailer->isHTML(true);

    return $mailer;
}

function send_password_reset_code_email(string $recipientEmail, string $code): void
{
    try {
        $mailer = create_mailer();
        $mailer->addAddress($recipientEmail);
        $mailer->Subject = 'EduTrack password reset code';
        $mailer->Body = build_password_reset_email_html($code);
        $mailer->AltBody = sprintf(
            "Your EduTrack password reset code is %s. The code expires in 15 minutes.",
            $code
        );
        $mailer->send();
    } catch (Exception | RuntimeException $exception) {
        throw new RuntimeException('Unable to send the password reset email.', 0, $exception);
    }
}

function build_password_reset_email_html(string $code): string
{
    $escapedCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
  <body style="margin:0;background:#f6f4ef;padding:32px 16px;font-family:Inter,Arial,sans-serif;color:#171717;">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e4dfd8;border-radius:20px;box-shadow:0 24px 60px rgba(15,23,42,0.12);overflow:hidden;">
      <div style="padding:24px 24px 0;">
        <div style="width:44px;height:44px;border-radius:14px;background:#171717;color:#ffffff;display:grid;place-items:center;font-size:20px;font-weight:700;">E</div>
      </div>
      <div style="padding:20px 24px 28px;">
        <p style="margin:0 0 8px;color:#6f7682;font-size:13px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;">EduTrack Security</p>
        <h1 style="margin:0 0 12px;font-size:26px;line-height:1.1;letter-spacing:-0.03em;">Password Reset Code</h1>
        <p style="margin:0 0 20px;color:#4b5563;font-size:15px;line-height:1.6;">Use the verification code below to reset your EduTrack password. The code expires in 15 minutes.</p>
        <div style="margin:0 0 20px;padding:18px 20px;border-radius:18px;background:#f7f5f0;border:1px solid #efece7;text-align:center;">
          <span style="display:block;font-family:'JetBrains Mono',Consolas,monospace;font-size:34px;font-weight:700;letter-spacing:0.32em;color:#171717;">{$escapedCode}</span>
        </div>
        <p style="margin:0;color:#6f7682;font-size:14px;line-height:1.6;">If you did not request this password reset, you can ignore this email.</p>
      </div>
    </div>
  </body>
</html>
HTML;
}
