<?php

namespace Grav\Plugin\Pdf\Engine\Gotenberg;

/**
 * Gotenberg cannot produce the document.
 *
 * This is deliberately wider than "the host is down": a configured address
 * that answers with `500`, a proxy that returns its own error page, a server
 * that speaks the v8 API and rejects our form fields, or a `200` that is not a
 * PDF at all all mean the same thing to a visitor — no document. The Router
 * turns every one of them into the same fallback.
 */
final class Unavailable extends \RuntimeException
{
    /**
     * @param bool $tripsBreaker whether the failure says "this host is unusable"
     *                           rather than "this request was malformed". A bad
     *                           form field is our bug: it should be logged on
     *                           every attempt, not hidden for a minute.
     * @param int  $status       the HTTP status, or 0 when the request never
     *                           produced a response
     */
    public function __construct(
        string $message,
        private readonly bool $tripsBreaker = true,
        private readonly int $status = 0,
    ) {
        parent::__construct($message);
    }

    public function tripsBreaker(): bool
    {
        return $this->tripsBreaker;
    }

    public function status(): int
    {
        return $this->status;
    }
}
