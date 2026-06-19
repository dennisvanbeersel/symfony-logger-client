<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Twig;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the CSP nonce for injected <script> tags.
 *
 * Centralises the request-attribute lookup and HTML-attribute escaping so every
 * script generator applies the nonce identically. Never throws - a missing or
 * unavailable nonce simply yields no attribute (CSP is optional for the host).
 */
final readonly class CspNonceProvider
{
    public function __construct(
        private ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * Build the ` nonce="..."` attribute for an injected <script> tag.
     *
     * Returns an empty string when no nonce is available, so the attribute is
     * simply omitted.
     */
    public function nonceAttribute(): string
    {
        $nonce = $this->getNonce();

        return null !== $nonce
            ? ' nonce="'.htmlspecialchars($nonce, \ENT_QUOTES, 'UTF-8').'"'
            : '';
    }

    /**
     * Get CSP nonce from request attributes.
     *
     * Returns null if no nonce is available (e.g., project doesn't use CSP).
     */
    public function getNonce(): ?string
    {
        try {
            if (null === $this->requestStack) {
                return null;
            }

            $request = $this->requestStack->getCurrentRequest();

            if (null === $request) {
                return null;
            }

            $nonce = $request->attributes->get('csp_nonce');

            if (null === $nonce || !\is_string($nonce) || '' === $nonce) {
                return null;
            }

            return $nonce;
        } catch (\Throwable) {
            // Silently fail - CSP nonce is optional
            return null;
        }
    }
}
