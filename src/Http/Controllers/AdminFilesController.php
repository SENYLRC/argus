<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Http\Controller;
use App\Support\Branding;
use App\Support\Config;
use App\Support\Db;

/**
 * Administration > Files & branding: replace the logos, and see/manage what
 * people have uploaded.
 *
 * Record integrity comes first: attachments can only be deleted while their
 * request is still pending or was rejected — once approved or complete they
 * are part of the signed record. Signature images and stored record PDFs are
 * listed read-only for the same reason (signoffs and completed records point
 * at them).
 */
final class AdminFilesController extends Controller
{
    private const DELETABLE_STATUSES = ['pending', 'rejected'];

    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'admin';
    }

    /** Logos, uploaded attachments, signature images, stored PDFs and orphan-file housekeeping. */
    public function index(): void
    {
        $this->requireRole(['admin']);

        $logos = [];
        foreach (Branding::SLOTS as $slot => $info) {
            $logos[] = $info + ['slot' => $slot, 'url' => Branding::url($slot), 'custom' => Branding::isCustom($slot)];
        }

        $attachments = Db::conn()->query(
            'SELECT a.id, a.orig_name, a.mime, a.bytes, a.uploaded_at, a.stored_path,
                    r.id AS request_id, r.number, r.status, u.name AS uploader
               FROM attachments a
               JOIN requests r ON r.id = a.request_id
          LEFT JOIN users u ON u.id = a.uploaded_by
           ORDER BY a.uploaded_at DESC, a.id DESC
              LIMIT 300'
        )->fetchAll();
        $root = $this->root();
        foreach ($attachments as &$a) {
            $a['size'] = $this->human((int) $a['bytes']);
            $a['when'] = date('M j, Y', strtotime($a['uploaded_at']));
            $a['type'] = ['application/pdf' => 'PDF', 'image/jpeg' => 'JPG', 'image/png' => 'PNG'][$a['mime']] ?? $a['mime'];
            $a['missing'] = !is_file($root . '/' . $a['stored_path']);
            $a['can_delete'] = in_array($a['status'], self::DELETABLE_STATUSES, true);
        }
        unset($a);

        $signatures = Db::conn()->query(
            'SELECT s.label, s.is_default, s.uploaded_at, s.retired_at, u.name
               FROM signature_images s JOIN users u ON u.id = s.user_id
           ORDER BY u.name, s.uploaded_at DESC'
        )->fetchAll();

        $records = Db::conn()->query(
            "SELECT id, number, record_pdf_path FROM requests WHERE record_pdf_path IS NOT NULL ORDER BY id DESC LIMIT 100"
        )->fetchAll();

        $this->render('admin/files.twig', [
            'admin_section' => 'files',
            'logos'         => $logos,
            'attachments'   => $attachments,
            'signatures'    => $signatures,
            'records'       => $records,
            'usage'         => $this->usage(),
            'orphans'       => array_map(fn ($p) => ['path' => $p, 'size' => $this->human((int) @filesize($root . '/' . $p))], $this->orphans()),
        ]);
    }

    /** Replace one logo slot. */
    public function uploadLogo(array $params): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $slot = (string) $params['slot'];
        try {
            Branding::store($slot, $_FILES['logo'] ?? []);
        } catch (\InvalidArgumentException $e) {
            $this->back($e->getMessage(), '#logos');
            return;
        }
        AuditLogger::log('branding', null, 'logo_upload', 'Replaced the ' . Branding::SLOTS[$slot]['label']);
        $this->back(Branding::SLOTS[$slot]['label'] . ' replaced. Use “Restore official logo” to undo.', '#logos');
    }

    /** Revert a logo slot to the official file. */
    public function resetLogo(array $params): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $slot = (string) $params['slot'];
        if (!isset(Branding::SLOTS[$slot])) {
            $this->back('Unknown logo.', '#logos');
            return;
        }
        Branding::reset($slot);
        AuditLogger::log('branding', null, 'logo_reset', 'Restored the official ' . Branding::SLOTS[$slot]['label']);
        $this->back(Branding::SLOTS[$slot]['label'] . ' restored to the official Southeastern logo.', '#logos');
    }

    /** Delete an attachment, only while its request is pending or rejected (approved records stay intact). */
    public function deleteAttachment(array $params): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $stmt = Db::conn()->prepare(
            'SELECT a.*, r.number, r.status FROM attachments a JOIN requests r ON r.id = a.request_id WHERE a.id = :id'
        );
        $stmt->execute(['id' => (int) $params['id']]);
        $a = $stmt->fetch();
        if (!$a) {
            $this->back('That attachment no longer exists.', '#attachments');
            return;
        }
        if (!in_array($a['status'], self::DELETABLE_STATUSES, true)) {
            $this->back("{$a['orig_name']} is part of {$a['number']}'s {$a['status']} record and can't be deleted.", '#attachments');
            return;
        }
        Db::conn()->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $a['id']]);
        @unlink($this->root() . '/' . $a['stored_path']);
        AuditLogger::log('request', (int) $a['request_id'], 'attachment_delete', "Admin deleted attachment {$a['orig_name']} from {$a['number']}");
        $this->back("Deleted {$a['orig_name']} from {$a['number']}.", '#attachments');
    }

    /** Files under storage/attachments with no attachments row (e.g. a failed save). */
    public function deleteOrphans(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $orphans = $this->orphans();
        foreach ($orphans as $rel) {
            @unlink($this->root() . '/' . $rel);
        }
        AuditLogger::log('storage', null, 'orphans_delete', 'Deleted ' . count($orphans) . ' orphaned attachment file(s)');
        $this->back('Deleted ' . count($orphans) . ' orphaned file(s).', '#housekeeping');
    }

    /** Empty the compiled Twig template cache. */
    public function clearCache(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $dir = $this->root() . '/' . Config::get('storage.tmp') . '/twig_cache';
        $count = 0;
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : (@unlink($f->getPathname()) && $count++);
            }
        }
        AuditLogger::log('storage', null, 'cache_clear', "Cleared template cache ({$count} files)");
        $this->back("Template cache cleared ({$count} files). Pages rebuild it on their next load.", '#housekeeping');
    }

    private function orphans(): array
    {
        $root = $this->root();
        $dir = $root . '/' . Config::get('storage.attachments');
        if (!is_dir($dir)) {
            return [];
        }
        $known = array_flip(Db::conn()->query('SELECT stored_path FROM attachments')->fetchAll(\PDO::FETCH_COLUMN));
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!$f->isFile() || $f->getFilename() === '.gitkeep') {
                continue;
            }
            $rel = ltrim(substr($f->getPathname(), strlen($root)), '/');
            if (!isset($known[$rel])) {
                $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }

    private function usage(): array
    {
        $root = $this->root();
        $areas = [
            'Request attachments' => Config::get('storage.attachments'),
            'Signature images'    => Config::get('storage.signatures'),
            'Stored record PDFs'  => Config::get('storage.generated'),
            'Logos'               => 'branding',
            'Temporary & cache'   => Config::get('storage.tmp'),
        ];
        $out = [];
        foreach ($areas as $label => $sub) {
            [$files, $bytes] = [0, 0];
            $dir = $root . '/' . $sub;
            if (is_dir($dir)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    if ($f->isFile() && $f->getFilename() !== '.gitkeep') {
                        $files++;
                        $bytes += $f->getSize();
                    }
                }
            }
            $out[] = ['label' => $label, 'files' => $files, 'size' => $this->human($bytes)];
        }
        $free = @disk_free_space($root);
        $out[] = ['label' => 'Free disk space', 'files' => null, 'size' => $free !== false ? $this->human((int) $free) : 'unknown'];
        return $out;
    }

    private function human(int $bytes): string
    {
        foreach (['bytes', 'KB', 'MB', 'GB', 'TB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'TB') {
                return $i === 0 ? "{$bytes} bytes" : number_format($bytes / 1024 ** $i, 1) . " {$unit}";
            }
        }
        return "{$bytes} bytes";
    }

    private function root(): string
    {
        return rtrim((string) Config::get('storage.root'), '/');
    }

    private function back(string $message, string $anchor): void
    {
        $this->flash($message);
        $this->redirect('/admin/files' . $anchor);
    }
}
