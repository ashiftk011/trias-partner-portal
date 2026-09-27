<?php
// includes/mailer.php — Universal Email Sender helper with SMTP and native mail support

require_once __DIR__ . '/../config/db.php';

/**
 * Send email using configured SMTP settings from app_settings or PHP mail() fallback
 * 
 * @param string $toEmail Recipient email address
 * @param string $subject Email subject line
 * @param string $htmlBody HTML email content
 * @param string $plainText Optional plain text content fallback
 * @return array ['success' => bool, 'message' => string]
 */
function sendPortalEmail(string $toEmail, string $subject, string $htmlBody, string $plainText = ''): array {
    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid recipient email address.'];
    }

    $db = getDB();
    $settings = [];
    $stmt = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'smtp_%' OR setting_key='company_name'");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $smtpHost = trim($settings['smtp_host'] ?? '');
    $smtpPort = (int)($settings['smtp_port'] ?? 587);
    $smtpUser = trim($settings['smtp_username'] ?? '');
    $smtpPass = trim($settings['smtp_password'] ?? '');
    $smtpEnc  = strtolower(trim($settings['smtp_encryption'] ?? 'tls'));
    $fromEmail= trim($settings['smtp_from_email'] ?? $smtpUser) ?: 'no-reply@trias.com';
    $fromName = trim($settings['smtp_from_name'] ?? ($settings['company_name'] ?? APP_NAME));

    if (empty($plainText)) {
        $plainText = strip_tags($htmlBody);
    }

    // If SMTP host is configured, attempt direct socket SMTP transmission
    if (!empty($smtpHost)) {
        return sendViaSmtpSocket($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpEnc, $fromEmail, $fromName, $toEmail, $subject, $htmlBody, $plainText);
    }

    // Fallback: Use standard PHP mail()
    $boundary = md5(time());
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= $plainText . "\r\n\r\n";

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= "--{$boundary}--";

    $sent = @mail($toEmail, $subject, $body, $headers);
    if ($sent) {
        return ['success' => true, 'message' => "Email sent to {$toEmail} via standard mail handler."];
    } else {
        return ['success' => false, 'message' => "Failed to send email to {$toEmail} via mail handler. Configure SMTP settings in Settings for reliable delivery."];
    }
}

/**
 * Socket-based SMTP Client Implementation
 */
function sendViaSmtpSocket($host, $port, $user, $pass, $enc, $fromEmail, $fromName, $toEmail, $subject, $htmlBody, $plainText): array {
    $timeout = 10;
    $transport = ($enc === 'ssl') ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, $timeout);

    if (!$socket) {
        return ['success' => false, 'message' => "SMTP Connection Failed to {$host}:{$port} - {$errstr} ({$errno})"];
    }

    $getResponse = function() use ($socket) {
        $response = "";
        while ($str = fgets($socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) == " ") break;
        }
        return $response;
    };

    $sendCommand = function($cmd) use ($socket, $getResponse) {
        fputs($socket, $cmd . "\r\n");
        return $getResponse();
    };

    $res = $getResponse(); // Banner
    if (substr($res, 0, 3) != "220") {
        fclose($socket);
        return ['success' => false, 'message' => "SMTP Error Banner: {$res}"];
    }

    // EHLO
    $res = $sendCommand("EHLO " . gethostname());
    if (substr($res, 0, 3) != "250") {
        fclose($socket);
        return ['success' => false, 'message' => "EHLO Failed: {$res}"];
    }

    // STARTTLS if TLS
    if ($enc === 'tls') {
        $res = $sendCommand("STARTTLS");
        if (substr($res, 0, 3) == "220") {
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            $sendCommand("EHLO " . gethostname());
        }
    }

    // AUTH LOGIN if credentials provided
    if (!empty($user) && !empty($pass)) {
        $res = $sendCommand("AUTH LOGIN");
        if (substr($res, 0, 3) == "334") {
            $res = $sendCommand(base64_encode($user));
            if (substr($res, 0, 3) == "334") {
                $res = $sendCommand(base64_encode($pass));
                if (substr($res, 0, 3) != "235") {
                    fclose($socket);
                    return ['success' => false, 'message' => "SMTP Authentication Failed: Username/Password rejected."];
                }
            } else {
                fclose($socket);
                return ['success' => false, 'message' => "SMTP User Auth Failed: {$res}"];
            }
        }
    }

    // MAIL FROM & RCPT TO
    $res = $sendCommand("MAIL FROM: <{$fromEmail}>");
    if (substr($res, 0, 3) != "250") {
        fclose($socket);
        return ['success' => false, 'message' => "MAIL FROM Error: {$res}"];
    }

    $res = $sendCommand("RCPT TO: <{$toEmail}>");
    if (substr($res, 0, 3) != "250" && substr($res, 0, 3) != "251") {
        fclose($socket);
        return ['success' => false, 'message' => "RCPT TO Error: {$res}"];
    }

    // DATA
    $res = $sendCommand("DATA");
    if (substr($res, 0, 3) != "354") {
        fclose($socket);
        return ['success' => false, 'message' => "DATA Command Error: {$res}"];
    }

    // Build Email Body
    $boundary = md5(time());
    $emailData = "From: {$fromName} <{$fromEmail}>\r\n";
    $emailData .= "To: <{$toEmail}>\r\n";
    $emailData .= "Subject: {$subject}\r\n";
    $emailData .= "Date: " . date("r") . "\r\n";
    $emailData .= "MIME-Version: 1.0\r\n";
    $emailData .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";

    $emailData .= "--{$boundary}\r\n";
    $emailData .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $emailData .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $emailData .= $plainText . "\r\n\r\n";

    $emailData .= "--{$boundary}\r\n";
    $emailData .= "Content-Type: text/html; charset=UTF-8\r\n";
    $emailData .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $emailData .= $htmlBody . "\r\n\r\n";
    $emailData .= "--{$boundary}--\r\n.";

    $res = $sendCommand($emailData);
    $sendCommand("QUIT");
    fclose($socket);

    if (substr($res, 0, 3) == "250") {
        return ['success' => true, 'message' => "Email delivered successfully via SMTP ({$host})."];
    } else {
        return ['success' => false, 'message' => "SMTP Delivery Error: {$res}"];
    }
}
