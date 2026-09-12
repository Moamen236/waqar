<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response security headers, chiefly a real Content-Security-Policy.
 *
 * The repo had no security header of any kind before Phase 7, and the two
 * confirmed High findings carried over from the Phase 5 review — an
 * unvalidated image upload and unpurified rich text — both ended in
 * *same-origin script execution*. Each is fixed at its source
 * (`ImageUpload`, `RichTextSanitizer`); this closes the shared leg, so a
 * third bug of the same shape is contained rather than exploited.
 *
 * A strict `script-src` is only affordable because this application
 * vendors everything it loads — Phase 5 and 6 pulled Phosphor, icomoon,
 * Cairo and both themes into the repo, so there is no CDN to allowlist
 * and no `@import url(...)` to leave a hole for. The two inline scripts
 * that do exist (Ziggy's route table, Vite's module preload) carry a
 * per-request nonce instead of being waved through with 'unsafe-inline'.
 *
 * `style-src` does keep 'unsafe-inline': React writes inline `style`
 * attributes and both themes ship them in markup. That is a deliberate,
 * much smaller concession — a style attribute cannot execute script in
 * any browser this application supports, and nonces do not apply to
 * attributes.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generated per request and handed to both the Vite tags and
        // Ziggy's @routes directive, which is why it is set before the
        // response is built rather than after.
        $nonce = Str::random(24);
        Vite::useCspNonce($nonce);
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        foreach ($this->headers($nonce) as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $nonce): array
    {
        return [
            'Content-Security-Policy' => $this->policy($nonce),
            // Belt-and-braces against the upload path: even if a file
            // slips past ImageUpload's content check, the browser will
            // not re-interpret a declared image/* as HTML.
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // COD-only, so there is nothing here that needs a camera, a
            // microphone or a payment handler.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];
    }

    private function policy(string $nonce): string
    {
        $scriptSrc = ["'self'", "'nonce-{$nonce}'", "'strict-dynamic'"];
        $connectSrc = ["'self'"];

        // `npm run dev` serves modules and a websocket from the Vite dev
        // server, which is a different origin. Without this the whole
        // admin/storefront is blank in development while being perfectly
        // fine in production — an unpleasant way to find out.
        if (Vite::isRunningHot()) {
            $devServer = rtrim((string) config('app.vite_dev_server', 'http://localhost:5173'), '/');
            $scriptSrc[] = $devServer;
            $connectSrc[] = $devServer;
            $connectSrc[] = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $devServer);
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $scriptSrc,
            // See the class docblock — attributes, not a script vector.
            'style-src' => ["'self'", "'unsafe-inline'"],
            // data: covers the generated SVG artwork and favicon that
            // Phase 5 inlined.
            'img-src' => ["'self'", 'data:', 'blob:'],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => $connectSrc,
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive) => $directive.' '.implode(' ', $sources))
            ->implode('; ');
    }
}
