<?php

namespace Tests\Unit;

use DupIssueQa\OutboundPolicy;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../qa/integration/OutboundPolicy.php';

final class IntegrationQaPolicyTest extends TestCase
{
    public function test_local_nlp_is_exact_and_external_disabled_by_default(): void
    {
        $this->assertTrue(OutboundPolicy::allows('GET', 'http://127.0.0.1:18765/health', false));
        $this->assertTrue(OutboundPolicy::allows('POST', 'http://127.0.0.1:18765/candidates', false));
        $this->assertFalse(OutboundPolicy::allows('GET', 'https://api.github.com/user', false));
        $this->assertFalse(OutboundPolicy::allows('POST', 'http://127.0.0.1:18765/admin', false));
    }

    public function test_audited_github_methods_paths_and_pagination(): void
    {
        foreach ([['POST', 'https://github.com/login/oauth/access_token'], ['GET', 'https://api.github.com/user'],
            ['GET', 'https://api.github.com/user/installations?per_page=100&page=1'],
            ['GET', 'https://api.github.com/user/installations/12/repositories?per_page=100&page=1'],
            ['GET', 'https://api.github.com/app/installations/12'], ['POST', 'https://api.github.com/app/installations/12/access_tokens'],
            ['GET', 'https://api.github.com/repos/valentinokevin/dupissue-testing'],
            ['GET', 'https://api.github.com/repos/valentinokevin/dupissue-testing/issues/7'],
            ['GET', 'https://api.github.com/repos/valentinokevin/dupissue-testing/issues?state=all&per_page=100&page=5'],
            ['GET', 'https://api.github.com/repos/valentinokevin/dupissue-testing/issues?state=all&per_page=1&page=1']] as [$method, $url]) {
            $this->assertTrue(OutboundPolicy::allows($method, $url, true), $url);
        }
    }

    public function test_host_url_parser_and_repository_bypasses_rejected(): void
    {
        foreach (['https://api.github.com.evil.test/user', 'https://api.github.com@evil.test/user',
            'https://x@api.github.com/user', 'https://api.github.com:444/user', 'http://api.github.com/user',
            'https://api.github.com/user#fragment', 'https://api.github.com/user?token=secret',
            'https://api.github.com/repos/another/repo/issues', 'https://api.github.com/repos/valentinokevin/dupissue-testing/../other',
            'https://api.github.com/%75ser', 'https://api.github.com/user\\evil',
            'http://localhost:18765/health', 'http://127.0.0.1:8000/health',
            'https://api.github.com/user/installations?per_page=100&page=2',
            'https://api.github.com/user/installations?per_page=100&page=1&page=1',
            'https://api.github.com/repos/valentinokevin/dupissue-testing/issues?state=all&per_page=100&page=6',
            'https://api.github.com/app/installations/0', 'file:///tmp/test'] as $url) {
            $this->assertFalse(OutboundPolicy::allows('GET', $url, true), $url);
        }
        $this->assertFalse(OutboundPolicy::allows('POST', 'https://api.github.com/repos/valentinokevin/dupissue-testing/issues', true));
        $this->assertFalse(OutboundPolicy::allows('DELETE', 'https://api.github.com/repos/valentinokevin/dupissue-testing', true));
    }

    public function test_second_repository_read_access(): void
    {
        $repo = 'https://api.github.com/repos/valentinokevin/dupissue-testing-2';

        foreach ([
            $repo,
            $repo.'/issues/1',
            $repo.'/issues?state=all&per_page=100&page=1',
            $repo.'/issues?state=all&per_page=100&page=5',
            $repo.'/issues?state=all&per_page=1&page=1',
        ] as $url) {
            $this->assertTrue(
                OutboundPolicy::allows('GET', $url, true),
                $url
            );

            $this->assertFalse(
                OutboundPolicy::allows('GET', $url, false),
                $url
            );
        }
    }

    public function test_second_repository_security_boundaries(): void
    {
        $repo = 'https://api.github.com/repos/valentinokevin/dupissue-testing-2';

        foreach ([
            $repo.'-other',
            $repo.'0',
            $repo.'-other/issues/1',
            $repo.'/../other',
            $repo.'/issues/0',
            $repo.'/issues/01',
            $repo.'/issues/1/extra',
            $repo.'/issues/1?extra=1',
            $repo.'/issues?state=all&per_page=100&page=6',
            $repo.'/issues?state=open&per_page=100&page=1',
            $repo.'/issues?state=all&per_page=101&page=1',
            $repo.'/issues?state=all&per_page=1&page=2',
            $repo.'/issues?state=all&per_page=100&page=1&page=1',
            $repo.'/issues?state=all&per_page=100&page=1&extra=1',
            $repo.'/contents/README.md',
            $repo.'/pulls/1',
            $repo.'/issues%2f1',
            $repo.'/issues/1#fragment',
        ] as $url) {
            $this->assertFalse(
                OutboundPolicy::allows('GET', $url, true),
                $url
            );
        }

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach ([$repo, $repo.'/issues', $repo.'/issues/1'] as $url) {
                $this->assertFalse(
                    OutboundPolicy::allows($method, $url, true),
                    $method.' '.$url
                );
            }
        }
    }

    public function test_denied_exception_never_contains_request_data(): void
    {
        $this->expectExceptionMessage('QA_OUTBOUND_DENIED');
        OutboundPolicy::enforce('GET', 'https://evil.test/?secret=PRIVATE', true);
    }
}
