<?php

namespace App\Services;

use RuntimeException;

class GithubAppJwtService
{
    public function generate(): string
    {
        $clientId = config('services.github_app.client_id');
        $keyPath = config('services.github_app.private_key_path');

        if (
            ! is_string($clientId)
            || trim($clientId) === ''
            || ! is_string($keyPath)
            || trim($keyPath) === ''
        ) {
            throw new RuntimeException(
                'GitHub App signing configuration is incomplete.'
            );
        }

        if (! is_file($keyPath) || ! is_readable($keyPath)) {
            throw new RuntimeException(
                'GitHub App private key is unavailable.'
            );
        }

        $pem = @file_get_contents($keyPath);

        if ($pem === false) {
            throw new RuntimeException(
                'Unable to read GitHub App private key.'
            );
        }

        $privateKey = @openssl_pkey_get_private($pem);

        if ($privateKey === false) {
            throw new RuntimeException(
                'GitHub App private key is invalid.'
            );
        }

        $now = time();

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $payload = [
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => trim($clientId),
        ];

        $unsigned = $this->encode($header)
            . '.'
            . $this->encode($payload);

        $signature = '';

        if (! openssl_sign(
            $unsigned,
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256
        )) {
            throw new RuntimeException(
                'Unable to sign GitHub App JWT.'
            );
        }

        return $unsigned . '.'
            . rtrim(
                strtr(base64_encode($signature), '+/', '-_'),
                '='
            );
    }

    private function encode(array $data): string
    {
        return rtrim(
            strtr(
                base64_encode(
                    json_encode($data, JSON_THROW_ON_ERROR)
                ),
                '+/',
                '-_'
            ),
            '='
        );
    }
}
