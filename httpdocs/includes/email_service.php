<?php
// filepath: /var/www/vhosts/middleworld.farm/httpdocs/includes/email_service.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Send email using PHPMailer via IONOS SMTP
 * 
 * @param string $to_email Recipient email address
 * @param string $subject Email subject line
 * @param string $html_content HTML formatted email content
 * @param string $text_content Plain text version of email content
 * @return bool Success or failure
 */
function send_shop_email($to_email, $subject, $html_content, $text_content = '') {
    // Set your IONOS SMTP credentials here
    $smtp_host = 'smtp.ionos.co.uk';
    $smtp_port = 587;
    $smtp_username = 'self-serve-shop@middleworld.farm'; // e.g. you@yourdomain.com
    $smtp_password = 'HHyyffkkkallljjyytyhFF';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = 'tls';
        $mail->Port = $smtp_port;

        $mail->setFrom($smtp_username, 'MiddleWorld Farm Shop');
        $mail->addAddress($to_email);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html_content;
        $mail->AltBody = $text_content ?: strip_tags($html_content);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer error: " . $mail->ErrorInfo);
        return false;
    }
}