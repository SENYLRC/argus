<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Config;
use App\Support\Money;
use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;
use Twig\Environment;

/**
 * Cover sheet reproduces S:\FORMS\Request for Expenditure or Encumbrance.doc
 * field-for-field (build-notes §3), generated with Dompdf and then merged
 * with the uploaded invoice via FPDI so the packet looks exactly like the
 * paper filing it replaces.
 */
final class PdfService
{
    /** Twig supplies the cover-sheet and summary templates; Dompdf renders them, FPDI merges attachments. */
    public function __construct(private Environment $twig)
    {
    }

    /** Everything the cover-sheet partial needs to render — used for both the
     *  on-screen preview and the real PDF, so the two never drift apart. */
    public function coverSheetContext(array $request, int $totalPages): array
    {
        // Only what's filled in: no padding rows, no empty fields (blank values are null
        // and the template leaves those rows out).
        $lines = array_map(fn ($l) => [
            'code'   => $l['line_code'] ?? $l['free_text'],
            'label'  => $l['line_code'] !== null ? ($l['line_label'] ?? '') : '',
            'class'  => $l['class_name'] ?? '',
            'amount' => Money::format((int) $l['amount_cents']),
        ], $request['lines']);

        $approve = $this->latestSignoff((int) $request['id'], 'approve');
        $complete = $this->latestSignoff((int) $request['id'], 'complete');
        $isExpenditure = $request['kind'] === 'expenditure';

        return [
            'request'       => $request,
            'lines'         => $lines,
            'requester'     => $request['requester_name'],
            'submitted'     => date('F j, Y', strtotime($request['created_at'])),
            'fiscal_year'   => $request['fiscal_year'] ?? \App\Support\FiscalYear::forDate($request['created_at']),
            'payable_to'    => $request['payable_to'],
            'kind_label'    => $isExpenditure ? 'Expenditure' : 'Encumbrance',
            'posted_date'   => $this->fmtDate($request['posted_date']),
            'ref_label'     => $isExpenditure ? 'Check #' : 'Enc. #',
            'ref_no'        => $request['check_number'] ?: null,
            'total'         => Money::format((int) $request['total_cents']),
            'status'        => $request['status'],
            'ed_signature'  => $approve['typed_name'] ?? null,
            'ed_sig_file'   => $approve ? ('signed electronically · ' . basename((string) $approve['signature_copy_path'])) : null,
            'ed_date'       => $approve ? date('M j, Y', strtotime($approve['signed_at'])) : null,
            'date_ordered'  => $request['status'] === 'complete' ? $this->fmtDate($request['posted_date']) : null,
            'notes'         => $this->notes($request, $complete),
            'attachment_name' => $request['attachments'][0]['orig_name'] ?? 'no file attached',
            'request_number'=> $request['number'],
            'today'         => date('F j, Y'),
            'page_count'    => $totalPages,
        ];
    }

    private function notes(array $request, ?array $complete): string
    {
        $notes = (string) $request['justification'];
        if (!empty($request['remit_address'])) {
            $notes .= "\n\nRemit to: " . str_replace("\n", ', ', $request['remit_address']);
        }
        $notes .= "\nPayment method: " . $this->paymentLabel($request['payment_method']);
        if ($complete) {
            $notes .= "\nPosted by {$complete['typed_name']} on " . date('M j, Y', strtotime($complete['signed_at']));
        }
        return $notes;
    }

    private function paymentLabel(string $method): string
    {
        return match ($method) {
            'check'         => 'Check',
            'card'          => 'Credit card',
            'ach'           => 'ACH / EFT',
            'reimbursement' => 'Staff reimbursement',
            default         => $method,
        };
    }

    private function fmtDate(?string $date): ?string
    {
        return $date ? date('M j, Y', strtotime($date)) : null;
    }

    private function latestSignoff(int $requestId, string $action): ?array
    {
        $stmt = \App\Support\Db::conn()->prepare(
            'SELECT * FROM signoffs WHERE request_id = :id AND action = :action ORDER BY signed_at DESC LIMIT 1'
        );
        $stmt->execute(['id' => $requestId, 'action' => $action]);
        return $stmt->fetch() ?: null;
    }

    /** PDF page count of an attachment, without rendering it — 1 for images (wrapped onto their own page). */
    public function attachmentPageCount(array $attachment): int
    {
        if ($attachment['mime'] !== 'application/pdf') {
            return 1;
        }
        $path = $this->absolutePath($attachment['stored_path']);
        if (!is_file($path)) {
            return 1;
        }
        try {
            $fpdi = new Fpdi();
            return $fpdi->setSourceFile($path);
        } catch (\Throwable) {
            return 1;
        }
    }

    /** Just the sheet's inner HTML (no document wrapper) — for the on-screen preview, embedded inside layout.twig. */
    public function renderCoverSheetHtml(array $context): string
    {
        return $this->twig->render('pdf/cover_sheet_content.twig', $context + ['logo_src' => $this->logoDataUri()]);
    }

    /** HTML of the print-run summary page (batch print preview). */
    public function renderSummaryHtml(array $rows, string $todayLabel, string $grandTotal): string
    {
        return $this->twig->render('pdf/print_summary_content.twig', [
            'rows' => $rows, 'today' => $todayLabel, 'grand_total' => $grandTotal, 'count' => count($rows),
        ]);
    }

    /** Renders the cover sheet alone (Dompdf output as a string) — used both standalone and pre-merge. */
    public function renderCoverSheetPdf(array $context): string
    {
        $html = $this->twig->render('pdf/cover_sheet.html.twig', $context + ['logo_src' => $this->logoDataUri()]);
        return $this->renderHtmlToPdf($html);
    }

    /**
     * Official two-colour horizontal logo, inlined as a data URI: Dompdf runs
     * with remote fetching off and a chroot that excludes public/, and the
     * same partial also renders in the browser preview.
     */
    private function logoDataUri(): string
    {
        static $uri = null;
        return $uri ??= 'data:image/png;base64,' . base64_encode((string) file_get_contents(\App\Support\Branding::path('pdf')));
    }

    /** The print-run summary as a standalone PDF (page 1 of a merged batch). */
    public function renderSummarySheetPdf(array $rows, string $todayLabel, string $grandTotal): string
    {
        $html = $this->twig->render('pdf/print_summary.html.twig', [
            'rows' => $rows, 'today' => $todayLabel, 'grand_total' => $grandTotal, 'count' => count($rows),
        ]);
        return $this->renderHtmlToPdf($html);
    }

    private function renderHtmlToPdf(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('dpi', 96); // CSS px → points at 96/inch, so the 816px sheet fills a 8.5" Letter page
        $options->set('isHtml5ParserEnabled', true); // build-notes §4b: real markup for PDF/UA tagging
        $options->set('defaultFont', 'Liberation Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    /**
     * Cover sheet + every PDF/image attachment, merged behind it via FPDI
     * (build-notes §3). Non-PDF attachments are wrapped onto their own page.
     */
    public function mergeRequestRecord(array $request): string
    {
        $pageCount = 1;
        foreach ($request['attachments'] as $attachment) {
            $pageCount += $this->attachmentPageCount($attachment);
        }

        $cover = $this->renderCoverSheetPdf($this->coverSheetContext($request, $pageCount));
        $sources = [$cover];
        foreach ($request['attachments'] as $attachment) {
            $sources[] = $this->attachmentAsPdfBytes($attachment);
        }

        return $this->mergePdfSources($sources);
    }

    /**
     * @param array<int,array> $requests each with 'lines' and 'attachments' pre-loaded
     */
    public function mergeBatchRecord(array $requests): string
    {
        $rows = array_map(fn ($r) => [
            'number'      => $r['number'],
            'payable_to'  => $r['payable_to'],
            'line_summary'=> implode(' · ', array_map(fn ($l) => $l['line_code'] ?? $l['free_text'], $r['lines'])),
            'status'      => $r['status'],
            'total'       => Money::format((int) $r['total_cents']),
        ], $requests);
        $grandTotal = Money::format((int) array_sum(array_column($requests, 'total_cents')));

        $sources = [$this->renderSummarySheetPdf($rows, date('F j, Y'), $grandTotal)];
        foreach ($requests as $request) {
            $pageCount = 1;
            foreach ($request['attachments'] as $attachment) {
                $pageCount += $this->attachmentPageCount($attachment);
            }
            $sources[] = $this->renderCoverSheetPdf($this->coverSheetContext($request, $pageCount));
            foreach ($request['attachments'] as $attachment) {
                $sources[] = $this->attachmentAsPdfBytes($attachment);
            }
        }

        return $this->mergePdfSources($sources);
    }

    private function attachmentAsPdfBytes(array $attachment): string
    {
        $path = $this->absolutePath($attachment['stored_path']);
        if ($attachment['mime'] === 'application/pdf') {
            return is_file($path) ? (string) file_get_contents($path) : $this->missingFilePdf($attachment);
        }
        // JPG/PNG receipts wrapped onto their own page (build-notes §3).
        if (!is_file($path)) {
            return $this->missingFilePdf($attachment);
        }
        $dataUri = 'data:' . $attachment['mime'] . ';base64,' . base64_encode((string) file_get_contents($path));
        $html = $this->twig->render('pdf/image_wrap.html.twig', [
            'data_uri' => $dataUri,
            'orig_name' => $attachment['orig_name'],
            'alt_text'  => $attachment['description'] ?? $attachment['orig_name'],
        ]);
        return $this->renderHtmlToPdf($html);
    }

    private function missingFilePdf(array $attachment): string
    {
        $html = $this->twig->render('pdf/missing_attachment.html.twig', ['orig_name' => $attachment['orig_name']]);
        return $this->renderHtmlToPdf($html);
    }

    /** @param string[] $pdfByteSources */
    private function mergePdfSources(array $pdfByteSources): string
    {
        $fpdi = new Fpdi();
        $tmpFiles = [];

        try {
            foreach ($pdfByteSources as $bytes) {
                $tmp = tempnam($this->tmpDir(), 'src');
                file_put_contents($tmp, $bytes);
                $tmpFiles[] = $tmp;
                $pageCount = $fpdi->setSourceFile($tmp);
                for ($p = 1; $p <= $pageCount; $p++) {
                    $template = $fpdi->importPage($p);
                    $size = $fpdi->getTemplateSize($template);
                    $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $fpdi->useTemplate($template);
                }
            }

            return $fpdi->Output('S');
        } finally {
            // Always clean up, even if a corrupt source PDF makes FPDI throw
            // partway through — otherwise a run of failed merges slowly fills
            // storage/tmp.
            foreach ($tmpFiles as $tmp) {
                @unlink($tmp);
            }
        }
    }

    private function absolutePath(string $relative): string
    {
        return rtrim((string) Config::get('storage.root'), '/') . '/' . $relative;
    }

    private function tmpDir(): string
    {
        $dir = rtrim((string) Config::get('storage.root'), '/') . '/' . Config::get('storage.tmp');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }
}
