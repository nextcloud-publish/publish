<?php

declare(strict_types=1);

namespace App\Controller;

use App\Storage\JobWorkspace;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a build's rendered output -- the publish_url reported to Collectives
 * in ssg-worker's success callback. Public by design: config/packages/security.yaml
 * exempts ^/publish/ from the bearer-token firewall the same way /health is,
 * since a published site has to be viewable without the API's own secret.
 *
 * ssg-worker's SiteBuilder writes every stylesheet/script/back/sub-page link
 * as relative to the CURRENT page's own URL being a directory (e.g.
 * "style.css", "../style.css", "Cats/") -- that only resolves correctly in a
 * browser when the page's own URL ends in "/". A directory-shaped request
 * without one is therefore redirected rather than served directly, the same
 * way a conventional web server would.
 *
 * Deliberately ONE route, not a separate one for the bare "/publish/{id}"
 * case: Symfony's own compiled router treats a trailing "{path}" with an
 * empty default as making its preceding "/" optional, so "/publish/{id}" and
 * "/publish/{id}/" already resolve to the very same route internally. A
 * second explicit route for the bare form previously fought Symfony's own
 * trailing-slash normalization (which redirects the "/" form back to the
 * slash-less one), producing a redirect loop. Whether the *actual* request
 * had a trailing slash is read from the Request itself, not inferred from
 * which route matched.
 */
final class PublishedSiteController
{
    /** ssg-worker's SiteRenderer only ever emits these three file types. */
    private const MIME_TYPES = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript',
    ];

    public function __construct(
        private readonly JobWorkspace $jobWorkspace,
    ) {
    }

    #[Route(
        '/publish/{static_site_id}/{path}',
        name: 'published_site',
        requirements: ['static_site_id' => '[^/]+', 'path' => '.*'],
        defaults: ['path' => ''],
        methods: ['GET'],
    )]
    public function show(Request $request, string $static_site_id, string $path): Response
    {
        $realOutputDir = $this->existingOutputDir($static_site_id);

        $trimmedPath = rtrim($path, '/');
        $requested = $trimmedPath === '' ? $realOutputDir : $realOutputDir . '/' . $trimmedPath;

        if (is_dir($requested)) {
            // The route match alone can't tell a bare "/publish/{id}" apart
            // from "/publish/{id}/" (see class docblock), so the actual
            // request path is what decides whether a redirect is needed.
            if (!str_ends_with($request->getPathInfo(), '/')) {
                return new RedirectResponse($request->getPathInfo() . '/');
            }

            $requested .= '/index.html';
        }

        // realpath resolves ".." and symlinks, so this -- not the route's
        // requirements -- is what actually stops a path like
        // "../../input/content.tar.gz" from escaping output/.
        $realTarget = realpath($requested);
        if (
            $realTarget === false
            || !str_starts_with($realTarget . '/', $realOutputDir . '/')
            || !is_file($realTarget)
        ) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($realTarget);
        $extension = strtolower(pathinfo($realTarget, PATHINFO_EXTENSION));
        $response->headers->set('Content-Type', self::MIME_TYPES[$extension] ?? 'application/octet-stream');

        return $response;
    }

    /**
     * @throws NotFoundHttpException if the id is unsafe or has no build on disk
     */
    private function existingOutputDir(string $staticSiteId): string
    {
        try {
            $outputDir = $this->jobWorkspace->outputDir($staticSiteId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }

        $realOutputDir = realpath($outputDir);
        if ($realOutputDir === false) {
            throw new NotFoundHttpException();
        }

        return $realOutputDir;
    }
}
