<?php

declare(strict_types=1);

namespace App\Billing;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * HTTP client middleware: a billing request follows a redirect only within
 * its origin.
 *
 * Billing requests carry an admin key in a header, and the HTTP client
 * re-sends every header except `Authorization` and `Cookie` when it follows a
 * redirect to another host. Rather than track which header carries a
 * credential, a redirect whose target differs from the request in scheme,
 * host or effective port is refused before the client's redirect handling
 * sees the response, so no second request is built and the target receives
 * nothing.
 *
 * Laravel pushes request middleware inside Guzzle's redirect middleware, so
 * this sees the response of every hop.
 */
final class SameOriginRedirects
{
    public static function middleware(): callable
    {
        return static fn (callable $handler): callable => static fn (RequestInterface $request, array $options) => $handler($request, $options)->then(
            static function (ResponseInterface $response) use ($request): ResponseInterface {
                if (! in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true)
                    || ! $response->hasHeader('Location')) {
                    return $response;
                }

                $from = self::origin($request->getUri());
                $to = self::target($request->getUri(), $response->getHeaderLine('Location'));

                if ($to !== null && $to === $from) {
                    return $response;
                }

                $response->getBody()->close();

                throw new BillingUnavailable('redirect_refused');
            }
        );
    }

    /** The origin a Location resolves to, the way Guzzle's redirect handling resolves it. */
    private static function target(UriInterface $base, string $location): ?string
    {
        try {
            return self::origin(UriResolver::resolve($base, new Uri($location)));
        } catch (Throwable) {
            return null;
        }
    }

    private static function origin(UriInterface $uri): ?string
    {
        $scheme = strtolower($uri->getScheme());
        $port = $uri->getPort() ?? match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };

        if ($port === null || $uri->getHost() === '') {
            return null;
        }

        return $scheme.'://'.strtolower($uri->getHost()).':'.$port;
    }
}
