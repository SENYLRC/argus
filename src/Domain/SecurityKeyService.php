<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Config;
use App\Support\Db;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

/**
 * Security keys (YubiKey and any other FIDO2 / WebAuthn authenticator) as a
 * two-step sign-in method, alongside authenticator-app codes. Uses the
 * lbuchs/webauthn library for the protocol checks: challenge, origin,
 * relying-party id (the site's host name), signature, and the signature
 * counter (a counter that goes backwards suggests a cloned key → refused).
 *
 * Touch-to-confirm is required (user presence); a key PIN isn't asked for
 * (userVerification "discouraged"), since this is the second factor after
 * the password. Challenges are single-use and
 * live in the session for two minutes.
 */
final class SecurityKeyService
{
    private const CHALLENGE_TTL = 120;

    /** @return array<int, array> the person's keys, newest first */
    public static function keysFor(int $userId): array
    {
        try {
            $stmt = Db::conn()->prepare('SELECT id, name, created_at, last_used_at FROM user_security_keys WHERE user_id = :u ORDER BY id DESC');
            $stmt->execute(['u' => $userId]);
            return $stmt->fetchAll();
        } catch (\PDOException) {
            return []; // migration not applied yet
        }
    }

    /** How many security keys / passkeys the user has registered. */
    public static function count(int $userId): int
    {
        return count(self::keysFor($userId));
    }

    /**
     * Browser options for registering a new key (navigator.credentials.create).
     * $kind 'key' asks for a roaming authenticator (YubiKey over USB/NFC — the
     * browser goes straight to "insert and touch your key"); 'passkey' puts no limit on
     * the kind of authenticator (Windows Hello, Touch ID, a phone, a password manager such
     * as Bitwarden, or a key). It is deliberately not restricted to "built into this
     * device": Firefox on Linux has none, so that setting makes it refuse at once. Keys
     * already on the account are excluded so the same one can't be added twice.
     */
    public static function registrationOptions(array $user, string $kind = 'key'): object
    {
        $wa = self::webAuthn();
        $passkey = $kind === 'passkey';
        $args = $wa->getCreateArgs(
            pack('N', (int) $user['id']), $user['email'], $user['name'],
            60,
            $passkey,                      // a passkey is a discoverable credential; a security key doesn't need to be
            $passkey ? 'preferred' : 'discouraged',
            $passkey ? null : true,
            self::credentialIds((int) $user['id'])
        );
        // Security keys: touch only, so the browser doesn't ask for the key's PIN
        // (the password is already the first factor). Passkeys: platform
        // authenticators and password managers expect to create a discoverable
        // credential and may verify the person, so they get the settings they
        // are built for. Sign-in checks the signature either way.
        self::rememberChallenge('reg', $wa, (int) $user['id']);
        return $args;
    }

    /** Verify the browser's response and save the key. Throws \RuntimeException with a readable message. */
    public static function finishRegistration(array $user, string $name, string $clientDataJSON, string $attestationObject): void
    {
        $challenge = self::takeChallenge('reg', (int) $user['id']);
        try {
            $data = self::webAuthn()->processCreate($clientDataJSON, $attestationObject, $challenge, false, true, false);
        } catch (WebAuthnException $e) {
            throw new \RuntimeException('That security key couldn’t be added: ' . $e->getMessage());
        }
        $credId = base64_encode((string) $data->credentialId);
        $dup = Db::conn()->prepare('SELECT id FROM user_security_keys WHERE credential_id = :c');
        $dup->execute(['c' => $credId]);
        if ($dup->fetch()) {
            throw new \RuntimeException('That security key is already registered.');
        }
        Db::conn()->prepare(
            'INSERT INTO user_security_keys (user_id, name, credential_id, public_key, sign_count, aaguid)
             VALUES (:u, :n, :c, :p, :s, :a)'
        )->execute([
            'u' => $user['id'], 'n' => mb_substr(trim($name) !== '' ? trim($name) : 'Security key', 0, 80),
            'c' => $credId, 'p' => (string) $data->credentialPublicKey, 's' => (int) $data->signatureCounter,
            'a' => $data->AAGUID ? bin2hex((string) $data->AAGUID) : null,
        ]);
    }

    /** Browser options for signing in with one of the person's keys (navigator.credentials.get). */
    public static function loginOptions(array $user): object
    {
        $wa = self::webAuthn();
        $args = $wa->getGetArgs(self::credentialIds((int) $user['id']), 60, true, true, true, true, true, false);
        $args->publicKey->userVerification = 'discouraged'; // touch only — no key PIN prompt
        self::rememberChallenge('login', $wa, (int) $user['id']);
        return $args;
    }

    /** Check a sign-in assertion; updates the counter and last-used time. */
    public static function verifyLogin(array $user, string $credentialId, string $clientDataJSON, string $authenticatorData, string $signature): bool
    {
        $challenge = self::takeChallenge('login', (int) $user['id']);
        $stmt = Db::conn()->prepare('SELECT * FROM user_security_keys WHERE user_id = :u AND credential_id = :c');
        $stmt->execute(['u' => $user['id'], 'c' => base64_encode($credentialId)]);
        $key = $stmt->fetch();
        if (!$key) {
            return false;
        }
        $wa = self::webAuthn();
        try {
            $wa->processGet($clientDataJSON, $authenticatorData, $signature, $key['public_key'], $challenge, (int) $key['sign_count'], false, true);
        } catch (WebAuthnException) {
            return false;
        }
        Db::conn()->prepare('UPDATE user_security_keys SET sign_count = :s, last_used_at = NOW() WHERE id = :id')
            ->execute(['s' => (int) $wa->getSignatureCounter(), 'id' => $key['id']]);
        return true;
    }

    /** Remove one of the person's keys. Returns the removed key's name, or null. */
    public static function remove(int $userId, int $keyId): ?string
    {
        $stmt = Db::conn()->prepare('SELECT name FROM user_security_keys WHERE id = :id AND user_id = :u');
        $stmt->execute(['id' => $keyId, 'u' => $userId]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            return null;
        }
        Db::conn()->prepare('DELETE FROM user_security_keys WHERE id = :id AND user_id = :u')->execute(['id' => $keyId, 'u' => $userId]);
        return (string) $name;
    }

    /** Delete every key on the account (admin 2FA reset). */
    public static function removeAll(int $userId): void
    {
        try {
            Db::conn()->prepare('DELETE FROM user_security_keys WHERE user_id = :u')->execute(['u' => $userId]);
        } catch (\PDOException) {
        }
    }

    // ── internals ────────────────────────────────────────────────────────────

    private static function webAuthn(): WebAuthn
    {
        // Relying party id = this site's host name; keys only work on this site.
        $rpId = (string) parse_url((string) Config::get('app.base_url'), PHP_URL_HOST);
        return new WebAuthn('Argus — Southeastern NY Library Resources Council', $rpId, null, false);
    }

    /** @return string[] raw credential ids for the person's keys */
    private static function credentialIds(int $userId): array
    {
        try {
            $stmt = Db::conn()->prepare('SELECT credential_id FROM user_security_keys WHERE user_id = :u');
            $stmt->execute(['u' => $userId]);
            return array_map(fn ($c) => (string) base64_decode((string) $c, true), $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\PDOException) {
            return [];
        }
    }

    private static function rememberChallenge(string $kind, WebAuthn $wa, int $userId): void
    {
        $_SESSION['webauthn_' . $kind] = [
            'challenge' => base64_encode($wa->getChallenge()->getBinaryString()),
            'user' => $userId,
            'at' => time(),
        ];
    }

    /** Single-use: the stored challenge is removed as it's read. */
    private static function takeChallenge(string $kind, int $userId): string
    {
        $c = $_SESSION['webauthn_' . $kind] ?? null;
        unset($_SESSION['webauthn_' . $kind]);
        if (!is_array($c) || (int) $c['user'] !== $userId || (time() - (int) $c['at']) > self::CHALLENGE_TTL) {
            throw new \RuntimeException('That took too long or the page was out of date — try again.');
        }
        return (string) base64_decode((string) $c['challenge'], true);
    }
}
