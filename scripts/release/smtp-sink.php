<?php

/*
 * A tiny SMTP server for the install drills (INSTALLER.md § 12): accepts
 * mail on 127.0.0.1:<port> and appends each message to <file>, so the web
 * installer's "send a test email" can be exercised without a real mail
 * service. Plain SMTP, no TLS; any AUTH is accepted. Test use only.
 *
 * Usage: php scripts/release/smtp-sink.php <port> <file>
 */

declare(strict_types=1);

if ($argc !== 3 || preg_match('/^\d{2,5}$/D', $argv[1]) !== 1) {
    fwrite(STDERR, "Usage: php smtp-sink.php <port> <file>\n");
    exit(64);
}

[, $port, $file] = $argv;
$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);

if ($server === false) {
    fwrite(STDERR, "Cannot listen on 127.0.0.1:{$port}: {$error}\n");
    exit(1);
}

$reply = static function ($connection, string $line): void {
    fwrite($connection, $line."\r\n");
};

while (true) {
    $connection = @stream_socket_accept($server, -1);

    if ($connection === false) {
        continue;
    }

    stream_set_timeout($connection, 30);
    $reply($connection, '220 goproperty-drill ESMTP');
    $inData = false;
    $authLines = 0;
    $message = '';

    while (($line = fgets($connection)) !== false) {
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                $inData = false;
                file_put_contents($file, $message."\n.\n", FILE_APPEND | LOCK_EX);
                $message = '';
                $reply($connection, '250 2.0.0 Queued');
            } else {
                $message .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            }

            continue;
        }

        if ($authLines > 0) {
            $authLines--;
            $reply($connection, $authLines > 0 ? '334 UGFzc3dvcmQ6' : '235 2.7.0 Authenticated');

            continue;
        }

        $command = strtoupper(trim(substr(trim($line), 0, 4)));

        switch ($command) {
            case 'EHLO':
                fwrite($connection, "250-goproperty-drill\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n");
                break;
            case 'AUTH':
                if (preg_match('/^AUTH\s+LOGIN\s*$/i', trim($line)) === 1) {
                    $authLines = 2;
                    $reply($connection, '334 VXNlcm5hbWU6');
                } elseif (preg_match('/^AUTH\s+PLAIN\s*$/i', trim($line)) === 1) {
                    $authLines = 1;
                    $reply($connection, '334 ');
                } else {
                    $reply($connection, '235 2.7.0 Authenticated');
                }
                break;
            case 'DATA':
                $inData = true;
                $reply($connection, '354 End data with <CR><LF>.<CR><LF>');
                break;
            case 'QUIT':
                $reply($connection, '221 2.0.0 Bye');
                fclose($connection);

                continue 3;
            default:
                $reply($connection, '250 2.0.0 OK');
        }
    }

    @fclose($connection);
}
