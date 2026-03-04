<?php
declare(strict_types=1);

namespace BiddingMonitor\Core;

use DOMDocument;
use DOMXPath;
use DOMNodeList;

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

    /**
     * XPath 쿼리 실행
     * @return DOMNodeList|false
     */
    public function queryXPath(string $expression)
    {
        if ($this->xpath === null) {
            return false;
        }
        return $this->xpath->query($expression);
    }

    /**
     * CSS selector 스타일 쿼리 (단순 변환: .class -> *[@class~=class], #id -> *[@id='id'])
     * 복잡한 selector는 XPath로 직접 작성 권장
     */
    public function querySelectorAll(string $selector): array
    {
        if ($this->xpath === null) {
            return [];
        }
        $xpath = $this->cssToXPath($selector);
        $list = $this->xpath->query($xpath);
        if ($list === false || $list->length === 0) {
            return [];
        }
        $result = [];
        foreach ($list as $node) {
            $result[] = $node;
        }
        return $result;
    }

    public function getDoc(): ?DOMDocument
    {
        return $this->doc;
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

    private function cssToXPath(string $selector): string
    {
        $selector = trim($selector);
        if (str_contains($selector, ' ')) {
            $parts = preg_split('/\s+/', $selector, 2);
            $first = $this->cssToXPath($parts[0]);
            $rest = $parts[1] ?? '';
            if ($rest === '') return $first;
            return $first . '//' . $this->cssToXPath($rest);
        }
        if (str_starts_with($selector, '#')) {
            return '//*[@id="' . substr($selector, 1) . '"]';
        }
        if (str_starts_with($selector, '.')) {
            $class = substr($selector, 1);
            return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]';
        }
        $tag = preg_replace('/\.\w+$/', '', $selector);
        if ($tag === '') $tag = '*';
        return '//' . $tag;
    }
}
