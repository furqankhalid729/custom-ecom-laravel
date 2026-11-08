<?php

namespace Custom\GoogleMerchant\Support;

class Text
{
    /**
     * HTML to plain text: tags removed (block tags become spaces), entities
     * decoded, whitespace collapsed, cut to $limit characters.
     */
    public static function plain(?string $html, int $limit): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $text = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/tr)[^>]*>/i', ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit)) : $text;
    }
}
