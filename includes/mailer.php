<?php
// includes/mailer.php

require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_memo_email($to, $subject, $html_message) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = ;       // Brevo's SMTP server
        $mail->SMTPAuth   = true;                         
        $mail->Username   = ;     // <-- Your Brevo SMTP Username
        $mail->Password   = ;        // <-- The SMTP key you generated in Step 1
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = 587;                          

        // The 'setFrom' MUST use your authenticated domain to bypass spam filters!
        $mail->setFrom('system@brokenhappy.com', 'PersonalApps System'); 
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_message;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</div>', '</p>'], "\r\n", $html_message));

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("PersonalApps Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>