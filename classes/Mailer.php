<?php
/**
 * EarnSphere - Mailer Class
 * Raw-socket SMTP mailer with PHP mail() fallback
 */

require_once __DIR__ . '/../config/config.php';

class Mailer {
    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;

    public function __construct() {
        $this->host       = SMTP_HOST;
        $this->port       = (int) SMTP_PORT;
        $this->encryption = SMTP_ENCRYPTION;
        $this->username   = SMTP_USER;
        $this->password   = SMTP_PASS;
        $this->fromEmail  = FROM_EMAIL;
        $this->fromName   = FROM_NAME;
    }

    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        // Try SMTP first
        $result = $this->sendSMTP($to, $subject, $htmlBody);
        if ($result) return true;

        // Fallback to PHP mail()
        error_log("Mailer: SMTP failed, falling back to PHP mail()");
        return $this->sendPHPMail($to, $subject, $htmlBody);
    }

    private function sendSMTP(string $to, string $subject, string $htmlBody): bool {
        if ($this->encryption === 'ssl') {
            $remote = "ssl://{$this->host}:{$this->port}";
        } else {
            $remote = "tcp://{$this->host}:{$this->port}";
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        $socket = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            error_log("Mailer: SMTP connection failed: {$errstr} ({$errno})");
            return false;
        }

        $response = fread($socket, 512);

        fwrite($socket, "EHLO earnsphere\r\n");
        $response = $this->readResponse($socket);

        if ($this->encryption !== 'ssl') {
            fwrite($socket, "STARTTLS\r\n");
            $response = $this->readResponse($socket);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            fwrite($socket, "EHLO earnsphere\r\n");
            $response = $this->readResponse($socket);
        }

        fwrite($socket, "AUTH LOGIN\r\n");
        $response = $this->readResponse($socket);

        fwrite($socket, base64_encode($this->username) . "\r\n");
        $response = $this->readResponse($socket);

        fwrite($socket, base64_encode($this->password) . "\r\n");
        $response = $this->readResponse($socket);

        fwrite($socket, "MAIL FROM:<{$this->fromEmail}>\r\n");
        $response = $this->readResponse($socket);

        fwrite($socket, "RCPT TO:<{$to}>\r\n");
        $response = $this->readResponse($socket);

        fwrite($socket, "DATA\r\n");
        $response = $this->readResponse($socket);

        $headers  = "From: =?UTF-8?B?" . base64_encode($this->fromName) . "?= <{$this->fromEmail}>\r\n";
        $headers .= "To: <{$to}>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "Message-ID: <" . md5(uniqid()) . "@" . $this->host . ">\r\n";
        $headers .= "\r\n";

        fwrite($socket, $headers . $htmlBody . "\r\n.\r\n");
        $response = $this->readResponse($socket);

        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        $code = substr(trim($response ?? ''), 0, 3);
        return $code === '250';
    }

    private function sendPHPMail(string $to, string $subject, string $htmlBody): bool {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$this->fromName} <{$this->fromEmail}>\r\n";
        $headers .= "X-Mailer: EarnSphere/1.0\r\n";

        $result = @mail($to, $subject, $htmlBody, $headers);
        if (!$result) {
            error_log("Mailer: PHP mail() failed for {$to}");
        }
        return $result;
    }

    private function readResponse($socket): string {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $response;
    }
}
