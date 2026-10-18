<?php

use PHPMailer\PHPMailer\PHPMailer;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function sendVerificationEmail(
    string $recipientEmail,
    string $recipientName,
    string $verificationUrl
): void {
    $config = require dirname(__DIR__) . '/config/mail.php';

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = $config['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['username'];
    $mail->Password = $config['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) $config['port'];
    $mail->CharSet = 'UTF-8';

    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($recipientEmail, $recipientName);

    $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8');

    $mail->isHTML(true);
    $mail->Subject = 'Verify your TICKETA email address';
    $mail->Body = "
        <h2>Welcome to TICKETA, {$safeName}!</h2>
        <p>Please verify your email address to activate your account.</p>
        <p>
            <a href=\"{$safeUrl}\"
               style=\"display:inline-block;padding:12px 20px;
                      background:#10c6a4;color:#102a26;
                      text-decoration:none;border-radius:8px;\">
                Verify my email
            </a>
        </p>
        <p>This link expires in 30 minutes and can only be used once.</p>
        <p>If you did not create this account, you can ignore this email.</p>
    ";

    $mail->AltBody =
        "Welcome to TICKETA. Verify your email using this link: "
        . $verificationUrl
        . "\nThis link expires in 30 minutes and can only be used once.";

    $mail->send();
}