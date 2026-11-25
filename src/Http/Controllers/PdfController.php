<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\PdfService;
use App\Domain\RequestRepository;
use App\Http\Controller;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Money;
use Twig\Environment;

/** HTML preview and merged PDF (cover sheet plus attachments) for one request or a batch of up to the configured cap. */
final class PdfController extends Controller
{
    /** PdfService renders and merges. */
    public function __construct(Environment $twig, private PdfService $pdf)
    {
        parent::__construct($twig);
    }

    /** On-screen preview of one request's cover sheet. */
    public function record(array $params): void
    {
        $user = $this->requireLogin();
        $request = $this->findVisible((int) $params['id'], $user);
        if (!$request) {
            http_response_code(404);
            $this->render('errors/404.twig');
            return;
        }

        AuditLogger::log('request', (int) $request['id'], 'view_record', "Viewed the record for {$request['number']}");
        $pageCount = 1 + array_sum(array_map(fn ($a) => $this->pdf->attachmentPageCount($a), $request['attachments']));
        $html = $this->pdf->renderCoverSheetHtml($this->pdf->coverSheetContext($request, $pageCount));

        $this->render('record.twig', ['request' => $request, 'cover_sheet_html' => $html]);
    }

    /** Merged PDF for one request; once approved it is stored and reused as the permanent record. */
    public function recordPdf(array $params): void
    {
        $user = $this->requireLogin();
        $request = $this->findVisible((int) $params['id'], $user);
        if (!$request) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        AuditLogger::log('request', (int) $request['id'], 'download_pdf', "Downloaded the PDF record for {$request['number']}");
        if ($request['record_pdf_path']) {
            $absolute = rtrim((string) Config::get('storage.root'), '/') . '/' . $request['record_pdf_path'];
            if (is_file($absolute)) {
                $this->streamPdf((string) file_get_contents($absolute), $request['number'] . '.pdf');
                return;
            }
        }

        $bytes = $this->pdf->mergeRequestRecord($request);

        // Approved is final: store the record PDF once, the first time it's
        // generated after approval (older 'complete' requests too).
        if (in_array($request['status'], ['approved', 'complete'], true)) {
            $relative = Config::get('storage.generated') . '/' . $request['number'] . '.pdf';
            $absolute = rtrim((string) Config::get('storage.root'), '/') . '/' . $relative;
            @mkdir(dirname($absolute), 0750, true);
            file_put_contents($absolute, $bytes);
            RequestRepository::setRecordPdfPath((int) $request['id'], $relative);
        }

        $this->streamPdf($bytes, $request['number'] . '.pdf');
    }

    /** Preview a print run: summary sheet plus a cover sheet per selected request. */
    public function batchPreview(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $requests = $this->loadSelectedRequests($user);

        if (!$requests) {
            $this->flash('Tick the requests you want to print first.');
            $this->redirect('/requests');
            return;
        }

        $rows = array_map(fn ($r) => [
            'number' => $r['number'], 'payable_to' => $r['payable_to'],
            'line_summary' => implode(' · ', array_map(fn ($l) => $l['line_code'] ?? $l['free_text'], $r['lines'])),
            'status' => $r['status'], 'total' => Money::format((int) $r['total_cents']),
        ], $requests);
        $grandTotal = Money::format((int) array_sum(array_column($requests, 'total_cents')));

        $coverSheetsHtml = array_map(function ($r) {
            $pageCount = 1 + array_sum(array_map(fn ($a) => $this->pdf->attachmentPageCount($a), $r['attachments']));
            return $this->pdf->renderCoverSheetHtml($this->pdf->coverSheetContext($r, $pageCount));
        }, $requests);

        $this->render('print_batch.twig', [
            'ids' => array_column($requests, 'id'),
            'requests' => $requests,
            'summary_html' => $this->pdf->renderSummaryHtml($rows, date('F j, Y'), $grandTotal),
            'cover_sheets_html' => $coverSheetsHtml,
        ]);
    }

    /** Merged PDF for the selected requests (staff limited to their own); one audit row per run. */
    public function batchPdf(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $requests = $this->loadSelectedRequests($user);

        if (!$requests) {
            $this->flash('Tick the requests you want to print first.');
            $this->redirect('/requests');
            return;
        }

        $bytes = $this->pdf->mergeBatchRecord($requests);
        AuditLogger::log('request', null, 'print_batch', 'Printed batch of ' . count($requests) . ' records (' . implode(', ', array_column($requests, 'number')) . ')');

        $this->streamPdf($bytes, 'expense-records-' . date('Y-m-d') . '.pdf');
    }

    /** @return array<int,array> fully loaded requests (lines + attachments), capped and visibility-filtered */
    private function loadSelectedRequests(array $user): array
    {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        $requesterId = Auth::isStaff() ? (int) $user['id'] : null;
        $ids = array_slice(RequestRepository::filterVisible($ids, $requesterId), 0, (int) Config::get('batch_print_cap', 50));

        $requests = array_map(fn ($id) => RequestRepository::find($id), $ids);
        // Preserve the order the user selected them in (filterVisible doesn't guarantee order).
        $byId = [];
        foreach ($requests as $r) {
            $byId[$r['id']] = $r;
        }
        return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));
    }

    private function findVisible(int $id, array $user): ?array
    {
        $request = RequestRepository::find($id);
        if (!$request) {
            return null;
        }
        if (Auth::isStaff() && (int) $request['requester_id'] !== (int) $user['id']) {
            return null;
        }
        return $request;
    }

    private function streamPdf(string $bytes, string $filename): void
    {
        header('Content-Type: application/pdf');
        header('Cache-Control: no-store');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }
}
