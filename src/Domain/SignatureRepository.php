<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * A user may keep several signature images (build-notes §2c: full signature,
 * initials, a fresh upload after a name change). Retired images are never
 * hard-deleted — past records still reference them.
 */
final class SignatureRepository
{
    /** The user's non-retired signature images, default first. */
    public static function forUser(int $userId): array
    {
        $stmt = Db::conn()->prepare(
            'SELECT * FROM signature_images WHERE user_id = :u AND retired_at IS NULL ORDER BY is_default DESC, id'
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll();
    }

    /** One signature image row by id, or null. */
    public static function find(int $id): ?array
    {
        $stmt = Db::conn()->prepare('SELECT * FROM signature_images WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Register a stored image for a user, optionally making it their default. Returns the new id. */
    public static function add(int $userId, string $label, string $storedPath, bool $makeDefault = false): int
    {
        return Db::transaction(function ($pdo) use ($userId, $label, $storedPath, $makeDefault) {
            if ($makeDefault) {
                $pdo->prepare('UPDATE signature_images SET is_default = 0 WHERE user_id = :u')->execute(['u' => $userId]);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO signature_images (user_id, label, stored_path, is_default) VALUES (:u, :l, :p, :d)'
            );
            $stmt->execute(['u' => $userId, 'l' => $label, 'p' => $storedPath, 'd' => $makeDefault ? 1 : 0]);
            return (int) $pdo->lastInsertId();
        });
    }

    /** Make one of the user's images the default (clears the flag on the rest). */
    public static function setDefault(int $userId, int $signatureId): void
    {
        Db::transaction(function ($pdo) use ($userId, $signatureId) {
            $pdo->prepare('UPDATE signature_images SET is_default = 0 WHERE user_id = :u')->execute(['u' => $userId]);
            $pdo->prepare('UPDATE signature_images SET is_default = 1 WHERE id = :id AND user_id = :u')
                ->execute(['id' => $signatureId, 'u' => $userId]);
        });
    }

    /** Soft-delete: never hard-delete, since past sign-offs reference the image. */
    public static function retire(int $id): void
    {
        $stmt = Db::conn()->prepare('UPDATE signature_images SET retired_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
