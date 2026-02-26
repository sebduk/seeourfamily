<?php

declare(strict_types=1);

namespace SeeOurFamily;

/**
 * HTML sanitisation and accent encoding helpers.
 *
 * sanitize()      - whitelist-strips HTML to safe formatting tags only
 * encodeAccents() - converts non-ASCII characters to HTML numeric entities
 *                   (e.g. é → &#233;) for cross-platform safety
 * clean()         - sanitize + encodeAccents in one call
 */
class Html
{
    /** Tags permitted in rich-text content. */
    private const ALLOWED_TAGS = '<b><i><u><s><strong><em><a><ul><ol><li><br><p><h3>';

    /**
     * Strip all HTML tags except the safe formatting whitelist.
     * Also cleans dangerous attributes from allowed tags.
     */
    public static function sanitize(string $html): string
    {
        $html = strip_tags($html, self::ALLOWED_TAGS);

        // Remove event-handler attributes (onclick, onerror, etc.) and
        // javascript: hrefs from the surviving tags.
        $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
        $html = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', 'href="#"', $html);

        return $html;
    }

    /**
     * Convert every non-ASCII character to an HTML numeric entity.
     * Leaves ASCII and existing HTML tags/entities intact.
     *
     * é → &#233;   ñ → &#241;   ü → &#252;
     */
    public static function encodeAccents(string $html): string
    {
        return preg_replace_callback('/[^\x00-\x7F]/u', function (array $m): string {
            return '&#' . mb_ord($m[0], 'UTF-8') . ';';
        }, $html);
    }

    /**
     * Sanitize + encode accents in one call.
     * Use this on any user-submitted rich-text before storing.
     */
    public static function clean(string $html): string
    {
        return self::encodeAccents(self::sanitize($html));
    }
}
