<?php

declare(strict_types=1);

namespace SeeOurFamily;

/**
 * Minimal front-controller router.
 *
 * Turns clean URLs into a page name + parameters:
 *   /                     -> page=home
 *   /tree                 -> page=tree
 *   /tree/abc-123-def     -> page=tree, id=abc-123-def (UUID)
 *   /person/abc-456-ghi   -> page=person, id=abc-456-ghi (UUID)
 *   /photos               -> page=photos
 *   /photo/abc-789-jkl    -> page=photo, id=abc-789-jkl (UUID)
 *   /admin/people         -> page=admin-people
 *   /admin/people/abc-12  -> page=admin-people, id=abc-12 (UUID)
 *
 * Also supports the old query-string style for backwards compatibility:
 *   ?page=home            -> page=home
 *   ?DomKey=abc123        -> handled by Auth before routing
 *
 * Requires .htaccess to rewrite all non-file URLs to index.php.
 */
class Router
{
    /** Special route prefixes handled outside the page template system. */
    private const SPECIAL_ROUTES = ['media'];

    /** Whitelist of valid page names. */
    private const PAGES = [
        'home',
        'blog',
        'blog-post',
        'tree',
        'ascendants',
        'descendants',
        'person',
        'list-names',
        'birthdays',
        'photos',
        'photo',
        'documents',
        'messages',
        'login',
        'forgot-password',
        'reset-password',
        'register',
        'help',
        'admin',
        'admin-people',
        'admin-couples',
        'admin-comments',
        'admin-documents',
        'admin-info',
        'admin-folders',
        'admin-messages',
        'system-admin',
        'system-admin-families',
        'system-admin-users',
        'system-admin-invitations',
        'system-admin-blog',
    ];

    private string $page = 'home';

    /** @var array<string, string> Extra parameters parsed from the URL. */
    private array $params = [];

    /** Non-null when the URL matches a special route (e.g. /media/{uuid}). */
    private ?string $specialRoute = null;

    public function __construct()
    {
        $this->resolve();
    }

    public function page(): string
    {
        return $this->page;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $_GET[$key] ?? $default;
    }

    /** Returns the special route name (e.g. 'media') or null for normal pages. */
    public function specialRoute(): ?string
    {
        return $this->specialRoute;
    }

    /** Build a clean URL for a page + optional id (UUID string or integer). */
    public static function url(string $page, string|int|null $id = null, array $extra = []): string
    {
        $path = '/' . ltrim($page, '/');
        if ($id !== null) {
            $path .= '/' . $id;
        }
        if ($extra) {
            $path .= '?' . http_build_query($extra);
        }
        return $path;
    }

    // -----------------------------------------------------------------

    private function resolve(): void
    {
        // 1. Try clean URL path first
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = trim($path, '/');

        if ($path !== '' && $path !== 'index.php') {
            $segments = explode('/', $path);

            // Special routes: /media/{uuid} — handled outside template system
            if (in_array($segments[0], self::SPECIAL_ROUTES, true)) {
                $this->specialRoute = $segments[0];
                $this->params['id'] = $segments[1] ?? null;
                return;
            }

            // /blog/abc-123-def -> page=blog-post, id=abc-123-def
            // /admin/people/12 -> page=admin-people, id=12
            // /system-admin/users/5 -> page=system-admin-users, id=5
            if ($segments[0] === 'blog' && isset($segments[1])) {
                $candidate = 'blog-post';
                $this->params['id'] = $segments[1];
            } elseif ($segments[0] === 'system-admin' && isset($segments[1])) {
                $candidate = 'system-admin-' . $segments[1];
                $this->params['id'] = $segments[2] ?? null;
            } elseif ($segments[0] === 'admin' && isset($segments[1])) {
                $candidate = 'admin-' . $segments[1];
                $this->params['id'] = $segments[2] ?? null;
            } else {
                $candidate = $segments[0];
                $this->params['id'] = $segments[1] ?? null;
            }

            if (in_array($candidate, self::PAGES, true)) {
                $this->page = $candidate;
                return;
            }
        }

        // 2. Fall back to ?page= query parameter (backwards compat)
        $qPage = $_GET['page'] ?? 'home';
        if (in_array($qPage, self::PAGES, true)) {
            $this->page = $qPage;
        }
    }
}
