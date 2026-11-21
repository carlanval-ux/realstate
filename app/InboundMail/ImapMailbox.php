<?php

declare(strict_types=1);

namespace App\InboundMail;

use LogicException;
use RuntimeException;

/**
 * Minimal IMAP4rev1 client, STRICTLY read-only (the image has no ext-imap and Composer adds nothing):
 * the folder is opened with EXAMINE (read-only session: the server never sets \Seen) and bodies are fetched with
 * BODY.PEEK[]. Every command goes through an allow-list, so STORE, COPY, MOVE, EXPUNGE, APPEND, SELECT… cannot be
 * sent even by mistake. The password is never kept in $sent.
 */
final class ImapMailbox
{
    /** The only commands this client can send. */
    private const ALLOWED = '/^(CAPABILITY|NOOP|LOGOUT|LOGIN "[^\r\n]*" "[^\r\n]*"|EXAMINE "[^\r\n]*"|UID SEARCH (UID \d+:\*|SINCE \d{1,2}-[A-Z][a-z]{2}-\d{4}|ALL)|UID FETCH \d+ \(BODY\.PEEK\[\]\))$/';

    private int $tag = 0;

    /** @var list<string> commands sent (password masked), for tests and diagnostics */
    public array $sent = [];

    /** @param resource $stream */
    public function __construct(private $stream)
    {
        $greeting = $this->line();
        if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
            throw new RuntimeException('IMAP: unexpected greeting.');
        }
    }

    public static function connect(string $host, int $port, int $timeout = 30): self
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $stream = @stream_socket_client("ssl://{$host}:{$port}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new RuntimeException("IMAP: cannot connect to {$host}:{$port} ({$error}).");
        }
        stream_set_timeout($stream, $timeout);

        return new self($stream);
    }

    public function login(string $user, string $password): void
    {
        $this->command('LOGIN '.self::quote($user).' '.self::quote($password), 'LOGIN '.self::quote($user).' "***"');
    }

    /** @return array{uidvalidity: int, uidnext: int, exists: int} */
    public function examine(string $folder): array
    {
        $info = ['uidvalidity' => 0, 'uidnext' => 0, 'exists' => 0];
        foreach ($this->command('EXAMINE '.self::quote($folder))['lines'] as $line) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m)) {
                $info['uidvalidity'] = (int) $m[1];
            } elseif (preg_match('/\[UIDNEXT (\d+)\]/i', $line, $m)) {
                $info['uidnext'] = (int) $m[1];
            } elseif (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) {
                $info['exists'] = (int) $m[1];
            }
        }

        return $info;
    }

    /** @return list<int> UIDs >= $from (ascending) */
    public function uidsFrom(int $from): array
    {
        return $this->search('UID '.max(1, $from).':*', $from);
    }

    /** @return list<int> UIDs of messages received on or after that day */
    public function uidsSince(\DateTimeInterface $day): array
    {
        return $this->search('SINCE '.$day->format('j-M-Y'));
    }

    /** The whole message (RFC 822), without changing its flags. */
    public function fetch(int $uid): ?string
    {
        return $this->command('UID FETCH '.$uid.' (BODY.PEEK[])')['literals'][0] ?? null;
    }

    public function logout(): void
    {
        rescue(fn () => $this->command('LOGOUT'), report: false);
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /** @return list<int> */
    private function search(string $criteria, int $min = 0): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH '.$criteria)['lines'] as $line) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $line, $m)) {
                array_push($uids, ...array_map('intval', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)));
            }
        }
        // «n:*» always returns the last message, even when its UID is lower than n.
        $uids = array_values(array_unique(array_filter($uids, fn (int $u) => $u >= $min)));
        sort($uids);

        return $uids;
    }

    /** @return array{lines: list<string>, literals: list<string>} */
    private function command(string $command, ?string $shown = null): array
    {
        if (! preg_match(self::ALLOWED, $command)) {
            throw new LogicException('IMAP: command not allowed (read-only client): '.strtok($command, ' '));
        }
        $tag = 'A'.(++$this->tag);
        $this->sent[] = $tag.' '.($shown ?? $command);
        if (fwrite($this->stream, $tag.' '.$command."\r\n") === false) {
            throw new RuntimeException('IMAP: connection lost.');
        }
        $lines = $literals = [];
        while (true) {
            $line = $this->line();
            // A literal {n}: the next n bytes are data (the message), then the line continues.
            while (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $literals[] = $this->bytes((int) $m[1]);
                $line = substr($line, 0, -strlen($m[0])).'{}'.$this->line();
            }
            if (str_starts_with($line, $tag.' ')) {
                if (! preg_match('/^'.$tag.' OK/i', $line)) {
                    throw new RuntimeException('IMAP: '.mb_strimwidth(substr($line, strlen($tag) + 1), 0, 200, '…'));
                }

                return ['lines' => $lines, 'literals' => $literals];
            }
            $lines[] = $line;
        }
    }

    private function line(): string
    {
        $line = fgets($this->stream);
        if ($line === false) {
            throw new RuntimeException('IMAP: connection closed or timed out.');
        }

        return rtrim($line, "\r\n");
    }

    private function bytes(int $n): string
    {
        $data = '';
        while (strlen($data) < $n) {
            $chunk = fread($this->stream, min(65536, $n - strlen($data)));
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                throw new RuntimeException('IMAP: connection closed while reading a message.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private static function quote(string $s): string
    {
        return '"'.addcslashes($s, '"\\').'"';
    }
}
