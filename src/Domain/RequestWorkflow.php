<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Auth;
use App\Support\Db;
use App\Support\GeoLocator;
use App\Support\IpResolver;
use App\Support\Money;
use PDO;

/**
 * State transitions: pending -> approved (final), or pending -> rejected.
 * Approval is the last step in the app: the bookkeeper then processes the
 * request in QuickBooks, using this app only to see and print it. ('complete'
 * remains a valid status for requests closed out under the earlier workflow.)
 * (build-notes §1). approve() enforces all three of typed name matching the
 * account, correct PIN, and a selected signature image, server-side —
 * disabling the button client-side is not enough (build-notes §2c). complete()
 * is deliberately lighter (typed name only, signature optional, no PIN),
 * matching the prototype's bookkeeper panel, which has no PIN field at all.
 */
final class RequestWorkflow
{
    /** MailService sends the rejection notice. */
    public function __construct(private MailService $mail)
    {
    }

    /**
     * ED/admin approval: needs the typed name, correct PIN and a chosen signature. Records the sign-off
     * (with IP/geo), freezes a copy of the signature, and sets status to approved.
     * @throws WorkflowException when the request isn't pending, the role is wrong or a credential fails
     */
    public function approve(array $request, array $actor, string $typedName, string $pin, int $signatureId): void
    {
        if ($request['status'] !== 'pending') {
            throw new WorkflowException('This request is no longer awaiting approval.');
        }
        if (!Auth::isApprover()) {
            throw new WorkflowException('Only the Executive Director or Admin can approve requests.');
        }
        $this->assertSignoffCredentials($actor, $typedName, $pin, $signatureId);
        $signature = SignatureRepository::find($signatureId);

        $ip = IpResolver::clientIp();
        $geo = GeoLocator::lookup($ip);
        $copyPath = UploadService::copySignatureForSignoff($signature['stored_path']);

        Db::transaction(function (PDO $pdo) use ($request, $actor, $typedName, $signature, $copyPath, $ip, $geo) {
            // Re-checked in the UPDATE itself: two people acting at the same moment can both have read
            // "pending" above, and only one of them may win. The loser rolls back with nothing written.
            $upd = $pdo->prepare(
                "UPDATE requests SET status = 'approved', approved_by = :by, approved_at = NOW() WHERE id = :id AND status = 'pending'"
            );
            $upd->execute(['by' => $actor['id'], 'id' => $request['id']]);
            if ($upd->rowCount() !== 1) {
                throw new WorkflowException('This request is no longer awaiting approval.');
            }

            $this->insertSignoff($pdo, $request['id'], $actor, 'approve', $typedName, $signature['id'], $copyPath, $ip, $geo);
        });

        AuditLogger::log(
            'request',
            (int) $request['id'],
            'approve',
            "Approved {$request['number']} · signed with " . basename($copyPath) . ', PIN verified'
        );
    }

    /**
     * ED/admin rejection with a reason (default 'No reason given.'); logs it and emails the requester.
     * @throws WorkflowException when the request isn't pending or the role is wrong
     */
    public function reject(array $request, array $actor, string $reason): void
    {
        if ($request['status'] !== 'pending') {
            throw new WorkflowException('This request is no longer awaiting approval.');
        }
        if (!Auth::isApprover()) {
            throw new WorkflowException('Only the Executive Director or Admin can reject requests.');
        }
        $reason = trim($reason) ?: 'No reason given.';
        $ip = IpResolver::clientIp();
        $geo = GeoLocator::lookup($ip);

        Db::transaction(function (PDO $pdo) use ($request, $actor, $reason, $ip, $geo) {
            $upd = $pdo->prepare(
                "UPDATE requests SET status = 'rejected', reject_reason = :reason WHERE id = :id AND status = 'pending'"
            );
            $upd->execute(['reason' => $reason, 'id' => $request['id']]);
            if ($upd->rowCount() !== 1) {
                throw new WorkflowException('This request is no longer awaiting approval.');
            }

            $this->insertSignoff($pdo, $request['id'], $actor, 'reject', $actor['name'], null, null, $ip, $geo, pinVerified: false);
        });

        AuditLogger::log('request', (int) $request['id'], 'reject', "Rejected {$request['number']} · reason emailed to requester");

        $this->mail->send(
            'rejected',
            $request['requester_email'],
            "Your expenditure request {$request['number']} was not approved",
            [
                'request_number' => $request['number'],
                'approver_name'  => $actor['name'],
                'payable_to'     => $request['payable_to'],
                'amount'         => Money::format((int) $request['total_cents']),
                'reason'         => $reason,
                'cta_url'        => rtrim((string) \App\Support\Config::get('app.base_url'), '/') . '/requests/' . $request['id'],
                'reply_to_email' => $actor['email'],
                'reply_to_name'  => $actor['name'],
            ],
            (int) $request['id']
        );
    }

    private function assertSignoffCredentials(array $actor, string $typedName, string $pin, int $signatureId): void
    {
        if (trim($typedName) === '' || trim($pin) === '') {
            throw new WorkflowException('Type your name and enter your PIN to sign.');
        }
        if (!$this->namesMatch($actor['name'], $typedName)) {
            throw new WorkflowException('The typed name must match your account name.');
        }
        if (!Auth::verifyPin($actor, $pin)) {
            throw new WorkflowException('Incorrect PIN.');
        }
        $signature = SignatureRepository::find($signatureId);
        if (!$signature || (int) $signature['user_id'] !== (int) $actor['id'] || $signature['retired_at']) {
            throw new WorkflowException('Select a signature image, or upload one, before signing.');
        }
    }

    private function namesMatch(string $accountName, string $typedName): bool
    {
        return mb_strtolower(trim($accountName)) === mb_strtolower(trim($typedName));
    }

    private function insertSignoff(
        PDO $pdo,
        int $requestId,
        array $actor,
        string $action,
        string $typedName,
        ?int $signatureId,
        ?string $signatureCopyPath,
        string $ip,
        array $geo,
        bool $pinVerified = true
    ): void {
        // Only admin/ed/bookkeeper ever reach here (Auth::isApprover()/isBookkeeperRole()
        // gate the callers), so the account's own role is always a valid signoffs.role_at_time.
        $roleAtTime = $actor['role'];

        $stmt = $pdo->prepare(
            'INSERT INTO signoffs
                (request_id, user_id, role_at_time, action, typed_name, signature_image_id, signature_copy_path,
                 pin_verified, ip, geo_city, geo_region, geo_country, geo_isp, geo_lat, geo_lon, user_agent)
             VALUES
                (:rid, :uid, :role, :action, :name, :sig_id, :sig_path,
                 :pin_ok, :ip, :city, :region, :country, :isp, :lat, :lon, :ua)'
        );
        $stmt->execute([
            'rid'     => $requestId,
            'uid'     => $actor['id'],
            'role'    => $roleAtTime,
            'action'  => $action,
            'name'    => $typedName,
            'sig_id'  => $signatureId,
            'sig_path'=> $signatureCopyPath,
            'pin_ok'  => $pinVerified ? 1 : 0,
            'ip'      => $ip,
            'city'    => $geo['city'],
            'region'  => $geo['region'],
            'country' => $geo['country'],
            'isp'     => $geo['isp'],
            'lat'     => $geo['lat'],
            'lon'     => $geo['lon'],
            'ua'      => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }
}
