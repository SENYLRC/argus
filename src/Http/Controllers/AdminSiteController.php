<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Http\Controller;
use App\Support\Settings;

/** Administration > Site banner: the "TEST SITE" strip shown to everyone. */
final class AdminSiteController extends Controller
{
    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'admin';
    }

    /** Site banner settings form. */
    public function index(): void
    {
        $this->requireRole(['admin']);
        $this->render('admin/site.twig', [
            'admin_section' => 'site',
            'banner_on'     => Settings::get('site_banner_on') === '1',
            'banner_text'   => Settings::get('site_banner_text'),
        ]);
    }

    /** Turn the banner on/off and set its text. */
    public function save(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();

        $on = isset($_POST['banner_on']);
        $text = trim(preg_replace('/\s+/', ' ', (string) ($_POST['banner_text'] ?? '')));
        if ($on && $text === '') {
            $this->flash('Not saved: enter the banner text, or turn the banner off.');
            $this->redirect('/admin/site');
            return;
        }
        if (mb_strlen($text) > 200) {
            $this->flash('Not saved: keep the banner to 200 characters or fewer.');
            $this->redirect('/admin/site');
            return;
        }
        Settings::set('site_banner_on', $on ? '1' : '');
        Settings::set('site_banner_text', $text);
        AuditLogger::log('setting', null, 'site_banner', $on ? "Site banner on: {$text}" : 'Site banner turned off');
        $this->flash($on ? 'Site banner saved — everyone now sees it at the top of every page.' : 'Site banner turned off.');
        $this->redirect('/admin/site');
    }
}
