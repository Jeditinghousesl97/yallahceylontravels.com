<?php

if (!function_exists('siteMailRecipient')) {
    function siteMailRecipient() {
        $siteEmail = trim((string) setting('site_email', ''));
        if ($siteEmail !== '' && filter_var($siteEmail, FILTER_VALIDATE_EMAIL)) {
            return $siteEmail;
        }

        $fromEmail = trim((string) setting('smtp_from_email', ''));
        if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return $fromEmail;
        }

        $smtpUser = trim((string) setting('smtp_user', ''));
        if ($smtpUser !== '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
            return $smtpUser;
        }

        return '';
    }
}

if (!function_exists('sendSiteEmail')) {
    function sendSiteEmail($to, $subject, $htmlBody, $textBody = '', array $options = []) {
        $to = trim((string) $to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log('sendSiteEmail: invalid recipient address.');
            return false;
        }

        $fromEmail = trim((string) setting('smtp_from_email', ''));
        $smtpUser  = trim((string) setting('smtp_user', ''));
        $fromName  = trim((string) setting('smtp_from_name', setting('site_name', 'Website')));

        if ($fromEmail === '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $smtpUser;
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            error_log('sendSiteEmail: missing SMTP from email.');
            return false;
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . formatMailbox($fromEmail, $fromName),
        ];

        $replyToEmail = trim((string) ($options['reply_to_email'] ?? ''));
        $replyToName  = trim((string) ($options['reply_to_name'] ?? ''));
        if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . formatMailbox($replyToEmail, $replyToName);
        }

        $smtpHost = trim((string) setting('smtp_host', ''));
        if ($smtpHost !== '') {
            return sendViaSmtp($to, $subject, $htmlBody, $textBody, [
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'reply_to_email' => $replyToEmail,
                'reply_to_name' => $replyToName,
            ]);
        }

        return @mail($to, encodeHeader($subject), $htmlBody, implode("\r\n", $headers));
    }
}

if (!function_exists('sendViaSmtp')) {
    function sendViaSmtp($to, $subject, $htmlBody, $textBody, array $meta) {
        $host       = trim((string) setting('smtp_host', ''));
        $port       = (int) setting('smtp_port', '587');
        $user       = trim((string) setting('smtp_user', ''));
        $pass       = (string) setting('smtp_pass', '');
        $encryption = strtolower(trim((string) setting('smtp_encryption', 'tls')));

        if ($host === '' || $port <= 0) {
            error_log('sendViaSmtp: SMTP host/port not configured.');
            return false;
        }

        $transportHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
        $socket = @stream_socket_client(
            $transportHost . ':' . $port,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT
        );

        if (!$socket) {
            error_log('sendViaSmtp connect failed: ' . $errstr . ' (' . $errno . ')');
            return false;
        }

        stream_set_timeout($socket, 20);

        try {
            smtpExpect($socket, [220]);
            smtpCommand($socket, 'EHLO ' . smtpServerName(), [250]);

            if ($encryption === 'tls') {
                smtpCommand($socket, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Unable to enable TLS encryption.');
                }
                smtpCommand($socket, 'EHLO ' . smtpServerName(), [250]);
            }

            if ($user !== '') {
                smtpCommand($socket, 'AUTH LOGIN', [334]);
                smtpCommand($socket, base64_encode($user), [334]);
                smtpCommand($socket, base64_encode($pass), [235]);
            }

            smtpCommand($socket, 'MAIL FROM:<' . $meta['from_email'] . '>', [250]);
            smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            smtpCommand($socket, 'DATA', [354]);

            $payload = buildSmtpMessage($to, $subject, $htmlBody, $textBody, $meta);
            fwrite($socket, $payload . "\r\n.\r\n");
            smtpExpect($socket, [250]);
            smtpCommand($socket, 'QUIT', [221]);
            fclose($socket);
            return true;
        } catch (Throwable $e) {
            error_log('sendViaSmtp failed: ' . $e->getMessage());
            if (is_resource($socket)) {
                @fwrite($socket, "QUIT\r\n");
                @fclose($socket);
            }
            return false;
        }
    }
}

if (!function_exists('buildSmtpMessage')) {
    function buildSmtpMessage($to, $subject, $htmlBody, $textBody, array $meta) {
        $boundary = 'b' . bin2hex(random_bytes(12));
        $lines = [
            'Date: ' . date('r'),
            'To: ' . formatMailbox($to, ''),
            'From: ' . formatMailbox($meta['from_email'], $meta['from_name'] ?? ''),
            'Subject: ' . encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        if (!empty($meta['reply_to_email']) && filter_var($meta['reply_to_email'], FILTER_VALIDATE_EMAIL)) {
            $lines[] = 'Reply-To: ' . formatMailbox($meta['reply_to_email'], $meta['reply_to_name'] ?? '');
        }

        $plainText = trim($textBody) !== '' ? $textBody : html_entity_decode(strip_tags($htmlBody), ENT_QUOTES, 'UTF-8');
        $plainText = preg_replace("/\r?\n/", "\r\n", $plainText);
        $htmlBody  = preg_replace("/\r?\n/", "\r\n", $htmlBody);

        $body = [
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            smtpEscapeBody($plainText),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            smtpEscapeBody($htmlBody),
            '--' . $boundary . '--',
            '',
        ];

        return implode("\r\n", array_merge($lines, $body));
    }
}

if (!function_exists('smtpEscapeBody')) {
    function smtpEscapeBody($body) {
        return preg_replace('/^\./m', '..', (string) $body);
    }
}

if (!function_exists('smtpServerName')) {
    function smtpServerName() {
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        return preg_replace('/[^A-Za-z0-9\.\-]/', '', $host) ?: 'localhost';
    }
}

if (!function_exists('smtpCommand')) {
    function smtpCommand($socket, $command, array $expectedCodes) {
        fwrite($socket, $command . "\r\n");
        return smtpExpect($socket, $expectedCodes);
    }
}

if (!function_exists('smtpExpect')) {
    function smtpExpect($socket, array $expectedCodes) {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException(trim($response) ?: 'Unknown SMTP error');
        }

        return $response;
    }
}

if (!function_exists('formatMailbox')) {
    function formatMailbox($email, $name = '') {
        $email = trim((string) $email);
        $name  = trim((string) $name);
        if ($name === '') {
            return $email;
        }

        return encodeHeader($name) . ' <' . $email . '>';
    }
}

if (!function_exists('encodeHeader')) {
    function encodeHeader($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }
}
