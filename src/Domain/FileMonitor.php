<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Config;
use App\Support\Db;
use App\Support\Settings;

/**
 * File integrity monitor, ported from the SENYLRC WordPress security plugin.
 *
 *  - Baseline: a SHA-256 of every watched code file (extensions are a setting)
 *    under src, public, templates, config, bin, db, deploy and vendor, plus the
 *    top-level composer and bootstrap files. Stored as hashes only, in
 *    file_baseline.
 *  - A scan compares the disk to the baseline and reports added, modified and
 *    deleted files, and always flags PHP-like files inside storage/ (uploads
 *    and generated files should never contain code).
 *  - Changes are emailed to the active Admin / Tech accounts, once per
 *    distinct set of changes, so a nightly scan doesn't repeat itself.
 *  - deploy/install.sh re-baselines after every deploy, since a deploy is a
 *    deliberate change; "Accept changes" does the same from the admin screen.
 *
 * The last result is kept in the fm_result setting (JSON) for the admin page.
 * Run from cron: bin/console files:scan.
 */
final class FileMonitor
{
    private const LIST_CAP = 500;
    private const SKIP_DIRS = ['.git', 'node_modules', 'twig_cache'];
    private const WATCHED_DIRS = ['src', 'public', 'templates', 'config', 'bin', 'db', 'deploy', 'vendor'];

    /** Application root (the folder holding bootstrap.php). */
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Whether the monitor is switched on in settings. */
    public static function enabled(): bool
    {
        return Settings::get('fm_enabled', '1') === '1';
    }

    /** File extensions to fingerprint, lower-case, from the setting (default list if blank). */
    public static function extensions(): array
    {
        $e = array_filter(array_map('trim', explode(',', strtolower(Settings::get('fm_extensions')))));
        return $e ?: ['php', 'twig', 'js', 'css', 'sql', 'htaccess'];
    }

    /** Path prefixes to skip, relative to the app root, one per line of the setting. */
    public static function excludedPrefixes(): array
    {
        $out = [];
        foreach (preg_split('/\R/', Settings::get('fm_exclude')) ?: [] as $line) {
            $line = trim(str_replace('\\', '/', $line), " /\t");
            if ($line !== '' && !str_contains($line, '..')) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * Hash every watched file under $root.
     * Files in bin/ are watched whatever their extension (bin/console has none).
     * @param string[] $exts   lower-case extensions ('htaccess' also matches .htaccess)
     * @param string[] $exclude path prefixes relative to $root
     * @return array<string,string> relative path => sha256, sorted by path
     */
    public static function scanFiles(string $root, array $exts, array $exclude = []): array
    {
        $root = rtrim($root, '/');
        $out = [];
        $wanted = static function (string $rel, string $name) use ($exts): bool {
            $lower = strtolower($name);
            if ($lower === '.htaccess') {
                return in_array('htaccess', $exts, true);
            }
            if (str_starts_with($rel, 'bin/')) {
                return true;
            }
            return in_array(strtolower(pathinfo($lower, PATHINFO_EXTENSION)), $exts, true);
        };
        $skipped = static function (string $rel) use ($exclude): bool {
            foreach ($exclude as $prefix) {
                if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) {
                    return true;
                }
            }
            return false;
        };

        foreach ((array) @scandir($root) as $name) {
            $abs = $root . '/' . $name;
            if (is_file($abs) && $wanted($name, $name) && !$skipped($name)) {
                $out[$name] = (string) @hash_file('sha256', $abs);
            }
        }
        foreach (self::WATCHED_DIRS as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $c): bool => !($c->isDir() && ($c->isLink() || in_array($c->getFilename(), self::SKIP_DIRS, true)))
            ));
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($root) + 1);
                if (!$wanted($rel, $file->getFilename()) || $skipped($rel)) {
                    continue;
                }
                $hash = @hash_file('sha256', $file->getPathname());
                if ($hash !== false) {
                    $out[$rel] = $hash;
                }
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Compare two path => hash maps.
     * @return array{added:string[],modified:string[],deleted:string[]}
     */
    public static function diff(array $old, array $now): array
    {
        $modified = [];
        foreach ($now as $path => $hash) {
            if (isset($old[$path]) && !hash_equals((string) $old[$path], (string) $hash)) {
                $modified[] = $path;
            }
        }
        return [
            'added' => array_keys(array_diff_key($now, $old)),
            'modified' => $modified,
            'deleted' => array_keys(array_diff_key($old, $now)),
        ];
    }

    /** PHP-like files under storage/ (uploads and generated files must never hold code). */
    public static function storageCode(string $root): array
    {
        $dir = $root . '/storage';
        if (!is_dir($dir)) {
            return [];
        }
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && preg_match('/\.(php\d?|phtml|phar)$/i', $file->getFilename())) {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
                if (count($found) >= self::LIST_CAP) {
                    break;
                }
            }
        }
        sort($found);
        return $found;
    }

    /** The stored baseline as path => hash. */
    public static function baseline(): array
    {
        return Db::conn()->query('SELECT path, sha256 FROM file_baseline')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /** Take a fresh baseline from disk (accepts everything as it is now). Returns the number of files. */
    public static function rebaseline(): int
    {
        $files = self::scanFiles(self::root(), self::extensions(), self::excludedPrefixes());
        Db::transaction(function (\PDO $pdo) use ($files) {
            $pdo->exec('DELETE FROM file_baseline');
            $ins = $pdo->prepare('INSERT INTO file_baseline (path, sha256) VALUES (:p, :h)');
            foreach ($files as $path => $hash) {
                $ins->execute(['p' => $path, 'h' => $hash]);
            }
        });
        self::store([
            'time' => time(), 'status' => 'clean', 'note' => 'Baseline taken.', 'total' => count($files),
            'added' => [], 'modified' => [], 'deleted' => [], 'storage_code' => [], 'alerted' => '',
        ]);
        return count($files);
    }

    /**
     * Scan now. Takes the baseline if there isn't one yet. With $mail, emails
     * the admins when there are changes not already reported.
     * @return array the stored result (see lastResult())
     */
    public static function run(?MailService $mail = null): array
    {
        $baseline = self::baseline();
        if (!$baseline) {
            self::rebaseline();
            return self::lastResult();
        }
        $root = self::root();
        $now = self::scanFiles($root, self::extensions(), self::excludedPrefixes());
        $d = self::diff($baseline, $now);
        $storage = self::storageCode($root);
        $problems = count($d['added']) + count($d['modified']) + count($d['deleted']) + count($storage);

        $prev = self::lastResult();
        $result = [
            'time' => time(), 'status' => $problems ? 'changes' : 'clean', 'note' => '', 'total' => count($now),
            'added' => array_slice($d['added'], 0, self::LIST_CAP),
            'modified' => array_slice($d['modified'], 0, self::LIST_CAP),
            'deleted' => array_slice($d['deleted'], 0, self::LIST_CAP),
            'storage_code' => $storage,
            'alerted' => (string) ($prev['alerted'] ?? ''),
        ];

        if ($problems) {
            $hashes = [];
            foreach (array_merge($d['added'], $d['modified']) as $p) {
                $hashes[] = $p . '@' . $now[$p];
            }
            $sig = sha1(json_encode([$hashes, $d['deleted'], $storage]));
            if ($sig !== $result['alerted']) {
                AuditLogger::log('integrity', null, 'changes_found',
                    sprintf('File integrity scan: %d modified, %d new, %d deleted, %d code file(s) in storage', count($d['modified']), count($d['added']), count($d['deleted']), count($storage)),
                    actorId: null, actorEmail: 'system');
                if ($mail !== null) {
                    self::alert($mail, $result);
                }
                $result['alerted'] = $sig;
            }
        }
        self::store($result);
        return $result;
    }

    /** The last scan result, or an empty "never scanned" result. */
    public static function lastResult(): array
    {
        $raw = json_decode(Settings::get('fm_result'), true);
        return is_array($raw) ? $raw : ['time' => 0, 'status' => 'none', 'note' => '', 'total' => 0,
            'added' => [], 'modified' => [], 'deleted' => [], 'storage_code' => [], 'alerted' => ''];
    }

    /** Persist a scan result for the admin page. */
    private static function store(array $result): void
    {
        Settings::set('fm_result', json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    /** Email the result to every active Admin / Tech account. */
    private static function alert(MailService $mail, array $r): void
    {
        $admins = Db::conn()->query("SELECT name, email FROM users WHERE role = 'admin' AND active = 1")->fetchAll();
        foreach ($admins as $a) {
            $mail->send('file_changes', $a['email'], 'File changes detected on the expenditure request system', [
                'name' => $a['name'],
                'modified' => array_slice($r['modified'], 0, 30), 'modified_count' => count($r['modified']),
                'added' => array_slice($r['added'], 0, 30), 'added_count' => count($r['added']),
                'deleted' => array_slice($r['deleted'], 0, 30), 'deleted_count' => count($r['deleted']),
                'storage_code' => array_slice($r['storage_code'], 0, 30), 'storage_count' => count($r['storage_code']),
                'cta_url' => rtrim((string) Config::get('app.base_url'), '/') . '/admin/integrity',
            ]);
        }
    }
}
