<?php
/**
 * Helpers for sanitizing stored blog post HTML before persistence/rendering.
 */

/**
 * Remove document-level asset tags and unsafe attributes from rich blog content.
 */
function bdta_sanitize_blog_post_content(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    if (!class_exists('DOMDocument')) {
        return bdta_sanitize_blog_post_content_fallback($html);
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrapper_id = 'bdta-blog-content-root';
    $previous_errors = libxml_use_internal_errors(true);
    $encoded_html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="' . $wrapper_id . '">' . $encoded_html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous_errors);

    $xpath = new DOMXPath($dom);
    $root = $xpath->query('//*[@id="' . $wrapper_id . '"]')->item(0);

    if (!$root instanceof DOMElement) {
        return bdta_sanitize_blog_post_content_fallback($html);
    }

    bdta_clean_blog_post_node($root);

    $sanitized = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $sanitized .= $dom->saveHTML($child);
    }

    $sanitized = str_replace('<?xml encoding="UTF-8">', '', $sanitized);

    return trim($sanitized);
}

function bdta_sanitize_blog_post_content_fallback(string $html): string
{
    $without_comments = preg_replace('/<!--[\s\S]*?-->/', '', $html);
    $sanitized = $without_comments === null ? $html : $without_comments;

    $sanitized = preg_replace(
        '/<(script|style|link|meta|title|base|object|embed|form|input|button|select|textarea|iframe|frame|frameset|svg|math)\b[^>]*>.*?<\/\1\s*>/is',
        '',
        $sanitized
    );
    $sanitized = $sanitized === null ? $html : $sanitized;

    $sanitized = preg_replace('/<\/?(script|style|link|meta|title|base|object|embed|form|input|button|select|textarea|iframe|frame|frameset|svg|math)\b[^>]*\/?>/is', '', $sanitized);
    $sanitized = $sanitized === null ? $html : $sanitized;

    $without_head = preg_replace('/<head\b[^>]*>.*?<\/head\s*>/is', '', $sanitized);
    $sanitized = $without_head === null ? $sanitized : $without_head;

    $without_wrappers = preg_replace('/<\/?(html|body)\b[^>]*>/is', '', $sanitized);
    $sanitized = $without_wrappers === null ? $sanitized : $without_wrappers;

    $with_sanitized_attributes = preg_replace_callback(
        '/<([a-z][a-z0-9-]*)(\s[^<>]*?)?(\/?)>/i',
        static function (array $matches): string {
            $tag = strtolower($matches[1]);

            if (in_array($tag, ['html', 'body', 'head'], true)) {
                return '';
            }

            $attributes = isset($matches[2]) ? bdta_sanitize_blog_post_tag_attributes_fallback($matches[2]) : '';
            $self_closing = isset($matches[3]) ? $matches[3] : '';

            return '<' . $matches[1] . $attributes . $self_closing . '>';
        },
        $sanitized
    );
    $sanitized = $with_sanitized_attributes === null ? $sanitized : $with_sanitized_attributes;

    return trim($sanitized);
}

function bdta_sanitize_blog_post_tag_attributes_fallback(string $attributes): string
{
    $sanitized = preg_replace(
        '/\s+on[a-z0-9_-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/i',
        '',
        $attributes
    );
    $sanitized = $sanitized === null ? $attributes : $sanitized;

    $sanitized = preg_replace(
        '/\s+srcdoc\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/i',
        '',
        $sanitized
    );
    $sanitized = $sanitized === null ? $attributes : $sanitized;

    $sanitized = preg_replace(
        '/\s+xlink:href\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/i',
        '',
        $sanitized
    );
    $sanitized = $sanitized === null ? $attributes : $sanitized;

    $sanitized_urls = preg_replace_callback(
        '/\s+(href|src)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/i',
        static function (array $matches): string {
            $attribute_name = strtolower($matches[1]);
            $raw_value = trim($matches[2]);

            if (
                strlen($raw_value) >= 2
                && ($raw_value[0] === '"' || $raw_value[0] === '\'')
                && substr($raw_value, -1) === $raw_value[0]
            ) {
                $raw_value = substr($raw_value, 1, -1);
            }

            $raw_value = trim($raw_value, "\"'");

            $allow_data_image = $attribute_name === 'src';
            if (!bdta_is_safe_blog_post_url(trim($raw_value), $allow_data_image)) {
                return '';
            }

            $serialized_value = '"' . htmlspecialchars($raw_value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';

            return ' ' . $matches[1] . '=' . $serialized_value;
        },
        $sanitized
    );

    return $sanitized_urls === null ? $sanitized : $sanitized_urls;
}

/**
 * @param DOMNode $node
 */
function bdta_clean_blog_post_node(DOMNode $node): void
{
    $children = [];
    foreach ($node->childNodes as $child) {
        $children[] = $child;
    }

    foreach ($children as $child) {
        if ($child->nodeType === XML_COMMENT_NODE) {
            $node->removeChild($child);
            continue;
        }

        if (!$child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->tagName);

        if (in_array($tag, ['script', 'style', 'link', 'meta', 'title', 'base', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'iframe', 'frame', 'frameset', 'svg', 'math'], true)) {
            $node->removeChild($child);
            continue;
        }

        if ($tag === 'head') {
            $node->removeChild($child);
            continue;
        }

        if (in_array($tag, ['html', 'body'], true)) {
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
            bdta_clean_blog_post_node($node);
            continue;
        }

        bdta_clean_blog_post_attributes($child);
        bdta_clean_blog_post_node($child);
    }
}

function bdta_clean_blog_post_attributes(DOMElement $element): void
{
    if (!$element->hasAttributes()) {
        return;
    }

    $attrs_to_remove = [];

    foreach ($element->attributes as $attribute) {
        $name = strtolower($attribute->name);
        $value = trim($attribute->value);

        if (strpos($name, 'on') === 0) {
            $attrs_to_remove[] = $attribute->name;
            continue;
        }

        if ($name === 'xlink:href') {
            $attrs_to_remove[] = $attribute->name;
            continue;
        }

        if ($name === 'href' || $name === 'src') {
            if (!bdta_is_safe_blog_post_url($value, $name === 'src')) {
                $attrs_to_remove[] = $attribute->name;
            }
            continue;
        }

        if ($name === 'srcdoc') {
            $attrs_to_remove[] = $attribute->name;
            continue;
        }

        if ($name === 'style') {
            $sanitized_style = bdta_sanitize_blog_post_style($value);
            if ($sanitized_style === '') {
                $attrs_to_remove[] = $attribute->name;
            } else {
                $element->setAttribute($attribute->name, $sanitized_style);
            }
            continue;
        }

        if ($name === 'srcset') {
            $attrs_to_remove[] = $attribute->name;
        }
    }

    foreach ($attrs_to_remove as $attribute_name) {
        $element->removeAttribute($attribute_name);
    }

    if (strtolower($element->tagName) === 'a' && strtolower($element->getAttribute('target')) === '_blank') {
        $rel_values = preg_split('/\s+/', trim($element->getAttribute('rel'))) ?: [];
        if (!in_array('noopener', $rel_values, true)) {
            $rel_values[] = 'noopener';
        }
        if (!in_array('noreferrer', $rel_values, true)) {
            $rel_values[] = 'noreferrer';
        }
        $element->setAttribute('rel', trim(implode(' ', array_filter($rel_values))));
    }
}

function bdta_is_safe_blog_post_url(string $url, bool $allow_data_image = false): bool
{
    if ($url === '') {
        return false;
    }

    if (preg_match('/^(https?:|mailto:|tel:)/i', $url) === 1) {
        return true;
    }

    if ($url[0] === '#' || $url[0] === '?') {
        return true;
    }

    if ($allow_data_image && preg_match('/^data:image\/(?:gif|png|jpe?g|webp|svg\+xml);/i', $url) === 1) {
        return true;
    }

    if (strpos($url, '//') === 0 || strpos($url, '../') === 0) {
        return false;
    }

    if ($url[0] === '/' || strpos($url, './') === 0) {
        return true;
    }

    return preg_match('/^[^:\s][^:\s]*(?:[?#].*)?$/', $url) === 1;
}

function bdta_sanitize_blog_post_style(string $style): string
{
    $sanitized = preg_replace('/expression\s*\(|javascript\s*:/i', '', $style);
    if ($sanitized === null) {
        return '';
    }

    $sanitized = preg_replace('/url\s*\(\s*[\'"]?\s*javascript:/i', 'url(', $sanitized);
    if ($sanitized === null) {
        return '';
    }

    return trim($sanitized);
}
