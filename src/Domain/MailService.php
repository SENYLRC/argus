<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Config;
use App\Support\Db;
use App\Support\Settings;
use PHPMailer\PHPMailer\PHPMailer;
use Twig\Environment;

/**
 * SMTP via PHPMailer — not mail() (build-notes §4). In production this is
 * the server's local Postfix on localhost:25 with no auth or TLS; Postfix
 * handles relay/deliverability. Setting mail.username / mail.encryption
 * switches to authenticated, encrypted SMTP against a remote provider.
 * Every send is logged to email_log, including failures, and always
 * carries an HTML + plain-text part.
 *
 * Email test mode (Administration screen, after linx's SEAL_Functions::send):
 *   'off'      nothing is sent; the message is only logged.
 *   'redirect' every message goes to the test address(es) instead, subject
 *              prefixed "[TEST]", with a banner naming the real recipient.
 * Covers every email this app sends, cron digests included.
 */
final class MailService
{
    /** Twig renders the HTML and plain-text bodies from templates/mail/. */
    public function __construct(private Environment $twig)
    {
    }

    /**
     * Send one notice, honouring the admin email mode (off / redirect to test address / normal) and the
     * per-notice switches. Every attempt is written to email_log. $bypassTestMode is for the admin's
     * "send a test email" button. Returns false only on a real delivery failure.
     */
    public function send(string $template, string $toEmail, string $subject, array $context, ?int $requestId = null, bool $bypassTestMode = false): bool
    {
        if (!Settings::noticeEnabled($template)) {
            $this->log($template, $toEmail, $subject, $requestId, true, null, 'Not sent (this notice is turned off in Administration > Email)');
            return true;
        }
        // Admin "send a test email": goes to the address the admin typed, whatever the mode.
        $mode = $bypassTestMode ? '' : Settings::emailMode();
        if ($mode === 'off') {
            $this->log($template, $toEmail, $subject, $requestId, true, null, 'Not sent (email test mode: off)');
            return true;
        }
        $recipients = [$toEmail];
        $delivery = $bypassTestMode ? 'Admin test send' : null;
        if ($mode === 'redirect') {
            $recipients = Settings::emailTestTo();
            if (!$recipients) {
                $this->log($template, $toEmail, $subject, $requestId, true, null, 'Not sent (email test mode: no valid test address set)');
                return true;
            }
            $context['test_banner_to'] = $toEmail;
            $subject = '[TEST] ' . $subject;
            $delivery = 'Sent to test address ' . implode(', ', $recipients);
        }

        $templateName = "mail/{$template}.html.twig";
        $html = $this->twig->render($templateName, $context);
        $wrapper = $this->twig->load($templateName);
        $text = $wrapper->hasBlock('plain', $context)
            ? $wrapper->renderBlock('plain', $context)
            : strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
        if ($mode === 'redirect') {
            $text = "*** TEST MODE — this email would have gone to: {$toEmail} ***\n\n" . $text;
        }

        $ok = true;
        $error = null;

        try {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = Config::get('mail.host');
            $mailer->Port = Config::get('mail.port');
            $username = (string) Config::get('mail.username', '');
            $mailer->SMTPAuth = $username !== '';
            if ($mailer->SMTPAuth) {
                $mailer->Username = $username;
                $mailer->Password = Config::get('mail.password');
            }
            $encryption = (string) Config::get('mail.encryption', '');
            $mailer->SMTPSecure = $encryption;
            // Don't opportunistically STARTTLS to a local Postfix — its snakeoil
            // cert fails verification and the send would error out.
            $mailer->SMTPAutoTLS = $encryption !== '';
            $mailer->setFrom(Settings::mailFromEmail(), Settings::mailFromName());
            if (!empty($context['reply_to_email']) && Settings::get('mail_reply_to_approver', '1') === '1') {
                $mailer->addReplyTo($context['reply_to_email'], $context['reply_to_name'] ?? '');
            }
            foreach ($recipients as $to) {
                $mailer->addAddress($to);
            }
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body = $html;
            // Logo travels inside the message (cid:argus-logo in the layout)
            // because the app's own URL is office-IP-only.
            $logo = \App\Support\Branding::path('header');
            if (is_file($logo)) {
                $mailer->addEmbeddedImage($logo, 'argus-logo', 'argus-logo.png', PHPMailer::ENCODING_BASE64, 'image/png');
            }
            $mailer->AltBody = $text;
            $mailer->send();
        } catch (\Throwable $e) {
            $ok = false;
            $error = substr($e->getMessage(), 0, 255);
        }

        $this->log($template, $toEmail, $subject, $requestId, $ok, $error, $delivery);
        return $ok;
    }

    /** to_email is always the real recipient; delivery says where it actually went (NULL = delivered normally). */
    private function log(string $template, string $toEmail, string $subject, ?int $requestId, bool $ok, ?string $error, ?string $delivery = null): void
    {
        $params = [
            'template'   => $template,
            'to_email'   => $toEmail,
            'request_id' => $requestId,
            'subject'    => $subject,
            'ok'         => $ok ? 1 : 0,
            'error'      => $error,
        ];
        try {
            Db::conn()->prepare(
                'INSERT INTO email_log (template, to_email, request_id, subject, ok, error, delivery)
                 VALUES (:template, :to_email, :request_id, :subject, :ok, :error, :delivery)'
            )->execute($params + ['delivery' => $delivery]);
        } catch (\PDOException) {
            // delivery column not migrated yet — log without it rather than fail the send.
            Db::conn()->prepare(
                'INSERT INTO email_log (template, to_email, request_id, subject, ok, error)
                 VALUES (:template, :to_email, :request_id, :subject, :ok, :error)'
            )->execute($params);
        }
    }

    /** Recent email_log rows for the Administration screen. */
    public static function recentLog(int $limit = 50): array
    {
        try {
            return Db::conn()->query('SELECT * FROM email_log ORDER BY sent_at DESC, id DESC LIMIT ' . max(1, min($limit, 200)))->fetchAll();
        } catch (\PDOException) {
            return [];
        }
    }
}
