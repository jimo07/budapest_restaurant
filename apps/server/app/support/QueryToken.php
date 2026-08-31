<?php

declare(strict_types=1);

namespace app\support;

final class QueryToken
{
    public static function generate(): string { return bin2hex(random_bytes(24)); }
    public static function hash(string $token): string { return hash('sha256', $token); }
    public static function verify(string $token, string $hash): bool { return hash_equals($hash, self::hash($token)); }
}
