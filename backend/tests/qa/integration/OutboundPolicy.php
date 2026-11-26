<?php

namespace DupIssueQa;

use RuntimeException;

final class OutboundPolicy
{
    public static function allows(string $method, string $url, bool $github): bool
    {
        $u = parse_url($url);
        if (! is_array($u) || isset($u['user']) || isset($u['pass']) || isset($u['fragment'])
            || preg_match('/[%\\\\\x00-\x20]/', $url)) {
            return false;
        }
        $host = $u['host'] ?? '';
        $path = $u['path'] ?? '';
        $query = $u['query'] ?? '';
        if (($u['scheme'] ?? '') === 'http' && $host === '127.0.0.1' && ($u['port'] ?? 0) === 18765) {
            return $query === '' && (($method === 'GET' && $path === '/health')
                || ($method === 'POST' && in_array($path, ['/candidates', '/similarity'], true)));
        }
        if (! $github || ($u['scheme'] ?? '') !== 'https' || ($u['port'] ?? 443) !== 443) {
            return false;
        }
        if ($host === 'github.com') {
            return $method === 'POST' && $path === '/login/oauth/access_token' && $query === '';
        }
        if ($host !== 'api.github.com') {
            return false;
        }
        if ($method === 'POST') {
            return $query === '' && preg_match('~\A/app/installations/[1-9][0-9]*/access_tokens\z~', $path) === 1;
        }
        if ($method !== 'GET') {
            return false;
        }
        $repositories = [
            '/repos/valentinokevin/dupissue-testing',
            '/repos/valentinokevin/dupissue-testing-2',
        ];

        if ($path === '/user'
            || preg_match('~\A/app/installations/[1-9][0-9]*\z~', $path)) {
            return $query === '';
        }

        $issueList = false;

        foreach ($repositories as $repo) {
            if ($path === $repo
                || preg_match('~\A'.preg_quote($repo, '~').'/issues/[1-9][0-9]*\z~', $path)) {
                return $query === '';
            }

            if ($path === $repo.'/issues') {
                $issueList = true;
            }
        }

        $list = $path === '/user/installations'
            || preg_match('~\A/user/installations/[1-9][0-9]*/repositories\z~', $path);

        if (! $list && ! $issueList) {
            return false;
        }
        $parameters = [];
        foreach (explode('&', $query) as $pair) {
            if (! preg_match('/\A(per_page|page|state)=([a-z0-9]+)\z/', $pair, $m) || isset($parameters[$m[1]])) {
                return false;
            }
            $parameters[$m[1]] = $m[2];
        }
        return (($parameters['per_page'] ?? '') === '100'
                || (! $list && ($parameters['per_page'] ?? '') === '1' && ($parameters['page'] ?? '') === '1'))
            && preg_match($list ? '/\A1\z/' : '/\A[1-5]\z/', $parameters['page'] ?? '')
            && ($list ? ! isset($parameters['state']) : ($parameters['state'] ?? '') === 'all');
    }

    public static function enforce(string $method, string $url, bool $github): void
    {
        if (! self::allows($method, $url, $github)) {
            // Never include request URL, query, headers or body in exceptions/logs.
            throw new RuntimeException('QA_OUTBOUND_DENIED');
        }
    }
}
