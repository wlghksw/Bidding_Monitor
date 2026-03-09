<?php
declare(strict_types=1);

namespace BiddingMonitor\Core;

/**
 * HTTP 클라이언트 (curl / curl_multi 래핑)
 * 재시도, rate limit, 공통 에러 처리
 */
class HttpClient
{
    private int $maxRetries = 2;
    private int $timeout = 30;
    private float $rateLimitDelay = 0.2; // 초
    /** SSL 체인이 불완전한 일부 공공 사이트 예외 */
    private const INSECURE_SSL_HOSTS = ['www.ui4u.go.kr'];

    private function shouldVerifySsl(string $url): bool
    {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        return !in_array($host, self::INSECURE_SSL_HOSTS, true);
    }

    /**
     * 단일 GET 요청
     */
    public function get(string $url): ?string
    {
        $attempt = 0;
        $verifySsl = $this->shouldVerifySsl($url);
        while ($attempt <= $this->maxRetries) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT       => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
                CURLOPT_USERAGENT      => 'BiddingMonitor/1.0 (Compatible; PHP)',
                CURLOPT_ENCODING       => '',
            ]);
            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($errno === 0 && $httpCode >= 200 && $httpCode < 400 && is_string($response)) {
                if (PHP_VERSION_ID < 80500) {
                    curl_close($ch);
                }
                return $response;
            }
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            $attempt++;
            if ($attempt <= $this->maxRetries) {
                usleep((int)($this->rateLimitDelay * 1_000_000));
            }
        }
        return null;
    }

    /**
     * application/x-www-form-urlencoded POST 요청 (단일)
     *
     * @param array<string, scalar> $fields
     */
    public function postForm(string $url, array $fields): ?string
    {
        $attempt = 0;
        $verifySsl = $this->shouldVerifySsl($url);
        $body = http_build_query($fields, '', '&');

        while ($attempt <= $this->maxRetries) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
                CURLOPT_USERAGENT      => 'BiddingMonitor/1.0 (Compatible; PHP)',
                CURLOPT_ENCODING       => '',
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
            ]);
            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($errno === 0 && $httpCode >= 200 && $httpCode < 400 && is_string($response)) {
                if (PHP_VERSION_ID < 80500) {
                    curl_close($ch);
                }
                return $response;
            }
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            $attempt++;
            if ($attempt <= $this->maxRetries) {
                usleep((int)($this->rateLimitDelay * 1_000_000));
            }
        }
        return null;
    }

    /**
     * 여러 URL 동시 요청 (curl_multi)
     * @param string[] $urls
     * @return array<string, string|null> url => body (실패 시 null)
     */
    public function getMulti(array $urls): array
    {
        $result = array_fill_keys($urls, null);
        if (empty($urls)) {
            return $result;
        }

        $mh = curl_multi_init();
        if ($mh === false) {
            return $result;
        }

        $handles = [];
        foreach ($urls as $url) {
            $verifySsl = $this->shouldVerifySsl($url);
            $ch = curl_init($url);
            if ($ch === false) continue;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT       => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
                CURLOPT_USERAGENT     => 'BiddingMonitor/1.0 (Compatible; PHP)',
                CURLOPT_ENCODING      => '',
            ]);
            $handles[$url] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
        } while ($running > 0);

        foreach ($handles as $url => $ch) {
            $body = curl_multi_getcontent($ch);
            $errno = curl_errno($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($errno === 0 && is_string($body) && $code >= 200 && $code < 400) {
                $result[$url] = $body;
            }
            curl_multi_remove_handle($mh, $ch);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
        }
        curl_multi_close($mh);
        return $result;
    }
}
