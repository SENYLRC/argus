<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\RequestRepository;
use App\Domain\RequestWorkflow;
use App\Domain\WorkflowException;
use App\Http\Controller;
use Twig\Environment;

/** ED/admin sign-off actions on a pending request: approve (name + PIN + signature) or reject (with reason). */
final class ApprovalController extends Controller
{
    /** RequestWorkflow enforces the rules; this class only translates the form and reports the outcome. */
    public function __construct(Environment $twig, private RequestWorkflow $workflow)
    {
        parent::__construct($twig);
    }

    /** POST /requests/{id}/approve. */
    public function approve(array $params): void
    {
        $user = $this->requireRole(['ed', 'admin']);
        $this->requireCsrf();
        $id = (int) $params['id'];
        $request = RequestRepository::find($id);
        if (!$request) {
            http_response_code(404);
            $this->render('errors/404.twig');
            return;
        }

        try {
            $this->workflow->approve(
                $request,
                $user,
                (string) ($_POST['typed_name'] ?? ''),
                (string) ($_POST['pin'] ?? ''),
                (int) ($_POST['signature_id'] ?? 0)
            );
            $this->flash("{$request['number']} approved and signed. The bookkeeper now sees it in the payment queue.");
        } catch (WorkflowException $e) {
            $this->flash($e->getMessage());
        }

        $this->redirect("/requests/{$id}");
    }

    /** POST /requests/{id}/reject. */
    public function reject(array $params): void
    {
        $user = $this->requireRole(['ed', 'admin']);
        $this->requireCsrf();
        $id = (int) $params['id'];
        $request = RequestRepository::find($id);
        if (!$request) {
            http_response_code(404);
            $this->render('errors/404.twig');
            return;
        }

        try {
            $this->workflow->reject($request, $user, (string) ($_POST['reason'] ?? ''));
            $this->flash('Rejection email sent to the requester with your reason.');
        } catch (WorkflowException $e) {
            $this->flash($e->getMessage());
        }

        $this->redirect("/requests/{$id}");
    }
}
