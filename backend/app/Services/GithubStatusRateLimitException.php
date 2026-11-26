<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class GithubStatusRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(
            'GitHub API rate limit exceeded. Please try again later.',
            429
        );
    }

    public static function fromResponse(Response $response): self
    {
        $delay = 60;

        $retry = trim((string) $response->header('Retry-After'));

        if ($retry !== '') {
            if (ctype_digit($retry)) {
                $delay = max($delay, min(31536000, (int) $retry));
            } else {
                $time = strtotime($retry);
                if ($time !== false) {
                    $delay = max($delay, $time - time());
                }
            }
        }

        if ($response->header('X-RateLimit-Remaining') === '0') {
            $reset = trim((string) $response->header('X-RateLimit-Reset'));

            if (ctype_digit($reset)) {
                $delay = max($delay, (int) $reset - time());
            }
        }

        return new self($delay);
    }
}
