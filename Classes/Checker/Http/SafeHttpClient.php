<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker\Http;

use Aistea\AisteaSeo\Checker\UrlTools;

/**
 * Native cURL client for fetching visitor-supplied URLs.
 *
 * - every URL and every redirect hop is validated by UrlGuard
 * - the validated IP is pinned with CURLOPT_RESOLVE (no DNS rebinding)
 * - redirects are followed manually, bodies are size-limited, proxies disabled
 */
final class SafeHttpClient
{
    public const USER_AGENT = 'Mozilla/5.0 (compatible; AIsteaSEOChecker/1.0; +https://aistea.me/seo-checker)';

    private const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly UrlGuard $guard) {}

    /**
     * GET with manually followed, re-validated redirects.
     */
    public function fetch(string $url, int $maxRedirects = 5, array $options = []): HttpResult
    {
        $current = $url;
        $redirects = [];

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $result = $this->request($current, $options + ['certInfo' => $hop === 0 && ($options['certInfo'] ?? false)]);
            if (!$result->isRedirect() || $result->error !== '') {
                return $result->withRedirects($redirects, $url);
            }

            $location = trim($result->header('location'));
            if ($location === '') {
                return $result->withRedirects($redirects, $url);
            }

            $redirects[] = ['url' => $current, 'status' => $result->status];
            $current = UrlTools::resolve($current, $location);
        }

        return (new HttpResult($url, $current, 0, [], '', 'tooManyRedirects'))->withRedirects($redirects, $url);
    }

    /**
     * Single request, redirects are not followed.
     *
     * @param array{method?: string, accept?: string, maxBytes?: int, timeout?: int, certInfo?: bool} $options
     */
    public function request(string $url, array $options = []): HttpResult
    {
        try {
            $target = $this->guard->validate($url);
        } catch (BlockedTargetException $e) {
            return new HttpResult($url, $url, 0, [], '', 'blocked:' . $e->getMessage());
        }

        $state = ['headers' => [], 'body' => '', 'truncated' => false];
        $ch = $this->createHandle($url, $target, $options, $state);
        curl_exec($ch);

        return $this->buildResult($ch, $url, $state);
    }

    /**
     * Parallel status checks (HEAD, falling back to a truncated GET when HEAD is refused).
     * Redirects are reported, not followed.
     *
     * @param list<string> $urls
     * @return array<string, HttpResult>
     */
    public function checkMany(array $urls, int $concurrency = 8, int $timeout = 10): array
    {
        $results = $this->runMulti($urls, 'HEAD', $concurrency, $timeout);

        $retry = [];
        foreach ($results as $url => $result) {
            if (in_array($result->status, [0, 400, 403, 405, 406, 429, 501], true) && !str_starts_with($result->error, 'blocked:')) {
                $retry[] = $url;
            }
        }
        if ($retry !== []) {
            foreach ($this->runMulti($retry, 'GET', $concurrency, $timeout) as $url => $result) {
                if ($result->status > 0 || $results[$url]->status === 0) {
                    $results[$url] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * @param list<string> $urls
     * @return array<string, HttpResult>
     */
    private function runMulti(array $urls, string $method, int $concurrency, int $timeout): array
    {
        $results = [];
        $pending = [];
        foreach (array_unique($urls) as $url) {
            try {
                $pending[] = [$url, $this->guard->validate($url)];
            } catch (BlockedTargetException $e) {
                $results[$url] = new HttpResult($url, $url, 0, [], '', 'blocked:' . $e->getMessage());
            }
        }

        $multi = curl_multi_init();
        $active = [];
        $states = [];
        $options = ['method' => $method, 'timeout' => $timeout, 'maxBytes' => 16384];

        $addNext = function () use (&$pending, &$active, &$states, $multi, $options): void {
            if ($pending === []) {
                return;
            }
            [$url, $target] = array_shift($pending);
            $states[$url] = ['headers' => [], 'body' => '', 'truncated' => false];
            $ch = $this->createHandle($url, $target, $options, $states[$url]);
            curl_multi_add_handle($multi, $ch);
            $active[(int) $ch] = [$ch, $url];
        };

        for ($i = 0; $i < $concurrency; $i++) {
            $addNext();
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
            while (($info = curl_multi_info_read($multi)) !== false) {
                $ch = $info['handle'];
                [, $url] = $active[(int) $ch];
                $results[$url] = $this->buildResult($ch, $url, $states[$url], $info['result']);
                curl_multi_remove_handle($multi, $ch);
                unset($active[(int) $ch]);
                $addNext();
            }
        } while (($running > 0 || $active !== []) && $status === CURLM_OK);

        curl_multi_close($multi);

        return $results;
    }

    /**
     * @param array{host: string, port: int, scheme: string, ip: string} $target
     * @return \CurlHandle
     */
    private function createHandle(string $url, array $target, array $options, array &$state)
    {
        $method = $options['method'] ?? 'GET';
        $maxBytes = $options['maxBytes'] ?? self::DEFAULT_MAX_BYTES;

        $ch = curl_init();
        $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $ip],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: ' . ($options['accept'] ?? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
                'Accept-Language: de,en;q=0.8',
            ],
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_CERTINFO => (bool) ($options['certInfo'] ?? false),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$state): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $state['headers'] = []; // new response block (e.g. after 100 Continue)
                } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $state['headers'][strtolower(trim($name))][] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$state, $maxBytes): int {
                if (strlen($state['body']) + strlen($chunk) > $maxBytes) {
                    $state['body'] .= substr($chunk, 0, max(0, $maxBytes - strlen($state['body'])));
                    $state['truncated'] = true;

                    return 0; // abort transfer
                }
                $state['body'] .= $chunk;

                return strlen($chunk);
            },
        ]);

        return $ch;
    }

    /**
     * @param \CurlHandle $ch
     */
    private function buildResult($ch, string $url, array $state, ?int $multiErrno = null): HttpResult
    {
        $errno = $multiErrno ?? curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = '';
        // A write abort after receiving headers is our own size limit, not a failure.
        if ($errno !== 0 && !($errno === CURLE_WRITE_ERROR && $state['truncated'] && $status > 0)) {
            $error = match ($errno) {
                CURLE_COULDNT_RESOLVE_HOST => 'dns',
                CURLE_OPERATION_TIMEDOUT => 'timeout',
                CURLE_COULDNT_CONNECT => 'connect',
                CURLE_SSL_CACERT, CURLE_SSL_PEER_CERTIFICATE => 'ssl',
                default => 'curl' . $errno,
            };
            if (str_contains(strtolower(curl_error($ch)), 'certificate')) {
                $error = 'ssl';
            }
        }

        $certExpires = null;
        $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
        if (is_array($certInfo) && isset($certInfo[0]['Expire date'])) {
            $parsed = strtotime((string) $certInfo[0]['Expire date']);
            $certExpires = $parsed !== false ? $parsed : null;
        }

        $versionMap = [
            CURL_HTTP_VERSION_1_0 => '1.0',
            CURL_HTTP_VERSION_1_1 => '1.1',
            CURL_HTTP_VERSION_2_0 => '2',
        ];
        if (defined('CURL_HTTP_VERSION_3')) {
            $versionMap[CURL_HTTP_VERSION_3] = '3';
        }
        $httpVersion = $versionMap[(int) curl_getinfo($ch, CURLINFO_HTTP_VERSION)] ?? '';

        $result = new HttpResult(
            url: $url,
            finalUrl: $url,
            status: $error === '' ? $status : ($status > 0 && $errno === CURLE_OPERATION_TIMEDOUT ? 0 : $status),
            headers: $state['headers'],
            body: $state['body'],
            error: $error,
            ttfbMs: (int) round((float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000),
            totalMs: (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
            httpVersion: $httpVersion,
            certExpires: $certExpires,
            truncated: $state['truncated'],
        );
        curl_close($ch);

        return $result;
    }
}
