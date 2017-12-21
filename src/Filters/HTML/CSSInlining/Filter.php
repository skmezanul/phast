<?php

namespace Kibo\Phast\Filters\HTML\CSSInlining;

use Kibo\Phast\Filters\HTML\Helpers\BodyFinderTrait;
use Kibo\Phast\Filters\HTML\HTMLFilter;
use Kibo\Phast\Logging\LoggingTrait;
use Kibo\Phast\Retrievers\Retriever;
use Kibo\Phast\Security\ServiceSignature;
use Kibo\Phast\Services\ServiceRequest;
use Kibo\Phast\ValueObjects\URL;

class Filter implements HTMLFilter {
    use BodyFinderTrait, LoggingTrait;

    /**
     * @var string
     */
    private $ieFallbackScript = <<<EOJS
(function() {
    
    var ua = window.navigator.userAgent;
    if (ua.indexOf('MSIE ') === -1 && ua.indexOf('Trident/') === -1) {
        return;
    }
    
    document.addEventListener('readystatechange', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('link[data-phast-ie-fallback-url]'),
            function (el) {
                console.log(el);
                el.getAttribute('data-phast-ie-fallback-url');
                el.setAttribute('href', el.getAttribute('data-phast-ie-fallback-url'));
            }
        );
    });

    
    Array.prototype.forEach.call(
        document.querySelectorAll('style[data-phast-ie-fallback-url]'),
        function (el) {
            var link = document.createElement('link');
            if (el.hasAttribute('media')) {
                link.setAttribute('media', el.getAttribute('media'));
            }
            link.setAttribute('rel', 'stylesheet');
            link.setAttribute('href', el.getAttribute('data-phast-ie-fallback-url'));
            el.parentNode.insertBefore(link, el);
            el.parentNode.removeChild(el);
        }
    );
    Array.prototype.forEach.call(
        document.querySelectorAll('style[data-phast-nested-inlined]'),
        function (groupEl) {
            groupEl.parentNode.removeChild(groupEl);
        }
    );
    
})();
EOJS;

    /**
     * @var ServiceSignature
     */
    private $signature;

    /**
     * @var bool
     */
    private $withIEFallback = false;

    /**
     * @var int
     */
    private $maxInlineDepth = 2;

    /**
     * @var URL
     */
    private $baseURL;

    /**
     * @var string[]
     */
    private $whitelist = [];

    /**
     * @var string
     */
    private $serviceUrl;

    /**
     * @var int
     */
    private $urlRefreshTime;

    /**
     * @var Retriever
     */
    private $retriever;

    public function __construct(ServiceSignature $signature, URL $baseURL, array $config, Retriever $retriever) {
        $this->signature = $signature;
        $this->baseURL = $baseURL;
        $this->serviceUrl = URL::fromString((string)$config['serviceUrl']);
        $this->urlRefreshTime = (int)$config['urlRefreshTime'];
        $this->retriever = $retriever;

        foreach ($config['whitelist'] as $key => $value) {
            if (!is_array($value)) {
                $this->whitelist[$value] = ['ieCompatible' => true];
                $key = $value;
            } else {
                $this->whitelist[$key] = $value;
            }
            if (!isset ($this->whitelist[$key]['ieCompatible'])) {
                $this->whitelist[$key] = true;
            }
        }
    }

    public function transformHTMLDOM(\Kibo\Phast\Common\DOMDocument $document) {
        $links = iterator_to_array($document->query('//link'));
        $styles = iterator_to_array($document->query('//style'));
        foreach ($links as $link) {
            $this->inlineLink($link);
        }
        foreach ($styles as $style) {
            $this->inlineStyle($style);
        }
        if ($this->withIEFallback) {
            $this->addIEFallbackScript($document);
        }
    }

    private function inlineLink(\DOMElement $link) {
        if (!$link->hasAttribute('rel')
            || $link->getAttribute('rel') != 'stylesheet'
            || !$link->hasAttribute('href')
        ) {
            return;
        }

        $location = URL::fromString($link->getAttribute('href'))->withBase($this->baseURL);

        if (!$this->findInWhitelist($location)) {
            return;
        }

        $media = $link->getAttribute('media');
        $elements = $this->inlineURL($link->ownerDocument, $location, $media);

        $this->replaceElement($elements, $link);
    }

    private function inlineStyle(\DOMElement $style) {
        $elements = $this->inlineCSS($style->ownerDocument, $this->baseURL, $style->textContent, $style->getAttribute('media'));
        $this->replaceElement($elements, $style);
    }

    private function replaceElement($replacements, $element) {
        foreach ($replacements as $replacement) {
            $element->parentNode->insertBefore($replacement, $element);
        }
        $element->parentNode->removeChild($element);
    }

    private function findInWhitelist(URL $url) {
        $stringUrl = (string)$url;
        foreach ($this->whitelist as $pattern => $settings) {
            if (preg_match($pattern, $stringUrl)) {
                return $settings;
            }
        }
        return false;
    }

    /**
     * @param \Kibo\Phast\Common\DOMDocument $document
     * @param URL $url
     * @param string $media
     * @param boolean $ieCompatible
     * @param int $currentLevel
     * @param string[] $seen
     * @return \DOMElement[]
     */
    private function inlineURL(\Kibo\Phast\Common\DOMDocument $document, URL $url, $media, $ieCompatible = true, $currentLevel = 0, $seen = []) {
        $whitelistEntry = $this->findInWhitelist($url);

        if (!$whitelistEntry) {
            $this->logger()->info('Not inlining {url}. Not in whitelist', ['url' => ($url)]);
            return [$this->makeLink($document, $url, $media)];
        }

        if (!$whitelistEntry['ieCompatible']) {
            $ieFallbackUrl = $ieCompatible ? $url : null;
            $ieCompatible = false;
        } else {
            $ieFallbackUrl = null;
        }

        if (in_array($url, $seen)) {
            return [];
        }

        if ($currentLevel > $this->maxInlineDepth) {
            return $this->addIEFallback($ieFallbackUrl, [$this->makeLink($document, $url, $media)]);
        }

        $seen[] = $url;

        $this->logger()->info('Inlining {url}.', ['url' => (string)$url]);
        $content = $this->retriever->retrieve($url);
        if ($content === false) {
            $this->logger()->error('Could not get contents for {url}', ['url' => (string)$url]);
            return $this->addIEFallback($ieFallbackUrl, [$this->makeServiceLink($document, $url, $media)]);
        }

        $elements = $this->inlineCSS($document, $url, $content, $media, $ieCompatible, $currentLevel, $seen);
        $this->addIEFallback($ieFallbackUrl, $elements);
        return $elements;
    }

    private function inlineCSS(\Kibo\Phast\Common\DOMDocument $document, URL $url, $content, $media, $ieCompatible = true, $currentLevel = 0, $seen = []) {
        $content = $this->minify($content);

        $urlMatches = $this->getImportedURLs($content);
        $elements = [];
        foreach ($urlMatches as $match) {
            $content = str_replace($match[0], '', $content);
            $matchedUrl = URL::fromString($match['url'])->withBase($url);
            $replacement = $this->inlineURL($document, $matchedUrl, $media, $ieCompatible, $currentLevel + 1, $seen);
            $elements = array_merge($elements, $replacement);
        }

        $content = $this->rewriteRelativeURLs($content, $url);
        $elements[] = $this->makeStyle($document, $content, $media);

        return $elements;
    }

    private function makeServiceLink(\Kibo\Phast\Common\DOMDocument $document, URL $location, $media) {
        $params = [
            'src' => (string) $location,
            'cacheMarker' => floor(time() / $this->urlRefreshTime)
        ];
        $url = $this->makeSignedUrl($params);
        return $this->makeLink($document, URL::fromString($url), $media);
    }

    private function addIEFallback(URL $fallbackUrl = null, array $elements = null) {
        if ($fallbackUrl === null || !$elements) {
            return $elements;
        }

        foreach ($elements as $element) {
            $element->setAttribute('data-phast-nested-inlined', '');
        }

        $element->setAttribute('data-phast-ie-fallback-url', (string)$fallbackUrl);
        $element->removeAttribute('data-phast-nested-inlined');

        $this->logger()->info('Set {url} as IE fallback URL', ['url' => (string)$fallbackUrl]);

        $this->withIEFallback = true;

        return $elements;
    }

    private function addIEFallbackScript(\Kibo\Phast\Common\DOMDocument $document) {
        $this->logger()->info('Adding IE fallback script');
        $this->withIEFallback = false;
        $script = $document->createElement('script');
        $script->setAttribute('data-phast-no-defer', 'data-phast-no-defer');
        $script->textContent = $this->ieFallbackScript;
        $this->getBodyElement($document)->appendChild($script);
    }

    private function getImportedURLs($cssContent) {
        preg_match_all(
            '~
                @import \s++
                ( url \( )?+                # url() is optional
                ( (?(1) ["\']?+ | ["\'] ) ) # without url() a quote is necessary
                (?<url>[A-Za-z0-9_/.:?&=+%,-]++)
                \2                          # match ending quote
                (?(1)\))                    # match closing paren if url( was used
                \s*+ ;
            ~xi',
            $cssContent,
            $matches,
            PREG_SET_ORDER
        );
        return $matches;
    }

    private function makeStyle(\Kibo\Phast\Common\DOMDocument $document, $content, $media) {
        $style = $document->createElement('style');
        if ($media !== '') {
            $style->setAttribute('media', $media);
        }
        $style->textContent = $content;
        return $style;
    }

    private function makeLink(\Kibo\Phast\Common\DOMDocument $document, URL $url, $media) {
        $link = $document->createElement('link');
        $link->setAttribute('rel', 'stylesheet');
        $link->setAttribute('href', (string)$url);
        if ($media !== '') {
            $link->setAttribute('media', $media);
        }
        return $link;
    }

    private function minify($content) {
        // Remove comments
        $content = preg_replace('~/\*[^*]*\*+([^/*][^*]*\*+)*/~', '', $content);

        // Normalize whitespace
        $content = preg_replace('~\s+~', ' ', $content);

        // Remove whitespace before and after operators
        $chars = [',', '{', '}', ';'];
        foreach ($chars as $char) {
            $content = str_replace("$char ", $char, $content);
            $content = str_replace(" $char", $char, $content);
        }

        // Remove whitespace after colons
        $content = str_replace(': ', ':', $content);

        return trim($content);
    }

    private function rewriteRelativeURLs($cssContent, URL $cssUrl) {
        $callback = function($match) use ($cssUrl) {
            if (preg_match('~^[a-z]+:~i', $match[3])) {
                return $match[0];
            }
            return $match[1] . URL::fromString($match[3])->withBase($cssUrl) . $match[4];
        };

        $cssContent = preg_replace_callback(
            '~
                \b
                ( url\( ([\'"]?) )
                ([A-Za-z0-9_/.:?&=+%,#-]+)
                ( \2 \) )
            ~x',
            $callback,
            $cssContent
        );

        $cssContent = preg_replace_callback(
            '~
                ( @import \s+ ([\'"]) )
                ([A-Za-z0-9_/.:?&=+%,#-]+)
                ( \2 )
            ~x',
            $callback,
            $cssContent
        );

        return $cssContent;
    }

    protected function makeSignedUrl($params) {
        return (new ServiceRequest())->withParams($params)
            ->withUrl($this->serviceUrl)
            ->sign($this->signature)
            ->serialize();
    }
}