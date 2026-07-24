<?php
/**
 * EarnSphere - Mailer Class
 * Raw-socket SMTP mailer (ported from mtaita-tech)
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

    /**
     * Send email via raw SMTP socket
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
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

        $socket = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            error_log("Mailer: SMTP connection failed: {$errstr} ({$errno})");
            return false;
        }

        // Read greeting
        $response = fread($socket, 512);

        // EHLO
        fwrite($socket, "EHLO earnsphere\r\n");
        $response = $this->readResponse($socket);

        // STARTTLS if not ssl
        if ($this->encryption !== 'ssl') {
            fwrite($socket, "STARTTLS\r\n");
            $response = $this->readResponse($socket);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            fwrite($socket, "EHLO earnsphere\r\n");
            $response = $this->readResponse($socket);
        }

        // AUTH LOGIN
        fwrite($socket, "AUTH LOGIN\r\n");
        $response = $this->readResponse($socket);

        fwrite($socket, base64_encode($this->username) . "\r\n");
        $response = $this->readResponse($socket);

        fwrite($socket, base64_encode($this->password) . "\r\n");
        $response = $this->readResponse($socket);

        // MAIL FROM
        fwrite($socket, "MAIL FROM:<{$this->fromEmail}>\r\n");
        $response = $this->readResponse($socket);

        // RCPT TO
        fwrite($socket, "RCPT TO:<{$to}>\r\n");
        $response = $this->readResponse($socket);

        // DATA
        fwrite($socket, "DATA\r\n");
        $response = $this->readResponse($socket);

        // Build headers
        $boundary = md5(uniqid(time()));
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

        // QUIT
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        $code = substr(trim($response ?? ''), 0, 3);
        return $code === '250';
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
