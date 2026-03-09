<?php
declare(strict_types=1);

namespace BiddingMonitor\Core;

use DOMDocument;
use DOMXPath;

/**
 * HTML 파서 (DOMDocument + DOMXPath 공통 래퍼)
 */
class HtmlParser
{
    private ?DOMDocument $doc = null;
    private ?DOMXPath $xpath = null;

    public function loadHtml(string $html): bool
    {
        $this->doc = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $loaded = @$this->doc->loadHTML(
            mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_use_internal_errors($internalErrors);
        if ($loaded) {
            $this->xpath = new DOMXPath($this->doc);
        } else {
            $this->xpath = null;
        }
        return $loaded;
    }

    public function getXPath(): ?DOMXPath
    {
        return $this->xpath;
    }

    /** 노드의 텍스트만 추출 (trim) */
    public static function getTextContent(?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        return trim((string)$node->textContent);
    }

    /** 노드의 href 속성 */
    public static function getHref(?\DOMNode $node, string $baseUrl = ''): string
    {
        if ($node === null || !$node->hasAttribute('href')) {
            return '';
        }
        $href = trim($node->getAttribute('href'));
        if ($href === '') return '';
        if (str_starts_with($href, 'http')) return $href;
        if ($baseUrl !== '' && str_starts_with($href, '/')) {
            $parsed = parse_url($baseUrl);
            $base = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
            return $base . $href;
        }
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
        }
        return $href;
    }
}
