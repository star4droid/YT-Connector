<?php
/**
 * Symmetric encryption for secrets stored in the database (client secrets, refresh tokens).
 * Uses AES-256-GCM with the app key from data/config.php.
 */
declare(strict_types=1);

function app_key(): string
{
    $k = (string)config('app_key', '');
    if ($k === '') throw new RuntimeException('Missing app_key in config');
    return hash('sha256', $k, true);
}

function encrypt_str(?string $plain): ?string
{
    if ($plain === null || $plain === '') return $plain;
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Encryption failed');
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_str(?string $enc): ?string
{
    if ($enc === null || $enc === '') return $enc;
    if (strncmp($enc, 'v1:', 3) !== 0) return $enc; // legacy/plain
    $raw = base64_decode(substr($enc, 3), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

function random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}
