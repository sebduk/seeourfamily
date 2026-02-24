<?php

declare(strict_types=1);

namespace SeeOurFamily;

/**
 * Minimal front-controller router.
 *
 * Turns clean URLs into a page name + parameters:
 *   /                     -> page=home
 *   /tree                 -> page=tree
 *   /tree/123             -> page=tree, id=123
 *   /person/456           -> page=person, id=456
 *   /photos               -> page=photos
 *   /photo/789            -> page=photo, id=789
 *   /admin/people         -> page=admin-people
 *   /admin/people/12      -> page=admin-people, id=12
 *
 * Also supports the old query-string style for backwards compatibility:
 *   ?page=home            -> page=home
 *   ?DomKey=abc123        -> handled by Auth before routing
 *
 * Requires .htaccess to rewrite all non-file URLs to index.php.
 */
class Router
{
    /** Whitelist of valid page names. */
    private const PAGES = [
        'home',
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
        'help',
        'admin',
        'admin-people',
        'admin-couples',
        'admin-comments',
        'admin-photos',
        'admin-documents',
        'admin-info',
        'admin-messages',
        'system-admin',
        'system-admin-families',
        'system-admin-users',
        'system-admin-invitations',
    ];

    private string $page = 'home';

    /** @var array<string, string> Extra parameters parsed from the URL. */
    private array $params = [];

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

    /** Build a clean URL for a page + optional id. */
    public static function url(string $page, ?int $id = null, array $extra = []): string
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

            // /admin/people/12 -> page=admin-people, id=12
            // /system-admin/users/5 -> page=system-admin-users, id=5
            if ($segments[0] === 'system-admin' && isset($segments[1])) {
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
