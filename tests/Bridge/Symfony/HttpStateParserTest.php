<?php declare(strict_types=1);

namespace ju1ius\Macaron\Tests\Bridge\Symfony;

use ju1ius\Macaron\Bridge\Symfony\MacaronHttpClient;
use ju1ius\Macaron\Tests\WebPlatformTests\HttpState\HttpStateTestDto;
use ju1ius\Macaron\Tests\WebPlatformTests\HttpState\HttpStateTestProvider;
use ju1ius\Macaron\Uri\UriService;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpStateParserTest extends TestCase
{
    private static function getClock(): ClockInterface
    {
        static $clock;
        return $clock ??= new MockClock('now', 'UTC');
    }

    private function createClient(): MacaronHttpClient
    {
        return new MacaronHttpClient(
            new MockHttpClient($this->handleRequest(...)),
            new UriService(),
            self::getClock(),
        );
    }

    #[DataProvider('provideHttpState')]
    public function testHttpState(HttpStateTestDto $test): void
    {
        if ($skipReason = $test->skip) {
            self::markTestSkipped($skipReason);
        }
        $client = $this->createClient();
        $response = $client->request('GET', $test->uri, [
            'extra' => [
                'test-case' => $test,
                'cookies' => [],
            ]
        ]);
        Assert::assertSame(200, $response->getStatusCode());
    }

    public static function provideHttpState(): iterable
    {
        foreach (HttpStateTestProvider::provideTestCases() as $name => $test) {
            $test->skip = match ($test->id) {
                '0026', '0028' => 'Symfony strips whitespace from headers.',
                'disabled-path0029' => 'Requires percent-decoding the request URL path.',
                default => null,
            };
            yield $name => [$test];
        }
    }

    private function handleRequest(string $method, string $url, array $options = []): ResponseInterface
    {
        $uri = parse_url($url);
        /** @var HttpStateTestDto $test */
        $test = $options['extra']['test-case'];
        $path = explode('/', trim($uri['path'], '/'));
        switch ($path[0]) {
            case 'cookie-parser':
                return new MockResponse('', [
                    'http_code' => 302,
                    'redirect_url' => $test->redirectUri,
                    'response_headers' => [
                        'location' => $test->redirectUri,
                        'set-cookie' => $test->setCookie,
                    ],
                ]);
            case 'cookie-parser-result':
                $cookieLine = $options['normalized_headers']['cookie'][0] ?? '';
                $expected = $test->expected;
                Assert::assertSame("cookie: {$expected}", $cookieLine);
                return new MockResponse('', [
                    'http_code' => 200,
                ]);
            default:
                return new MockResponse('', [
                    'http_code' => 404,
                ]);
        }
    }
}
