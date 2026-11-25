<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The sand notification band (README "Interactions & behaviour"): every
 * confirmation and validation message goes through here, is announced via
 * role="status" aria-live="polite", and never auto-hides — it stays on
 * screen (persisted in the session, so it survives the redirect after a
 * POST) until the user clicks Dismiss, which calls Flash::clear().
 */
final class Flash
{
    private const SESSION_KEY = '_flash';

    /** Queue a one-message notice for the next page; it stays until dismissed. */
    public static function set(string $message, string $kind = 'status'): void
    {
        $_SESSION[self::SESSION_KEY] = ['message' => $message, 'kind' => $kind];
    }

    /** Peek without clearing — the message must survive page reloads until Dismiss is clicked. */
    public static function peek(): ?array
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /** Remove the queued notice (the Dismiss button). */
    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
