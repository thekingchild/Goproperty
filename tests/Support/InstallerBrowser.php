<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Walks the web installer like a browser (P01-09): keeps the cookies each
 * response sets (as sent, still encrypted), sends the page's CSRF token and
 * an Origin header, and starts every request with no session in memory, so
 * only the cookie carries the installer session from one request to the
 * next, as in production.
 */
final class InstallerBrowser
{
    /** @var array<string, string> */
    public array $cookies = [];

    private string $page = '';

    public function __construct(
        private readonly TestCase $test,
        private readonly Application $app,
        private readonly string $origin = 'http://localhost',
    ) {}

    /** @return TestResponse<Response> */
    public function get(string $uri): TestResponse
    {
        return $this->send('GET', $uri);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $server  extra $_SERVER entries (headers as HTTP_*)
     * @return TestResponse<Response>
     */
    public function post(string $uri, array $data = [], array $server = []): TestResponse
    {
        $token = $this->token();

        if ($token !== null && ! array_key_exists('_token', $data)) {
            $data['_token'] = $token;
        }

        return $this->send('POST', $uri, $data, $server + ['HTTP_ORIGIN' => $this->origin]);
    }

    /**
     * Follows redirects with GET, as a browser does after a 303.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    public function follow(TestResponse $response, int $limit = 10): TestResponse
    {
        while ($response->isRedirect() && $limit-- > 0) {
            $response = $this->get((string) $response->headers->get('Location'));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $server
     * @return TestResponse<Response>
     */
    public function submit(string $uri, array $data = [], array $server = []): TestResponse
    {
        return $this->follow($this->post($uri, $data, $server));
    }

    public function page(): string
    {
        return $this->page;
    }

    /** The CSRF token in the last page's forms. */
    public function token(): ?string
    {
        return preg_match('/name="_token" value="([^"]+)"/', $this->page, $match) === 1 ? html_entity_decode($match[1]) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $server
     * @return TestResponse<Response>
     */
    private function send(string $method, string $uri, array $data = [], array $server = []): TestResponse
    {
        $app = $this->app;
        $app->make('session')->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->make('auth')->forgetGuards();

        $started = time();
        $response = $this->test->call($method, $uri, $data, $this->cookies, [], $server + ['HTTP_ACCEPT' => 'text/html']);
        $this->stampSessionFiles($started);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared() || $cookie->getValue() === null || $cookie->getValue() === '') {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        $content = $response->baseResponse->getContent();

        if (! $response->isRedirect() && is_string($content) && $content !== '') {
            $this->page = $content;
        }

        return $response;
    }

    /**
     * File sessions expire by the file's modification time, which the
     * tests' time travel does not move: stamp the session files this
     * request wrote with the (travelled) current time.
     */
    private function stampSessionFiles(int $since): void
    {
        $directory = config('installer.session_files');

        if (! is_string($directory) || ! is_dir($directory)) {
            return;
        }

        clearstatcache();

        foreach (glob($directory.'/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) >= $since - 1) {
                touch($file, now()->getTimestamp());
            }
        }
    }
}
