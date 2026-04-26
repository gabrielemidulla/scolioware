<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/** HTTP client for inference `/internal/*`. */
final class InferenceInternalGateway
{
    private static ?self $instance = null;

    private readonly HttpClientInterface $http;

    private function __construct(string $baseUri)
    {
        $this->http = HttpClient::createForBaseUri($baseUri, [
            'max_redirects' => 0,
        ]);
    }

    public static function instance(): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $base = rtrim(getenv('INFERENCE_INTERNAL_URL') ?: 'http://inference:8000', '/') . '/';
        self::$instance = new self($base);

        return self::$instance;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getJson(string $path, float $timeoutSeconds = 20.0): ?array
    {
        $path = ltrim($path, '/');
        try {
            $response = $this->http->request('GET', $path, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => $timeoutSeconds,
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return null;
        }

        $decoded = json_decode($response->getContent(false), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function postJson(string $path, array $body, float $timeoutSeconds = 120.0): ?InferencePostResult
    {
        $encoded = json_encode($body);
        if ($encoded === false) {
            return null;
        }

        $path = ltrim($path, '/');
        try {
            $response = $this->http->request('POST', $path, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => $encoded,
                'timeout' => $timeoutSeconds,
            ]);
        } catch (Throwable) {
            return null;
        }

        $decoded = json_decode($response->getContent(false), true);
        if (!is_array($decoded)) {
            return null;
        }

        return new InferencePostResult($response->getStatusCode(), $decoded);
    }
}
