<?php
declare(strict_types=1);

/**
 * Minimal SMTP client for signup confirmations and password resets: STARTTLS
 * or implicit TLS, AUTH PLAIN/LOGIN, UTF-8 plain-text messages.
 */

function wk_mail_configured(): bool
{
    $c = wk_config()['smtp'];
    return !empty($c['host']) && !empty($c['from']);
}

function wk_mail_send(string $to, string $subject, string $text): void
{
    $c = wk_config()['smtp'];
    if (!wk_mail_configured()) {
        throw new RuntimeException('SMTP is not configured');
    }
    // No header injection: addresses and subject are single lines.
    $clean = fn(string $s) => trim(str_replace(["\r", "\n", "\0"], ' ', $s));
    $to = $clean($to);
    $from = $clean((string) $c['from']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid sender or recipient address');
    }
    $security = strtolower((string) $c['security']);
    $host = (string) $c['host'];
    $port = (int) $c['port'];
    $remote = ($security === 'tls' ? 'tls://' : 'tcp://') . $host . ':' . $port;

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        throw new RuntimeException("Can't connect to $host:$port ($errstr)");
    }
    stream_set_timeout($sock, 20);

    $read = function () use ($sock): array {
        $lines = '';
        while (($line = fgets($sock, 1024)) !== false) {
            $lines .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if ($lines === '') {
            throw new RuntimeException('SMTP server closed the connection');
        }
        return [(int) substr($lines, 0, 3), trim($lines)];
    };
    $cmd = function (string $line, array $expect) use ($sock, $read): string {
        fwrite($sock, $line . "\r\n");
        [$code, $reply] = $read();
        if (!in_array($code, $expect, true)) {
            $shown = preg_match('/^AUTH|^[A-Za-z0-9+\/=]+$/', $line) ? '(credentials)' : $line;
            throw new RuntimeException("SMTP: $shown → $reply");
        }
        return $reply;
    };

    try {
        [$code, $greeting] = $read();
        if ($code !== 220) {
            throw new RuntimeException("SMTP greeting: $greeting");
        }
        $helo = preg_replace('/[^A-Za-z0-9.-]/', '', (string) parse_url((string) (wk_config()['auth']['base_url'] ?? ''), PHP_URL_HOST)) ?: 'wankarr.local';
        $ehlo = $cmd("EHLO $helo", [250]);
        if ($security === 'starttls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $ehlo = $cmd("EHLO $helo", [250]);
        }
        if (!empty($c['user'])) {
            $user = (string) $c['user'];
            $pass = (string) $c['pass'];
            if (preg_match('/AUTH[ =][^\n]*PLAIN/i', $ehlo)) {
                $cmd('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235]);
            } else {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($user), [334]);
                $cmd(base64_encode($pass), [235]);
            }
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);

        $name = $clean((string) ($c['from_name'] ?: 'wankarr'));
        $encode = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $domain = substr(strrchr($from, '@') ?: '@wankarr.local', 1);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $encode($name) . " <$from>",
            "To: <$to>",
            'Subject: ' . $encode($clean($subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domain>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        $body = chunk_split(base64_encode(str_replace("\r\n", "\n", $text)), 76, "\r\n");
        fwrite($sock, implode("\r\n", $headers) . "\r\n\r\n" . $body . ".\r\n");
        [$code, $reply] = $read();
        if ($code !== 250) {
            throw new RuntimeException("SMTP: message rejected → $reply");
        }
        $cmd('QUIT', [221]);
        wk_log("mail: sent \"$subject\" to $to");
    } finally {
        fclose($sock);
    }
}
