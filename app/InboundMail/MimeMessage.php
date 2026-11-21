<?php

declare(strict_types=1);

namespace App\InboundMail;

/**
 * Small RFC 822 / MIME reader for what triage needs: decoded headers, the text and HTML bodies (UTF-8) and the
 * attachments. Nested multiparts are walked; an attached message (message/rfc822) is kept as an .eml attachment.
 */
final class MimeMessage
{
    /** @var array<string, list<string>> lower-case name => raw (unfolded) values */
    public array $headers = [];

    public string $text = '';

    public string $html = '';

    /** @var list<array{name: string, type: string, content: string}> */
    public array $attachments = [];

    public static function parse(string $raw): self
    {
        $message = new self;
        [$message->headers, $body] = self::split($raw);
        $message->walk($message->headers, $body, 0);
        if (trim($message->text) === '' && $message->html !== '') {
            $message->text = self::htmlToText($message->html);
        }

        return $message;
    }

    /** First value of a header, RFC 2047 decoded. */
    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)][0] ?? null;

        return $value === null ? null : self::decodeWords($value);
    }

    /** @return array{0: ?string, 1: ?string} [address (lower case), display name] */
    public function from(): array
    {
        $from = (string) $this->header('From');
        $email = preg_match('/<([^<>\s]+@[^<>\s]+)>/', $from, $m) ? $m[1] : (preg_match('/[^\s<>"]+@[^\s<>"]+/', $from, $m) ? $m[0] : null);
        $name = trim((string) preg_replace('/<[^>]*>/', '', $from), " \t\"'");

        return [$email === null ? null : strtolower(trim($email, '.,;')), $name !== '' && $name !== $email ? $name : null];
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace(['#<(script|style|head)\b.*?</\1>#is', '#<br\s*/?>#i', '#</(p|div|tr|li|h[1-6]|blockquote)>#i'], ['', "\n", "\n"], $html) ?? $html;

        return trim((string) preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** @return array{0: array<string, list<string>>, 1: string} */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        [$head, $body] = $pos === false ? [$raw, ''] : [substr($raw, 0, $pos), substr($raw, $pos + 2)];
        $headers = [];
        foreach (preg_split('/\n(?![ \t])/', $head) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim((string) preg_replace('/\n[ \t]+/', ' ', $value));
            }
        }

        return [$headers, $body];
    }

    /** @param array<string, list<string>> $headers */
    private function walk(array $headers, string $body, int $depth): void
    {
        [$type, $params] = self::params($headers['content-type'][0] ?? 'text/plain');
        [$disposition, $dParams] = self::params($headers['content-disposition'][0] ?? '');
        $name = $dParams['filename'] ?? $params['name'] ?? null;

        if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $depth < 10) {
            $parts = explode("\n--".$params['boundary'], "\n".$body);
            array_shift($parts);   // preamble
            foreach ($parts as $part) {
                if (str_starts_with($part, '--')) {
                    break;   // closing boundary
                }
                [$h, $b] = self::split((string) substr($part, strcspn($part, "\n") + 1));
                $this->walk($h, $b, $depth + 1);
            }

            return;
        }

        $content = match (strtolower(trim($headers['content-transfer-encoding'][0] ?? ''))) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        $isText = in_array($type, ['text/plain', 'text/html'], true);
        if ($disposition === 'attachment' || $name !== null || ! $isText) {
            if ($type === 'message/delivery-status' || $type === 'message/disposition-notification') {
                $this->text .= ($this->text === '' ? '' : "\n").self::utf8($content, 'UTF-8');
            }
            $this->attachments[] = ['name' => self::safeName($name ?? ($type === 'message/rfc822' ? 'mensaje.eml' : 'adjunto')), 'type' => $type, 'content' => $content];

            return;
        }
        $content = self::utf8($content, $params['charset'] ?? 'UTF-8');
        if ($type === 'text/html') {
            $this->html .= $content;
        } else {
            $this->text .= ($this->text === '' ? '' : "\n").$content;
        }
    }

    /** @return array{0: string, 1: array<string, string>} lower-case value and parameters (RFC 2231 decoded) */
    private static function params(string $header): array
    {
        $parts = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $header) ?: [];
        $value = strtolower(trim((string) array_shift($parts)));
        $params = [];
        foreach ($parts as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $part, 2);
            $k = strtolower(trim($k));
            $v = trim(trim($v), '"');
            if (preg_match('/^(\w+)\*(?:\d+)?(\*?)$/', $k, $m)) {   // filename*=UTF-8''n%C3%BA.pdf, filename*0*=…, filename*1=…
                $encoded = $m[2] === '*' || $k === $m[1].'*';
                if (preg_match("/^([\w-]+)'[^']*'(.*)$/", $v, $e)) {
                    $v = self::utf8(rawurldecode($e[2]), $e[1]);
                } elseif ($encoded) {
                    $v = rawurldecode($v);
                }
                $params[$m[1]] = ($params[$m[1]] ?? '').$v;
            } else {
                $params[$k] = self::decodeWords($v);
            }
        }

        return [$value, $params];
    }

    private static function decodeWords(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return self::utf8($value, 'UTF-8');
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return self::utf8($decoded === false ? $value : $decoded, 'UTF-8');
    }

    private static function utf8(string $s, string $charset): string
    {
        $charset = strtoupper(trim($charset)) ?: 'UTF-8';
        if (! in_array($charset, ['UTF-8', 'US-ASCII', 'ASCII'], true)) {
            try {
                $s = mb_convert_encoding($s, 'UTF-8', $charset);
            } catch (\ValueError) {
                $s = (string) @iconv($charset, 'UTF-8//IGNORE', $s);
            }
        }

        return mb_scrub($s, 'UTF-8');
    }

    private static function safeName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/', '_', basename(str_replace('\\', '/', $name))), ' ._');

        return mb_substr($name !== '' ? $name : 'adjunto', 0, 150);
    }
}
