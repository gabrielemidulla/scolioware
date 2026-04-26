<?php

declare(strict_types=1);

/**
 * Server-to-server calls to the inference app (R2 objects, report JSON) with {@see INFERENCE_INTERNAL_TOKEN}.
 */
function sv_inference_internal_token(): string
{
    $t = getenv('INFERENCE_INTERNAL_TOKEN');
    return is_string($t) ? $t : '';
}

/**
 * @return list<string> curl_httpheader lines
 */
function sv_inference_curl_headers(): array
{
    $tok = sv_inference_internal_token();
    $h = [
        'Accept: application/json',
    ];
    if ($tok !== '') {
        $h[] = 'X-Internal-Token: ' . $tok;
    }
    return $h;
}

/**
 * Internal binary routes (R2); no JSON Accept.
 *
 * @return list<string>
 */
function sv_inference_curl_headers_binary(): array
{
    $h = [];
    $tok = sv_inference_internal_token();
    if ($tok !== '') {
        $h[] = 'X-Internal-Token: ' . $tok;
    }
    return $h;
}

/**
 * GET JSON from inference (e.g. /reports/1).
 * @return array<string, mixed>|null
 */
function sv_inference_get_json(string $path): ?array
{
    $base = rtrim(getenv('INFERENCE_INTERNAL_URL') ?: 'http://inference:8000', '/');
    $url = $base . ($path[0] === '/' ? $path : '/' . $path);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => sv_inference_curl_headers(),
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }
    $json = json_decode((string) $body, true);
    return is_array($json) ? $json : null;
}

/**
 * GET binary from inference internal file routes.
 */
function sv_inference_get_bytes(string $path, int $timeout = 60): ?string
{
    $base = rtrim(getenv('INFERENCE_INTERNAL_URL') ?: 'http://inference:8000', '/');
    $url = $base . ($path[0] === '/' ? $path : '/' . $path);
    $ch = curl_init($url);
    $hdr = sv_inference_curl_headers_binary();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $hdr !== [] ? $hdr : ['Accept: */*'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }
    return is_string($body) ? $body : null;
}

/**
 * POST JSON to an inference path (e.g. /internal/reports/1/recompute-landmarks).
 * @param array<string, mixed> $body
 * @return array<string, mixed>|null
 */
function sv_inference_post_json(string $path, array $body, int $timeout = 120): ?array
{
    $base = rtrim(getenv('INFERENCE_INTERNAL_URL') ?: 'http://inference:8000', '/');
    $url = $base . ($path[0] === '/' ? $path : '/' . $path);
    $ch = curl_init($url);
    $payload = json_encode($body);
    if ($payload === false) {
        return null;
    }
    $h = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $tok = sv_inference_internal_token();
    if ($tok !== '') {
        $h[] = 'X-Internal-Token: ' . $tok;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $h,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) {
        return null;
    }
    $json = json_decode((string) $resp, true);
    if (!is_array($json)) {
        return null;
    }
    $json['__http'] = $code;
    return $json;
}

