<?php

namespace App\Http\Controllers;

use App\Services\FacebookLeadService;
use Illuminate\Http\Request;

class FacebookLeadSettingsController extends Controller
{
    public function __construct(private FacebookLeadService $fb) {}

    private function guard(): void
    {
        if (session('user_role') !== 'admin') {
            abort(403);
        }
    }

    /** POST /settings/facebook-leads/token */
    public function saveToken(Request $request)
    {
        $this->guard();

        $token = trim((string) $request->input('system_user_token', ''));
        if ($token === '') {
            return back()->with('error', 'Please paste a System User token.');
        }

        try {
            $pages = $this->fb->saveSystemUserToken($token);
            return back()
                ->with('success', '✅ Token verified. ' . count($pages) . ' page(s) synced.')
                ->withFragment('facebook');
        } catch (\Throwable $e) {
            return back()
                ->with('error', '❌ ' . $e->getMessage())
                ->withFragment('facebook');
        }
    }

    /** POST /settings/facebook-leads/refresh-pages */
    public function refreshPages()
    {
        $this->guard();

        try {
            $pages = $this->fb->refreshPageTokens();
            return back()
                ->with('success', '✅ ' . count($pages) . ' page(s) refreshed.')
                ->withFragment('facebook');
        } catch (\Throwable $e) {
            return back()
                ->with('error', '❌ ' . $e->getMessage())
                ->withFragment('facebook');
        }
    }

    /** POST /settings/facebook-leads/connect-page */
    public function connectPage(Request $request)
    {
        $this->guard();

        $pageId = (string) $request->input('page_id', '');
        if ($pageId === '') {
            return back()->with('error', 'Please select a page.')->withFragment('facebook');
        }

        try {
            $this->fb->setConnectedPage($pageId);
            return back()
                ->with('success', '✅ Page connected. Click "Refresh Forms" to load the lead forms.')
                ->withFragment('facebook');
        } catch (\Throwable $e) {
            return back()->with('error', '❌ ' . $e->getMessage())->withFragment('facebook');
        }
    }

        /** POST /settings/facebook-leads/subscribe-all */
    public function subscribeAll()
    {
        $this->guard();

        try {
            $res = $this->fb->subscribeAllPages();

            $msg = '✅ Subscribed: ' . count($res['subscribed'])
                 . ' · Already active: ' . count($res['already'])
                 . ' · Failed: ' . count($res['failed']);

            return back()->with('success', $msg)->withFragment('facebook');
        } catch (\Throwable $e) {
            return back()->with('error', '❌ ' . $e->getMessage())->withFragment('facebook');
        }
    }

    /** POST /settings/facebook-leads/refresh-forms */
    public function refreshForms()
    {
        $this->guard();

        try {
            $forms = $this->fb->fetchAndCacheAllForms();
            return back()
                ->with('success', '✅ ' . count($forms) . ' form(s) loaded from all pages.')
                ->withFragment('facebook');
        } catch (\Throwable $e) {
            return back()->with('error', '❌ ' . $e->getMessage())->withFragment('facebook');
        }
    }

    /** POST /settings/facebook-leads/map — AJAX */
    public function saveMapping(Request $request)
    {
        $this->guard();

        $formId    = (string) $request->input('form_id', '');
        $projectId = $request->input('project_id');

        if ($formId === '') {
            return response()->json(['ok' => false, 'error' => 'form_id required'], 400);
        }

        $projectId = ($projectId === '' || $projectId === null) ? null : (int) $projectId;

        try {
            $this->fb->setFormProject($formId, $projectId);
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}