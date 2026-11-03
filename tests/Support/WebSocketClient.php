<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * A minimal WebSocket client (RFC 6455) for the realtime drill: it opens a
 * connection the way a browser's Pusher client does, sends JSON text
 * frames (masked, as clients must) and reads the server's JSON messages,
 * answering pings. Enough of the protocol for Reverb, nothing more.
 */
final class WebSocketClient
{
    private string $buffer = '';

    /** @param resource $socket */
    private function __construct(private $socket) {}

    public static function connect(string $host, int $port, string $path, string $origin, float $timeout = 5.0): self
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $timeout);

        if ($socket === false) {
            throw new RuntimeException("Cannot reach {$host}:{$port}: {$error}");
        }

        stream_set_blocking($socket, false);
        $client = new self($socket);
        $key = base64_encode(random_bytes(16));
        $client->write("GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: {$origin}\r\n\r\n");

        $deadline = microtime(true) + $timeout;

        while (! str_contains($client->buffer, "\r\n\r\n")) {
            $client->fill($deadline) || throw new RuntimeException('No WebSocket handshake answer in time.');
        }

        [$head, $client->buffer] = explode("\r\n\r\n", $client->buffer, 2);

        if (! str_starts_with($head, 'HTTP/1.1 101')) {
            throw new RuntimeException('WebSocket handshake refused: '.strtok($head, "\r\n"));
        }

        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        if (preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $head, $match) !== 1 || $match[1] !== $accept) {
            throw new RuntimeException('WebSocket handshake answer did not match the key.');
        }

        return $client;
    }

    /** @param array<string, mixed> $message */
    public function send(array $message): void
    {
        $this->sendFrame(0x1, (string) json_encode($message, JSON_THROW_ON_ERROR));
    }

    /**
     * The next JSON message within $timeout seconds, or null.
     *
     * @return array<string, mixed>|null
     */
    public function receive(float $timeout): ?array
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            $frame = $this->frame();

            if ($frame === null) {
                if (! $this->fill($deadline)) {
                    return null;
                }

                continue;
            }

            [$opcode, $payload] = $frame;

            if ($opcode === 0x9) {
                $this->sendFrame(0xA, $payload);

                continue;
            }

            if ($opcode === 0x8) {
                throw new RuntimeException('The server closed the connection.');
            }

            if ($opcode === 0x1) {
                $message = json_decode($payload, true);

                return is_array($message) ? $message : null;
            }
        }
    }

    /**
     * Waits for a message whose `event` is $event (others are skipped), or null.
     *
     * @return array<string, mixed>|null
     */
    public function await(string $event, float $timeout): ?array
    {
        $deadline = microtime(true) + $timeout;

        while (($left = $deadline - microtime(true)) > 0) {
            $message = $this->receive($left);

            if ($message !== null && ($message['event'] ?? null) === $event) {
                return $message;
            }
        }

        return null;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @$this->sendFrame(0x8, '');
            fclose($this->socket);
        }
    }

    /** @return array{int, string}|null a whole frame from the buffer */
    private function frame(): ?array
    {
        if (strlen($this->buffer) < 2) {
            return null;
        }

        $opcode = ord($this->buffer[0]) & 0x0F;
        $length = ord($this->buffer[1]) & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if (strlen($this->buffer) < 4) {
                return null;
            }

            $length = unpack('n', substr($this->buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($this->buffer) < 10) {
                return null;
            }

            $length = unpack('J', substr($this->buffer, 2, 8))[1];
            $offset = 10;
        }

        if (strlen($this->buffer) < $offset + $length) {
            return null;
        }

        $payload = substr($this->buffer, $offset, $length);
        $this->buffer = substr($this->buffer, $offset + $length);

        return [$opcode, $payload];
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);

        if ($length < 126) {
            $head .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $head .= chr(0x80 | 126).pack('n', $length);
        } else {
            $head .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);
        $masked = '';

        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        $this->write($head.$mask.$masked);
    }

    private function write(string $bytes): void
    {
        while ($bytes !== '') {
            $written = @fwrite($this->socket, $bytes);

            if ($written === false) {
                throw new RuntimeException('Cannot write to the WebSocket.');
            }

            $bytes = substr($bytes, $written);
        }
    }

    /** Reads what has arrived, waiting until $deadline; false when nothing came in time. */
    private function fill(float $deadline): bool
    {
        $left = $deadline - microtime(true);

        if ($left <= 0) {
            return false;
        }

        $read = [$this->socket];
        $write = $except = null;
        $ready = @stream_select($read, $write, $except, (int) $left, (int) (($left - floor($left)) * 1_000_000));

        if ($ready === false || $ready === 0) {
            return false;
        }

        $chunk = fread($this->socket, 65536);

        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            throw new RuntimeException('The WebSocket connection dropped.');
        }

        $this->buffer .= $chunk;

        return true;
    }
}
