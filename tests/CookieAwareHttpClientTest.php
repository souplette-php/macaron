<?php declare(strict_types=1);

namespace ju1ius\Macaron\Tests;

use ju1ius\Macaron\Cookie;
use ju1ius\Macaron\CookieAwareHttpClient;
use ju1ius\Macaron\CookieJar;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

final class CookieAwareHttpClientTest extends TestCase
{
    public function testWithOptions(): void
    {
        $client = self::createClient(null);
        Assert::assertInstanceOf(CookieAwareHttpClient::class, $client->withOptions([]));
    }

    public function testReset(): void
    {
        $client = new CookieAwareHttpClient($inner = new MockHttpClient(fn() => new MockResponse()));
        $client->request('GET', '/');
        Assert::assertSame(1, $inner->getRequestsCount());
        $client->reset();
        Assert::assertSame(0, $inner->getRequestsCount());
    }

    public function testCookiesOptionCallback(): void
    {
        $cookieFactory = function(string $method, string $url, array $options) {
              Assert::assertSame('POST', $method);
              Assert::assertSame('/a', $url);
              Assert::assertNotEmpty($options);
              return new CookieJar();
        };
        $client = self::createClient(new MockResponse());
        $response = $client->request('POST', '/a', [
            'extra' => ['cookies' => $cookieFactory],
        ]);
        Assert::assertSame(200, $response->getStatusCode());
    }

    public function testRequestWithoutCookies(): void
    {
        // when no cookies are set, the inner client request method is called exactly once.
        $factory = [
            self::redirect(307, '/a'),
            self::redirect(307, '/b'),
            new MockResponse('OK'),
        ];
        $client = new CookieAwareHttpClient($inner = new MockHttpClient($factory));
        $response = $client->request('GET', '/foo');
        Assert::assertSame(307, $response->getStatusCode());
        Assert::assertSame(1, $inner->getRequestsCount());
    }

    public function testRequestWithCookieArray(): void
    {
        $factory = function(string $method, string $url, array $options = []) {
            $cookie = $options['normalized_headers']['cookie'] ?? null;
            switch ($url) {
                case 'http://macaron.test/a':
                    Assert::assertSame(['cookie: foo=bar'], $cookie);
                    return self::redirect(307, 'http://macaron.test/b', [new Cookie('a', '1')]);
                case 'http://macaron.test/b':
                    Assert::assertSame(['cookie: foo=bar; a=1'], $cookie);
                    return self::redirect(302, 'http://macaron.test/c', [new Cookie('b', '1')]);
                case 'http://macaron.test/c':
                    Assert::assertSame(['cookie: foo=bar; a=1; b=1'], $cookie);
                    return new MockResponse('OK');
                default:
                    return null;
            }
        };
        $client = self::createClient($factory);
        $response = $client->request('GET', '/a', [
            'max_redirects' => 5,
            'extra' => ['cookies' => ['foo' => 'bar']],
        ]);
        Assert::assertSame(200, $response->getStatusCode());
        Assert::assertSame('OK', $response->getContent());
    }

    public function testRequestWithCookieJar(): void
    {
        $jar = new CookieJar();
        $factory = function() use ($jar) {
            Assert::assertEmpty($jar->all());
            yield self::redirect(307, 'http://macaron.test/b', [new Cookie('a', '1')]);
            yield self::redirect(302, 'http://macaron.test/c', [new Cookie('b', '1')]);
            yield new MockResponse('OK');
        };
        $client = self::createClient($factory());
        $response = $client->request('GET', '/', ['extra' => ['cookies' => $jar]]);
        Assert::assertSame(200, $response->getStatusCode());

        $expected = [
            new Cookie('a', '1', path: '/', domain: 'macaron.test'),
            new Cookie('b', '1', path: '/', domain: 'macaron.test'),
        ];
        Assert::assertEquals($expected, $jar->all());
    }

    public function testStream(): void
    {
        $factory = function() {
            yield self::redirect(307, 'http://macaron.test/b');
            yield new MockResponse('KO', [
                'http_code' => 302,
                'redirect_url' => 'http://macaron.test/c',
            ]);
            yield new MockResponse('OK');
        };
        $client = self::createClient($factory());
        $response = $client->request('GET', '/', ['extra' => ['cookies' => new CookieJar()]]);
        Assert::assertSame(200, $response->getStatusCode());
        $body = '';
        foreach ($client->stream($response) as $chunk) {
            $body .= $chunk->getContent();
        }
        Assert::assertSame('OK', $body);
    }

    public function testItFailsWhenMissingLocationHeader(): void
    {
        $client = self::createClient(fn() => new MockResponse('', ['http_code' => 307]));
        $this->expectException(RedirectionExceptionInterface::class);
        $response = $client->request('GET', '/foo', [
            'extra' => ['cookies' => ['a' => 'b']],
        ]);
        $response->getHeaders();
    }

    public function testItFailsWhenTooManyRedirects(): void
    {
        $client = self::createClient([
            self::redirect(307, '/a'),
            self::redirect(307, '/b'),
        ]);
        $this->expectException(RedirectionExceptionInterface::class);
        $client->request('GET', '/foo', [
            'max_redirects' => 1,
            'extra' => ['cookies' => ['a' => 'b']],
        ]);
    }

    public function testItSkipsInvalidCookies(): void
    {
        $client = self::createClient(function(string $method, string $url, array $options = []) {
            $cookie = $options['normalized_headers']['cookie'] ?? null;
            Assert::assertSame(['cookie: foo=bar'], $cookie);
            return new MockResponse('OK');
        });
        $client->request('GET', '/', [
            'extra' => [
                'cookies' => [
                    'foo' => 'bar',
                    'bar' => new class {
                    },
                ],
            ],
        ]);
    }

    public function test304(): void
    {
        $factory = [
            new MockResponse('', ['http_code' => 304])
        ];
        $client = self::createClient($factory);
        $response = $client->request('GET', '/foo', [
            'extra' => ['cookies' => new CookieJar()],
        ]);
        Assert::assertSame(304, $response->getStatusCode());
    }

    /**
     * @dataProvider provideAuth
     */
    public function testAuth(array $options, string $expected): void
    {
        $factory = function(string $method, string $url, array $options = []) use ($expected) {
            $auth = $options['normalized_headers']['authorization'][0] ?? null;
            switch ($url) {
                case 'http://macaron.test/a':
                    Assert::assertSame($expected, $auth);
                    return self::redirect(302, 'http://example.com/b');
                case 'http://example.com/b':
                    Assert::assertNull($auth);
                    return self::redirect(307, 'http://macaron.test/c');
                default:
                    Assert::assertSame($expected, $auth);
                    return new MockResponse();
            }
        };
        $client = self::createClient($factory);
        $response = $client->request('GET', '/a', [
            ...$options,
            'extra' => ['cookies' => new CookieJar()],
        ]);
        Assert::assertSame(200, $response->getStatusCode());
    }

    public static function provideAuth(): iterable
    {
        yield 'auth_basic' => [
            ['auth_basic' => 'foo:bar'],
            'Authorization: Basic Zm9vOmJhcg==',
        ];
        yield 'auth_bearer' => [
            ['auth_bearer' => 'foo:bar'],
            'Authorization: Bearer foo:bar',
        ];
        yield 'Authorization header' => [
            ['headers' => ['Authorization' => 'Bearer foo:bar']],
            'Authorization: Bearer foo:bar',
        ];
    }

    /**
     * @dataProvider redirectChangesRequestMethodProvider
     */
    public function testRedirectChangesRequestMethod(string $method, int $statusCode, string $expected): void
    {
        $factory = function(string $method, string $url) use ($statusCode, $expected) {
            switch ($url) {
                case 'http://macaron.test/a':
                    return self::redirect($statusCode, '/b');
                default:
                    Assert::assertSame($expected, $method);
                    return new MockResponse();
            }
        };
        $client = self::createClient($factory);
        $response = $client->request($method, '/a', [
            'extra' => ['cookies' => new CookieJar()],
        ]);
        Assert::assertSame(200, $response->getStatusCode());
    }

    public static function redirectChangesRequestMethodProvider(): iterable
    {
        foreach ([301, 302, 303] as $code) {
            yield "HEAD => {$code} => HEAD" => ['HEAD', $code, 'HEAD'];
            yield "GET => {$code} => GET" => ['GET', $code, 'GET'];
            yield "POST => {$code} => GET" => ['POST', $code, 'GET'];
        }
    }

    public function testSetLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $inner = new class($this) extends MockHttpClient implements LoggerAwareInterface {
            public function __construct(private TestCase $test)
            {
                parent::__construct();
            }

            public function setLogger(LoggerInterface $logger): void
            {
                $this->test->addToAssertionCount(1);
            }
        };
        $client = new CookieAwareHttpClient($inner);
        $client->setLogger($logger);
    }

    private static function createClient($factory): CookieAwareHttpClient
    {
        $client = new MockHttpClient($factory, 'http://macaron.test');
        return new CookieAwareHttpClient($client);
    }

    /**
     * @param Cookie[] $cookies
     */
    private static function redirect(int $code, string $location, array $cookies = []): MockResponse
    {
        if (!parse_url($location, \PHP_URL_HOST)) {
            $location = "http://macaron.test{$location}";
        }
        return new MockResponse('', [
            'http_code' => $code,
            'redirect_url' => $location,
            'response_headers' => [
                'location' => $location,
                'set-cookie' => array_map(fn($c) => (string)$c, $cookies),
            ],
        ]);
    }
}
