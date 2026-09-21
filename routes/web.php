<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\RateLimiter;

// ============================================================
// PROTECTED DEV ROUTES — only accessible with ?key=SECRET
// ============================================================
$devKey = env('DEV_ROUTE_KEY', 'change-me-to-something-random');

$guardDevRoute = function () use ($devKey) {
    if (request()->query('key') !== $devKey) {
        abort(404);
    }
};

// ============================================================
// TASKS + LEADS INDEX
// ============================================================

Route::get('/tasks', [\App\Http\Controllers\TaskController::class, 'index'])->name('tasks.index');
Route::get('/leads', [\App\Http\Controllers\LeadIndexController::class, 'index'])->name('leads.index');
Route::get('/my-leads', [\App\Http\Controllers\LeadIndexController::class, 'mine'])->name('leads.mine');

// ============================================================
// PUBLIC WEBSITE INTAKE (no auth, rate limited)
// ============================================================
Route::get('/api/projects/search', [\App\Http\Controllers\ProjectController::class, 'search'])
    ->name('projects.search');
Route::post('/api/leads',    [\App\Http\Controllers\PublicLeadController::class, 'intake'])->name('public.leads.intake');
Route::options('/api/leads', [\App\Http\Controllers\PublicLeadController::class, 'preflight']);

// ============================================================
// META LEAD ADS WEBHOOK (public — Meta calls this)
// ============================================================

Route::get('/webhooks/meta/lead',  [\App\Http\Controllers\MetaWebhookController::class, 'verify']);
Route::post('/webhooks/meta/lead', [\App\Http\Controllers\MetaWebhookController::class, 'handle']);

// ============================================================
// PASSWORD RESET (public)
// ============================================================

Route::get('/forgot-password',        [\App\Http\Controllers\PasswordResetController::class, 'showForgot'])->name('password.request');
Route::post('/forgot-password',       [\App\Http\Controllers\PasswordResetController::class, 'sendResetLink'])->name('password.email');
Route::get('/reset-password/{token}', [\App\Http\Controllers\PasswordResetController::class, 'showReset'])->name('password.reset');
Route::post('/reset-password',        [\App\Http\Controllers\PasswordResetController::class, 'resetPassword'])->name('password.update');

// ============================================================
// LOGIN & LOGOUT
// ============================================================

Route::match(['get', 'post'], '/login', function (Request $request) {
    if (session('user_id') && $request->isMethod('get')) {
        return redirect('/');
    }

    if ($request->isMethod('post')) {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $rateKey = 'login:' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => "Too many login attempts. Try again in {$seconds} seconds."]);
        }

        $user = DB::table('users')->where('email', $request->email)->first();

        if ($user && password_verify($request->password, $user->password)) {
            RateLimiter::clear($rateKey);

            app(\App\Services\AuditLogService::class)->record(
                'authentication',
                'Login successful',
                $request,
                ['email' => $user->email],
                'User',
                $user->id,
                (int) $user->id,
                $user->name,
                $user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role,
                302
            );

            $request->session()->regenerate();

            session([
                'user_id'   => $user->id,
                'user_name' => $user->name,
                'user_role' => $user->role,
            ]);

            return redirect('/');
        }

        app(\App\Services\AuditLogService::class)->record(
            'authentication',
            'Login failed',
            $request,
            ['email' => $request->input('email')],
            'User',
            $user?->id,
            null,
            null,
            null,
            302
        );

        RateLimiter::hit($rateKey, 60);

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => '❌ Invalid email or password.']);
    }

    return view('auth.login');
});

Route::post('/logout', function () {
    Session::flush();
    return redirect('/login');
})->name('logout');

Route::get('/logout', function () {
    return redirect('/');
});

// ============================================================
// ATTENDANCE — check-in / check-out / manager override
// ============================================================
Route::post('/attendance/check-in',        [\App\Http\Controllers\AttendanceController::class, 'checkIn'])->name('attendance.checkIn');
Route::post('/attendance/check-out',       [\App\Http\Controllers\AttendanceController::class, 'checkOut'])->name('attendance.checkOut');
Route::post('/attendance/force-check-out', [\App\Http\Controllers\AttendanceController::class, 'forceCheckOut'])->name('attendance.forceCheckOut');
Route::get('/attendance',                  [\App\Http\Controllers\AttendanceController::class, 'index'])->name('attendance.index');
Route::post('/attendance/manual-check-in', [\App\Http\Controllers\AttendanceController::class, 'manualCheckIn'])->name('attendance.manualCheckIn');

// ============================================================
// CONTACTS + TELECALLING
// ============================================================

Route::get('/contacts',                       [\App\Http\Controllers\ContactController::class, 'index'])->name('contacts.index');
Route::get('/contacts/dialer',                [\App\Http\Controllers\ContactCallController::class, 'dialer'])->name('contacts.dialer');
Route::get('/contacts/{id}',                  [\App\Http\Controllers\ContactController::class, 'show'])->name('contacts.show')->where('id', '[0-9]+');
Route::post('/contacts/{id}/assign',          [\App\Http\Controllers\ContactController::class, 'assign'])->name('contacts.assign')->where('id', '[0-9]+');
Route::post('/contacts/{id}/promote',         [\App\Http\Controllers\ContactController::class, 'promote'])->name('contacts.promote')->where('id', '[0-9]+');

Route::post('/calls/log',                     [\App\Http\Controllers\ContactCallController::class, 'log'])->name('calls.log');
Route::get('/calls/recent',                   [\App\Http\Controllers\ContactCallController::class, 'recent'])->name('calls.recent');

Route::get('/contacts/import',                [\App\Http\Controllers\ContactImportController::class, 'index'])->name('contacts.import.index');
Route::get('/contacts/import/sample.csv',    [\App\Http\Controllers\ContactImportController::class, 'downloadSample'])->name('contacts.import.sample');
Route::post('/contacts/import/preview',       [\App\Http\Controllers\ContactImportController::class, 'preview'])->name('contacts.import.preview');
Route::post('/contacts/import/execute',       [\App\Http\Controllers\ContactImportController::class, 'execute'])->name('contacts.import.execute');
Route::get('/contacts/import/summary/{id}',   [\App\Http\Controllers\ContactImportController::class, 'summary'])->name('contacts.import.summary');
Route::get('/contacts/import/{id}/errors.csv',[\App\Http\Controllers\ContactImportController::class, 'downloadErrors'])->name('contacts.import.errors');

Route::get('/reports/telecalling',            [\App\Http\Controllers\TelecallingReportController::class, 'index'])->name('reports.telecalling');

// ============================================================
// LEAD + PROJECT + ACTIVITY + FOLLOWUP ACTIONS
// ============================================================

Route::post('/leads/tags/save',      [\App\Http\Controllers\LeadTagController::class,  'save'])->name('leads.tags.save');
Route::post('/leads/tags/confirm',   [\App\Http\Controllers\LeadTagController::class,  'confirm'])->name('leads.tags.confirm');
Route::post('/leads/add-project-interest', [\App\Http\Controllers\LeadController::class, 'addProjectInterest'])->name('leads.addProjectInterest');
Route::post('/leads/record-site-visit', [\App\Http\Controllers\LeadController::class, 'recordSiteVisit'])->name('leads.recordSiteVisit');
Route::post('/assign-project',       [\App\Http\Controllers\LeadController::class,     'assignProject'])->name('leads.assignProject');
Route::post('/leads/assign-agent',   [\App\Http\Controllers\LeadController::class,     'assignAgent'])->name('leads.assignAgent');
Route::post('/leads/unshare-agent',  [\App\Http\Controllers\LeadController::class,     'unshareAgent'])->name('leads.unshareAgent');
Route::post('/leads/external-share', [\App\Http\Controllers\ExternalShareController::class, 'store'])->name('leads.externalShare');
Route::post('/leads/revive',         [\App\Http\Controllers\LeadController::class,     'revive'])->name('leads.revive');
Route::post('/leads/status',         [\App\Http\Controllers\LeadController::class,     'updateStatus'])->name('leads.updateStatus');
Route::post('/leads/lost-reason',     [\App\Http\Controllers\LeadController::class,     'updateLostReason'])->name('leads.updateLostReason');
Route::post('/leads/correct-to-lost', [\App\Http\Controllers\LeadController::class, 'correctToLostFromOutcome'])->name('leads.correctToLostFromOutcome');
Route::post('/leads/lost-reason/bulk',[\App\Http\Controllers\LeadIndexController::class,'bulkUpdateLostReason'])->name('leads.bulkUpdateLostReason');
Route::post('/leads/update-booking', [\App\Http\Controllers\LeadController::class,     'updateBooking'])->name('leads.updateBooking');
Route::post('/leads',                [\App\Http\Controllers\LeadController::class,     'store'])->name('leads.store');
Route::match(['GET','POST'], '/device-share', [\App\Http\Controllers\DeviceLeadImportController::class, 'share'])->name('device.share');
Route::get('/device-share/native', [\App\Http\Controllers\DeviceLeadImportController::class, 'native'])->name('device.share.native');

Route::get('/projects',              [\App\Http\Controllers\ProjectController::class,  'index'])->name('projects.index');
Route::post('/projects',             [\App\Http\Controllers\ProjectController::class,  'store'])->name('projects.store');

Route::post('/activities',           [\App\Http\Controllers\ActivityController::class, 'store'])->name('activities.store');
Route::post('/leads/customer-information', [\App\Http\Controllers\ActivityController::class, 'storeCustomerInformation'])->name('leads.customerInformation');

Route::post('/followups',            [\App\Http\Controllers\FollowupController::class, 'store'])->name('followups.store');
Route::post('/followups/done',       [\App\Http\Controllers\FollowupController::class, 'markDone'])->name('followups.done');
Route::post('/followups/complete',   [\App\Http\Controllers\FollowupController::class, 'completeWithActivity'])->name('followups.complete');
Route::post('/followups/share-site-team', [\App\Http\Controllers\FollowupController::class, 'shareToSiteTeam'])->name('followups.shareSiteTeam');
Route::post('/followups/handover-agent', [\App\Http\Controllers\FollowupController::class, 'handoverToAgent'])->name('followups.handoverAgent');
Route::post('/followups/dispose', [\App\Http\Controllers\FollowupController::class, 'dispose'])->name('followups.dispose');

// ============================================================
// MODALS
// ============================================================

Route::get('/modals/task-share-lead/handover-options', [\App\Http\Controllers\ModalController::class, 'handoverOptions'])->name('modals.taskShareLead.handoverOptions');
Route::get('/modals/{name}', [\App\Http\Controllers\ModalController::class, 'show'])->name('modals.show');

// ============================================================
// NOTIFICATIONS
// ============================================================

Route::get('/notifications',              [\App\Http\Controllers\NotificationController::class, 'index']);
Route::get('/notifications/panel',        [\App\Http\Controllers\NotificationController::class, 'panel']);
Route::get('/notifications/unread-count', [\App\Http\Controllers\NotificationController::class, 'unreadCount']);
Route::get('/notifications/push-key',     [\App\Http\Controllers\NotificationController::class, 'pushKey']);
Route::get('/notifications/push-status', [\App\Http\Controllers\NotificationController::class, 'pushStatus']);
Route::post('/notifications/push-test', [\App\Http\Controllers\NotificationController::class, 'pushTest']);
Route::post('/notifications/push-subscribe', [\App\Http\Controllers\NotificationController::class, 'pushSubscribe']);
Route::delete('/notifications/push-subscribe', [\App\Http\Controllers\NotificationController::class, 'pushUnsubscribe']);
Route::post('/notifications/read-all',    [\App\Http\Controllers\NotificationController::class, 'markAllRead']);
Route::post('/notifications/{id}/read',   [\App\Http\Controllers\NotificationController::class, 'markRead']);
Route::post('/notifications/{id}/delete', [\App\Http\Controllers\NotificationController::class, 'destroy']);

// ============================================================
// CSV IMPORT (admin only)
// ============================================================

Route::get('/import',                  [\App\Http\Controllers\ImportController::class, 'index'])->name('import.index');
Route::post('/import/preview',         [\App\Http\Controllers\ImportController::class, 'preview'])->name('import.preview');
Route::post('/import/execute',         [\App\Http\Controllers\ImportController::class, 'execute'])->name('import.execute');
Route::get('/import/summary/{id}',     [\App\Http\Controllers\ImportController::class, 'summary'])->name('import.summary');
Route::get('/import/{id}/errors.csv',  [\App\Http\Controllers\ImportController::class, 'downloadErrors'])->name('import.errors');

// ============================================================
// ADMIN — DELETE LEAD (admin-only, permanent, two-step)
// ============================================================
Route::get('/admin/delete-lead',          [\App\Http\Controllers\DeleteLeadController::class, 'index'])->name('admin.delete-lead');
Route::post('/admin/delete-lead/confirm', [\App\Http\Controllers\DeleteLeadController::class, 'confirm'])->name('admin.delete-lead.confirm');
// ============================================================
// ADMIN — PASTE LEAD FROM EXTERNAL TEXT
// ============================================================
Route::get('/admin/paste-lead',  [\App\Http\Controllers\PasteLeadController::class, 'show'])->name('admin.paste-lead');
Route::post('/admin/paste-lead', [\App\Http\Controllers\PasteLeadController::class, 'store'])->name('admin.paste-lead.store');
// ============================================================
// SETTINGS (admin only)
// ============================================================

Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->name('search.index');

// Customer profile
Route::get('/customers/{id}', [\App\Http\Controllers\CustomerController::class, 'show'])
    ->name('customers.show')
    ->where('id', '[0-9]+');

Route::get('/settings',          [\App\Http\Controllers\SettingsController::class, 'index'])->name('settings.index');
Route::post('/settings/general', [\App\Http\Controllers\SettingsController::class, 'saveGeneral'])->name('settings.saveGeneral');
// ---- Facebook Lead Ads settings ----

Route::post('/settings/facebook-leads/token',    [\App\Http\Controllers\FacebookLeadSettingsController::class, 'saveToken'])->name('settings.facebook-leads.token');
Route::post('/settings/facebook-leads/refresh-pages', [\App\Http\Controllers\FacebookLeadSettingsController::class, 'refreshPages'])->name('settings.facebook-leads.refresh-pages');
Route::post('/settings/facebook-leads/connect-page',  [\App\Http\Controllers\FacebookLeadSettingsController::class, 'connectPage'])->name('settings.facebook-leads.connect-page');
Route::post('/settings/facebook-leads/subscribe-all', [\App\Http\Controllers\FacebookLeadSettingsController::class, 'subscribeAll'])->name('settings.facebook-leads.subscribe-all');
Route::post('/settings/facebook-leads/refresh-forms', [\App\Http\Controllers\FacebookLeadSettingsController::class, 'refreshForms'])->name('settings.facebook-leads.refresh-forms');
Route::post('/settings/facebook-leads/map',           [\App\Http\Controllers\FacebookLeadSettingsController::class, 'saveMapping'])->name('settings.facebook-leads.map');

Route::post('/settings/website-intake',            [\App\Http\Controllers\SettingsController::class, 'saveWebsiteIntake'])->name('settings.saveWebsiteIntake');
Route::post('/settings/website-intake/regenerate', [\App\Http\Controllers\SettingsController::class, 'regenerateApiKey'])->name('settings.regenerateApiKey');

Route::get('/auth/facebook',                    [\App\Http\Controllers\FacebookController::class, 'redirect'])->name('facebook.redirect');
Route::get('/auth/facebook/callback',           [\App\Http\Controllers\FacebookController::class, 'callback'])->name('facebook.callback');
Route::post('/settings/facebook/select-page',   [\App\Http\Controllers\FacebookController::class, 'selectPage'])->name('facebook.selectPage');
Route::post('/settings/facebook/disconnect',    [\App\Http\Controllers\FacebookController::class, 'disconnect'])->name('facebook.disconnect');
Route::post('/settings/facebook/refresh-pages', [\App\Http\Controllers\FacebookController::class, 'refreshPages'])->name('facebook.refreshPages');

// Super Admin — Delegated Access
Route::get('/settings/delegated-access/{id?}', [\App\Http\Controllers\DelegatedAccessController::class, 'index'])
    ->where('id', '[0-9]+')
    ->name('settings.delegated-access');
Route::post('/settings/delegated-access/save/{id?}', [\App\Http\Controllers\DelegatedAccessController::class, 'save'])
    ->where('id', '[0-9]+')
    ->name('settings.delegated-access.save');
Route::post('/settings/delegated-access/{id}/toggle', [\App\Http\Controllers\DelegatedAccessController::class, 'toggle'])
    ->where('id', '[0-9]+')
    ->name('settings.delegated-access.toggle');

Route::post('/settings/teams/save/{id?}',  [\App\Http\Controllers\SettingsController::class, 'saveTeam'])->name('settings.saveTeam');
Route::post('/settings/teams/{id}/toggle', [\App\Http\Controllers\SettingsController::class, 'toggleTeam'])->name('settings.toggleTeam');
Route::post('/settings/teams/{id}/delete', [\App\Http\Controllers\SettingsController::class, 'destroyTeam'])->name('settings.destroyTeam');

Route::post('/settings/managers/save/{id?}',  [\App\Http\Controllers\ManagerController::class, 'save'])->name('managers.save');
Route::post('/settings/managers/{id}/toggle', [\App\Http\Controllers\ManagerController::class, 'toggle'])->name('managers.toggle');

// Personal / Business WhatsApp identities for every CRM user
Route::get('/my-whatsapp', [\App\Http\Controllers\WhatsAppAccountController::class, 'index'])->name('whatsapp.accounts');
Route::post('/my-whatsapp', [\App\Http\Controllers\WhatsAppAccountController::class, 'update'])->name('whatsapp.accounts.update');
Route::get('/my-whatsapp/accounts', [\App\Http\Controllers\WhatsAppAccountController::class, 'accounts'])->name('whatsapp.accounts.json');
Route::post('/my-whatsapp/open', [\App\Http\Controllers\WhatsAppAccountController::class, 'open'])->name('whatsapp.open');
Route::post('/team-status/nudge', [\App\Http\Controllers\NudgeController::class, 'open'])->name('team-status.nudge');

Route::post('/settings/agents/save/{id?}',      [\App\Http\Controllers\AgentController::class, 'save'])->name('agents.save');
Route::post('/settings/agents/{id}/toggle',     [\App\Http\Controllers\AgentController::class, 'toggle'])->name('agents.toggle');
Route::post('/settings/agents/{id}/reset-load', [\App\Http\Controllers\AgentController::class, 'resetLoad'])->name('agents.resetLoad');

Route::post('/settings/projects/{id}/routing/agent',        [\App\Http\Controllers\ProjectRoutingController::class, 'addAgent'])->name('projects.routing.addAgent');
Route::post('/settings/projects/{id}/routing/team',         [\App\Http\Controllers\ProjectRoutingController::class, 'addTeam'])->name('projects.routing.addTeam');
Route::post('/settings/projects/{id}/routing/agent/remove', [\App\Http\Controllers\ProjectRoutingController::class, 'removeAgent'])->name('projects.routing.removeAgent');
Route::post('/settings/projects/{id}/routing/team/remove',  [\App\Http\Controllers\ProjectRoutingController::class, 'removeTeam'])->name('projects.routing.removeTeam');
Route::post('/settings/projects/{id}/routing/sync',         [\App\Http\Controllers\ProjectRoutingController::class, 'sync'])->name('projects.routing.sync');
Route::post('/settings/routing/strict',                     [\App\Http\Controllers\ProjectRoutingController::class, 'saveStrict'])->name('settings.routing.strict');
Route::post('/settings/routing/bulk-team',                  [\App\Http\Controllers\ProjectRoutingController::class, 'bulkAssignTeam'])->name('settings.routing.bulkTeam');
// Bulk assign a team member to all unassigned projects of a team
Route::post('/settings/routing/bulk-assign', [\App\Http\Controllers\ProjectRoutingController::class, 'bulkAssign'])->name('settings.routing.bulkAssign');
Route::get('/settings/routing/team-members/{teamId}', [\App\Http\Controllers\ProjectRoutingController::class, 'teamMembers'])
    ->name('settings.routing.teamMembers');

Route::post('/projects/{id}/update',            [\App\Http\Controllers\ProjectController::class,  'update'])->name('projects.update');
Route::post('/settings/users/{id}/change-role', [\App\Http\Controllers\UserRoleController::class, 'change'])->name('users.changeRole');

// ---- Generic {type} routes (LAST) ----
Route::post('/settings/{type}/save/{id?}',      [\App\Http\Controllers\SettingsController::class, 'save'])->name('settings.save');
Route::post('/settings/{type}/{id}/toggle',     [\App\Http\Controllers\SettingsController::class, 'toggle'])->name('settings.toggle');
Route::post('/settings/{type}/{id}/delete',     [\App\Http\Controllers\SettingsController::class, 'destroy'])->name('settings.destroy');
Route::post('/settings/{type}/{id}/move/{dir}', [\App\Http\Controllers\SettingsController::class, 'move'])->name('settings.move');

// ============================================================
// REPORTS + EXPORT
// ============================================================

Route::get('/reports',           [\App\Http\Controllers\ReportController::class, 'index'])->name('reports.index');
Route::get('/reports/brokerage', [\App\Http\Controllers\ReportController::class, 'brokerage'])->name('reports.brokerage');
Route::get('/export/leads',      [\App\Http\Controllers\ExportController::class, 'leads'])->name('export.leads');

Route::get('/reports/team',       [\App\Http\Controllers\TeamReportController::class, 'teamPerformance'])->name('reports.team');
Route::get('/reports/scorecard',  [\App\Http\Controllers\TeamReportController::class, 'agentScorecard'])->name('reports.scorecard');
Route::get('/reports/crm-health', [\App\Http\Controllers\TeamReportController::class, 'crmHealth'])->name('reports.crmHealth');
Route::get('/reports/user-activity', [\App\Http\Controllers\UserActivityReportController::class, 'index'])->name('reports.user-activity');
Route::get('/admin/timeline-intelligence', [\App\Http\Controllers\TimelineIntelligenceController::class, 'index'])->name('admin.timeline-intelligence');
Route::get('/admin/timeline-intelligence/lead/{id}', [\App\Http\Controllers\TimelineIntelligenceController::class, 'lead'])->name('admin.timeline-intelligence.lead')->where('id', '[0-9]+');
Route::get('/admin/audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index'])->name('admin.audit-logs');
Route::get('/admin/audit-logs/verify', [\App\Http\Controllers\AuditLogController::class, 'verify'])->name('admin.audit-logs.verify');
Route::post('/admin/audit-logs/initialize', [\App\Http\Controllers\AuditLogController::class, 'initialize'])->name('admin.audit-logs.initialize');
Route::get('/admin/booking-control', [\App\Http\Controllers\BookingControlController::class, 'index'])->name('admin.booking-control');
Route::get('/admin/incentives', [\App\Http\Controllers\IncentiveController::class, 'index'])->name('admin.incentives');
Route::post('/admin/incentives/config', [\App\Http\Controllers\IncentiveController::class, 'saveConfig'])->name('admin.incentives.config');
Route::post('/admin/incentives/agents/{id}', [\App\Http\Controllers\IncentiveController::class, 'saveAgent'])->name('admin.incentives.agents.save')->where('id', '[0-9]+');
Route::post('/admin/incentives/agents/{id}/salary-history', [\App\Http\Controllers\IncentiveController::class, 'saveSalaryHistory'])->name('admin.incentives.agents.salaryHistory')->where('id', '[0-9]+');
Route::post('/admin/incentives/deals', [\App\Http\Controllers\IncentiveController::class, 'saveDeal'])->name('admin.incentives.deals.create');
Route::post('/admin/incentives/deals/{id}', [\App\Http\Controllers\IncentiveController::class, 'saveDeal'])->name('admin.incentives.deals.update')->where('id', '[0-9]+');
Route::post('/admin/incentives/deals/{id}/collections', [\App\Http\Controllers\IncentiveController::class, 'addCollection'])->name('admin.incentives.collections.create')->where('id', '[0-9]+');
Route::post('/admin/incentives/deals/{id}/reconcile', [\App\Http\Controllers\IncentiveController::class, 'reconcileDeal'])->name('admin.incentives.deals.reconcile')->where('id', '[0-9]+');
Route::post('/admin/incentives/annual-true-up', [\App\Http\Controllers\IncentiveController::class, 'runAnnualTrueUp'])->name('admin.incentives.annualTrueUp');
Route::post('/admin/incentives/agents/{id}/exit-settlement', [\App\Http\Controllers\IncentiveController::class, 'runExitSettlement'])->name('admin.incentives.exitSettlement')->where('id', '[0-9]+');
Route::post('/admin/booking-control/{id}/approve', [\App\Http\Controllers\BookingControlController::class, 'approve'])->name('admin.booking-control.approve')->where('id', '[0-9]+');
Route::post('/admin/booking-control/{id}/reject', [\App\Http\Controllers\BookingControlController::class, 'reject'])->name('admin.booking-control.reject')->where('id', '[0-9]+');

// ============================================================
// MAIN CRM PAGES
// ============================================================

Route::get('/admin/work-summary', [\App\Http\Controllers\SuperAdminWorkSummaryController::class, 'index'])->name('admin.workSummary');
Route::get('/admin/work-summary/reconciliation/{agentId}', [\App\Http\Controllers\SuperAdminWorkSummaryController::class, 'reconciliation'])->name('admin.workSummary.reconciliation');
Route::get('/admin/work-summary/{agentId}/{metric}', [\App\Http\Controllers\SuperAdminWorkSummaryController::class, 'detail'])->name('admin.workSummary.detail');

Route::get('/',           [\App\Http\Controllers\DashboardController::class, 'index']);
Route::get('/team-status', [\App\Http\Controllers\TeamStatusController::class, 'index'])->name('team-status.index');
Route::get('/leads/{id}', [\App\Http\Controllers\LeadController::class,     'show'])->name('leads.show');

// ============================================================
// MY TEAM (Team Managers + Admins)
// ============================================================

Route::get('/my-team',                          [\App\Http\Controllers\MyTeamController::class, 'index'])->name('my-team.index');
Route::post('/my-team/agents/save/{id?}',       [\App\Http\Controllers\MyTeamController::class, 'saveAgent'])->name('my-team.agents.save');
Route::post('/my-team/agents/{id}/toggle',      [\App\Http\Controllers\MyTeamController::class, 'toggleAgent'])->name('my-team.agents.toggle');
Route::post('/my-team/agents/{id}/reset-load',  [\App\Http\Controllers\MyTeamController::class, 'resetAgentLoad'])->name('my-team.agents.resetLoad');
Route::post('/my-team/agents/{id}/remove',      [\App\Http\Controllers\MyTeamController::class, 'removeAgent'])->name('my-team.agents.remove');
Route::post('/my-team/projects/{id}/sync',      [\App\Http\Controllers\MyTeamController::class, 'syncProjectRouting'])->name('my-team.projects.sync');

// ============================================================
// DEV / DEBUG ROUTES — ALL protected by ?key=SECRET
// ============================================================
Route::get('/test-fix-stuck-statuses', function () use ($guardDevRoute) {
    $guardDevRoute();

    $apply = request()->boolean('apply');
    $out   = [];

    $settings = app(\App\Services\SettingsService::class);
    $statusService = app(\App\Services\LeadStatusService::class);

    $leads = \App\Models\Lead::whereNotIn('status', ['booking', 'lost'])
        ->orderBy('id')
        ->get();

    $fixable = 0;
    $skipped = 0;

    foreach ($leads as $lead) {
        $activity = \App\Models\Activity::where('lead_id', $lead->id)
            ->whereNotNull('outcome_key')
            ->orderByDesc('logged_at')
            ->first();

        if (! $activity) { $skipped++; continue; }

        $outcome = \App\Models\Config\CallOutcome::where('key', $activity->outcome_key)->first();
        if (! $outcome || ! $outcome->suggestedStatus) { $skipped++; continue; }

        $targetKey = $outcome->suggestedStatus->key;

        if ($targetKey === $lead->statusKey()) { $skipped++; continue; }

        $allowed = $settings->allowedTransitionsFrom($lead->statusKey());
        if (! in_array($targetKey, $allowed, true)) {
            $out[] = "· [{$lead->id}] {$lead->customer_name}: {$lead->statusKey()} → {$targetKey}  (NOT ALLOWED — skipped)";
            $skipped++;
            continue;
        }

        $out[] = "→ [{$lead->id}] {$lead->customer_name}: {$lead->statusKey()} → {$targetKey}  (last outcome: {$activity->outcome_key})";

        if ($apply) {
            try {
                \Illuminate\Support\Facades\DB::transaction(function () use ($lead, $targetKey, $statusService) {
                    $statusService->change(
                        $lead,
                        $targetKey,
                        null,
                        null,
                        'Auto-advanced by admin fix tool',
                        true,
                        [],
                        []
                    );
                });
            } catch (\Throwable $e) {
                $out[] = "   ❌ Failed: " . $e->getMessage();
                continue;
            }
        }
        $fixable++;
    }

    $out[] = '';
    $out[] = $apply
        ? "✅ Applied. Fixed {$fixable} lead(s), skipped {$skipped}."
        : "Dry-run. Would fix {$fixable}, skipped {$skipped}. Add &apply=1 to actually run.";

    return '<pre>' . implode("\n", $out) . '</pre>';
});

Route::get('/clear-config', function () use ($guardDevRoute) {
    $guardDevRoute();
    \Illuminate\Support\Facades\Artisan::call('config:clear');
    \Illuminate\Support\Facades\Artisan::call('route:clear');
    \Illuminate\Support\Facades\Artisan::call('view:clear');
    \Illuminate\Support\Facades\Artisan::call('cache:clear');
    return "✅ Config + Route + View + App caches cleared.";
});

Route::get('/admin/sync-manager-agents', function () use ($guardDevRoute) {
    $guardDevRoute();

    $managers = \App\Models\User::where('role', 'team_manager')->get();
    $created  = 0;
    $skipped  = 0;

    foreach ($managers as $u) {
        if (\App\Models\Agent::where('user_id', $u->id)->exists()) {
            $skipped++;
            continue;
        }

        \App\Models\Agent::create([
            'user_id'         => $u->id,
            'phone'           => null,
            'max_daily_leads' => 10,
            'current_load'    => 0,
            'status'          => 'active',
        ]);
        $created++;
    }

    return '<pre>Team managers scanned: ' . $managers->count() . "\n"
         . 'Agent records created: ' . $created . "\n"
         . 'Already had agent record: ' . $skipped . '</pre>';
});

Route::get('/admin/flush-settings', function () use ($guardDevRoute) {
    $guardDevRoute();
    app(\App\Services\SettingsService::class)->flush();
    return '✅ Settings cache flushed.';
});

Route::get('/admin/backfill-visits', function () use ($guardDevRoute) {
    $guardDevRoute();
    \Artisan::call('leads:backfill-visit-followups');
    return '<pre>' . \Artisan::output() . '</pre>';
});

Route::get('/test-outcomes', function (\Illuminate\Http\Request $r) use ($guardDevRoute) {
    $guardDevRoute();

    $s   = app(\App\Services\SettingsService::class);
    $ctx = $r->input('context');
    $outcomes = $ctx
        ? $s->callOutcomesForContext($ctx)
        : $s->callOutcomes(true);

    $out = ['<h3>Context: ' . ($ctx ?: '(all)') . '</h3><ul>'];
    foreach ($outcomes as $o) {
        $out[] = '<li><code>' . $o->key . '</code> — ' . $o->label
               . ' <em>[context: ' . ($o->context_action_key ?: 'NULL') . ']</em></li>';
    }
    $out[] = '</ul>';
    return implode('', $out);
});

Route::get('/test-config', function () use ($guardDevRoute) {
    $guardDevRoute();

    $s = app(\App\Services\SettingsService::class);
    $out = [];
    $out[] = '<h2>✅ DB-Driven Config</h2>';

    $out[] = '<h3>Lead Statuses</h3>';
    foreach ($s->statuses() as $st) {
        $transitions = $s->allowedTransitionsFrom($st->key);
        $out[] = sprintf(
            '<code>%s</code> → <strong>%s</strong> (color <em>%s</em>, final=%s) → allowed: [%s]',
            $st->key, $st->label, $st->color,
            $st->is_final ? 'yes' : 'no',
            implode(', ', $transitions) ?: '—'
        );
    }

    $out[] = '<h3>Activity Types</h3>';
    foreach ($s->activityTypes() as $a) {
        $out[] = sprintf('%s <code>%s</code> → %s (requires_outcome=%s)',
            $a->icon ?? '', $a->key, $a->label,
            $a->requires_outcome ? 'yes' : 'no');
    }

    $out[] = '<h3>Followup Action Types</h3>';
    foreach ($s->actionTypes() as $t) {
        $out[] = "<code>{$t->key}</code> → {$t->label}";
    }

    $out[] = '<h3>Lead Sources</h3>';
    foreach ($s->sources() as $src) {
        $out[] = "<code>{$src->key}</code> → {$src->label}";
    }

    $out[] = '<h3>Settings (key-value)</h3>';
    foreach ([
        'company_name', 'activity_log_window_minutes',
        'followup_escalation_hours', 'intelligent_engine_enabled',
        'default_brokerage_percentage',
    ] as $k) {
        $out[] = "<code>{$k}</code> = <strong>" . e($s->get($k, '—')) . "</strong>";
    }

    return '<div style="font-family:monospace;font-size:13px;line-height:1.7;padding:16px;">'
         . implode('<br>', $out) . '</div>';
});

Route::get('/test-run-command', function () use ($guardDevRoute) {
    $guardDevRoute();
    \Artisan::call('leads:ensure-next-actions');
    return '<pre>' . \Artisan::output() . '</pre>';
});

Route::get('/test-ensure-now', function () use ($guardDevRoute) {
    $guardDevRoute();
    $count = app(\App\Services\SmartFollowupService::class)->ensureAllLeadsHaveNextAction();
    return "✅ Fixed {$count} lead(s) missing a next action.";
});

Route::get('/test-reminder', function () use ($guardDevRoute) {
    $guardDevRoute();
    \Artisan::call('followup:remind');
    return "Reminder command executed. Check logs!";
});

Route::get('/test-escalate', function () use ($guardDevRoute) {
    $guardDevRoute();
    \Artisan::call('followup:escalate');
    return "Escalation command executed. Check logs!";
});

Route::get('/test-schedule-list', function () use ($guardDevRoute) {
    $guardDevRoute();
    \Illuminate\Support\Facades\Artisan::call('schedule:list');
    return '<pre>' . \Illuminate\Support\Facades\Artisan::output() . '</pre>';
});

Route::get('/test-time', function () use ($guardDevRoute) {
    $guardDevRoute();
    $now = now();
    return "<h1>Timezone Debug</h1>
            <p><strong>Server default timezone:</strong> " . date_default_timezone_get() . "</p>
            <p><strong>Config app.timezone:</strong> " . config('app.timezone', 'not set') . "</p>
            <p><strong>Current time (now()):</strong> " . $now->toDateTimeString() . "</p>
            <p><strong>Current time (UTC):</strong> " . $now->utc()->toDateTimeString() . "</p>
            <p><strong>Your local time (approx):</strong> " . date('Y-m-d H:i:s') . "</p>";
});

Route::get('/test-smtp', function () use ($guardDevRoute) {
    $guardDevRoute();

    $to = request()->query('to');

    if (! $to) {
        return 'Add ?to=your@email.com to send a test email';
    }

    try {
        \Illuminate\Support\Facades\Mail::raw(
            "This is a test email from your NPO CRM.\n\n"
            . "If you're reading this, SMTP is working. You can now use password reset.",
            function ($m) use ($to) {
                $m->to($to)->subject('NPO CRM — SMTP Test');
            }
        );

        return "✅ Email sent to <strong>{$to}</strong>. Check your inbox.";
    } catch (\Throwable $e) {
        return '<p style="color:red">❌ SMTP failed:</p><pre>' . e($e->getMessage()) . '</pre>';
    }
});

Route::get('/test-social-config', function () use ($guardDevRoute) {
    $guardDevRoute();

    $out = [];
    $out[] = '<h2>🔍 Facebook / Meta Config</h2>';

    $fb   = config('services.facebook');
    $meta = config('services.meta');

    $mask = function ($v) {
        if (! $v) return '<em style="color:#c0392b;">— NOT SET —</em>';
        $len = strlen($v);
        if ($len <= 8) return '<code>' . e($v) . '</code>';
        return '<code>' . e(substr($v, 0, 4)) . '...' . e(substr($v, -4)) . '</code> (length ' . $len . ')';
    };

    $out[] = '<h3>services.facebook</h3><ul>';
    $out[] = '<li><strong>client_id</strong>: '     . $mask($fb['client_id']     ?? null) . '</li>';
    $out[] = '<li><strong>client_secret</strong>: ' . $mask($fb['client_secret'] ?? null) . '</li>';
    $out[] = '<li><strong>redirect</strong>: <code>' . e($fb['redirect'] ?? '—') . '</code></li>';
    $out[] = '</ul>';

    $out[] = '<h3>services.meta</h3><ul>';
    $out[] = '<li><strong>app_secret</strong>: '        . $mask($meta['app_secret']        ?? null) . '</li>';
    $out[] = '<li><strong>verify_token</strong>: '      . $mask($meta['verify_token']      ?? null) . '</li>';
    $out[] = '<li><strong>page_access_token</strong>: ' . $mask($meta['page_access_token'] ?? null) . '</li>';
    $out[] = '</ul>';

    $out[] = '<h3>✅ Sanity Checks</h3><ul>';
    $out[] = '<li>Facebook client_id set? '     . (($fb['client_id']     ?? null) ? '✅' : '❌') . '</li>';
    $out[] = '<li>Facebook client_secret set? ' . (($fb['client_secret'] ?? null) ? '✅' : '❌') . '</li>';
    $out[] = '<li>Facebook redirect URL set? '  . (($fb['redirect']      ?? null) ? '✅' : '❌') . '</li>';
    $out[] = '<li>Meta app_secret set? '        . (($meta['app_secret']        ?? null) ? '✅' : '❌') . '</li>';
    $out[] = '<li>Meta verify_token set? '      . (($meta['verify_token']      ?? null) ? '✅' : '❌') . '</li>';
    $out[] = '<li>Meta page_access_token set? ' . (($meta['page_access_token'] ?? null) ? '✅' : '<em>— will be set after OAuth login —</em>') . '</li>';
    $out[] = '</ul>';

    return '<div style="font-family:monospace;font-size:13px;line-height:1.8;padding:20px;">'
         . implode('', $out) . '</div>';
});

Route::get('/admin/fix-stuck-visits', function () use ($guardDevRoute) {
    $guardDevRoute();

    $args = [];
    if (request()->boolean('apply')) $args['--apply'] = true;
    if (request('lead'))            $args['--lead']  = request('lead');
    if (request('when'))            $args['--when']  = request('when');

    \Illuminate\Support\Facades\Artisan::call('leads:fix-stuck-visits', $args);
    return '<pre>' . \Illuminate\Support\Facades\Artisan::output() . '</pre>';
});

Route::get('/admin/backfill-customers', function () use ($guardDevRoute) {
    $guardDevRoute();

    $dryRun = ! request()->boolean('apply');
    $out    = [];

    // Load all leads with the fields we need
    $leads = \App\Models\Lead::orderBy('id')->get([
        'id', 'customer_name', 'phone', 'email', 'project_id',
        'created_at', 'customer_id',
    ]);

    $out[] = "Total leads: " . $leads->count();

    /* ---------- Group by normalized phone ---------- */
    $groups   = [];
    $skipped  = 0;

    foreach ($leads as $lead) {
        $p = \App\Models\Customer::normalizePhone((string) $lead->phone);
        if (strlen($p) < 8) {
            $skipped++;
            continue;
        }
        $groups[$p][] = $lead;
    }

    $out[] = "Distinct phones: " . count($groups);
    $out[] = "Leads linked to a phone: " . array_sum(array_map('count', $groups));
    $out[] = "Leads with unusable phone: {$skipped}";
    $out[] = "";

    /* ---------- Distribution ---------- */
    $multiLeadPhones = array_filter($groups, fn ($g) => count($g) > 1);
    $out[] = "Phones with 2+ leads (multi-enquiry customers): " . count($multiLeadPhones);
    $out[] = "";

    $out[] = "Top 10 multi-enquiry customers:";
    usort($multiLeadPhones, fn ($a, $b) => count($b) <=> count($a));
    $top = array_slice($multiLeadPhones, 0, 10, true);
    foreach ($top as $phone => $group) {
        $names = collect($group)->pluck('customer_name')->unique()->take(2)->implode(' / ');
        $out[] = "  {$phone} — " . count($group) . " lead(s) — {$names}";
    }

    if ($dryRun) {
        $out[] = "";
        $out[] = "DRY RUN. Add &apply=1 to actually run.";
        return '<pre>' . implode("\n", $out) . '</pre>';
    }

    /* ---------- Execute ---------- */
    $out[] = "";
    $out[] = "RUNNING BACKFILL…";

    $created = 0;
    $linked  = 0;

    \Illuminate\Support\Facades\DB::transaction(function () use ($groups, &$created, &$linked) {
        foreach ($groups as $phone => $group) {
            $sorted  = collect($group)->sortBy('created_at');
            $oldest  = $sorted->first();
            $latest  = $sorted->last();

            // Best name = longest non-empty name
            $bestName = $sorted
                ->pluck('customer_name')
                ->filter(fn ($n) => $n && $n !== 'Unknown')
                ->sortByDesc(fn ($n) => strlen($n))
                ->first() ?: 'Unknown';

            // Best email = first non-empty
            $bestEmail = $sorted->pluck('email')->filter()->first();

            $customer = \App\Models\Customer::updateOrCreate(
                ['phone' => $phone],
                [
                    'name'            => $bestName,
                    'email'           => $bestEmail,
                    'first_seen_at'   => $oldest->created_at,
                    'last_seen_at'    => $latest->created_at,
                    'total_enquiries' => count($group),
                ]
            );
            $created++;

            foreach ($group as $lead) {
                \App\Models\Lead::where('id', $lead->id)
                    ->update(['customer_id' => $customer->id]);
                $linked++;
            }
        }
    });

    $out[] = "Customers created/updated: {$created}";
    $out[] = "Leads linked: {$linked}";
    $out[] = "";
    $out[] = "✅ Done.";

    return '<pre>' . implode("\n", $out) . '</pre>';
});

Route::get('/debug-access', function () use ($guardDevRoute) {
    $guardDevRoute();

    $svc  = app(\App\Services\AccessService::class);
    $role = session('user_role');
    $uid  = session('user_id');
    $flag = $uid ? \App\Models\User::where('id', $uid)->value('is_telecaller') : null;
    $vis  = app(\App\Services\SettingsService::class)->get('contacts_visibility', 'MISSING');

    return response()->json([
        'session_user_id'            => $uid,
        'session_user_role'          => $role,
        'user_is_telecaller'         => $flag,
        'settings_contacts_visibility' => $vis,
        'canAccessContacts'          => $svc->canAccessContacts(),
    ]);
});

// ============================================================
// ATTENDANCE DEBUG (dev-only)
// ============================================================
Route::get('/debug-attendance', function () use ($guardDevRoute) {
    $guardDevRoute();

    $user = \App\Models\User::find(session('user_id'));
    $agent = $user ? \App\Models\Agent::where('user_id', $user->id)->first() : null;

    $todayAttendance = null;
    if ($agent) {
        $todayAttendance = \App\Models\Attendance::where('agent_id', $agent->id)
            ->where('shift_date', now()->toDateString())
            ->first();
    }

    $offices = \App\Models\OfficeLocation::active()->get(['id', 'label', 'latitude', 'longitude', 'radius_meters']);

    return response()->json([
        'session_user_id'         => session('user_id'),
        'session_user_role'       => session('user_role'),
        'user_is_on_payroll'      => $user?->is_on_payroll,
        'requires_attendance'     => $user?->requiresAttendance(),
        'agent_id'                => $agent?->id,
        'offices'                 => $offices,
        'today_attendance'        => $todayAttendance,
    ]);
});
// ============================================================
// SESSION CHECK — cheap HEAD request used by the pageshow handler
// ============================================================
Route::get('/api/session-check', function () {
    if (! session('user_id')) {
        return response()->json(['ok' => false], 401);
    }
    return response()->json(['ok' => true]);
})->name('api.session-check');
/* Generalized Booking Financial Engine v1 */
Route::get('/admin/booking-finance', [\App\Http\Controllers\BookingFinanceController::class, 'index'])->name('admin.booking-finance');
Route::post('/admin/booking-finance/rules', [\App\Http\Controllers\BookingFinanceController::class, 'saveRule'])->name('admin.booking-finance.rules.create');
Route::post('/admin/booking-finance/rules/{id}', [\App\Http\Controllers\BookingFinanceController::class, 'saveRule'])->where('id','[0-9]+')->name('admin.booking-finance.rules.update');
Route::post('/admin/booking-finance/bookings', [\App\Http\Controllers\BookingFinanceController::class, 'saveBooking'])->name('admin.booking-finance.bookings.create');
Route::post('/admin/booking-finance/bookings/{id}', [\App\Http\Controllers\BookingFinanceController::class, 'saveBooking'])->where('id','[0-9]+')->name('admin.booking-finance.bookings.update');
Route::post('/admin/booking-finance/bookings/{id}/submit', [\App\Http\Controllers\BookingFinanceController::class, 'submit'])->where('id','[0-9]+')->name('admin.booking-finance.bookings.submit');
Route::post('/admin/booking-finance/bookings/{id}/authorize', [\App\Http\Controllers\BookingFinanceController::class, 'authorizeBooking'])->where('id','[0-9]+')->name('admin.booking-finance.bookings.authorize');
Route::post('/admin/booking-finance/bookings/{id}/brokerage-receipts', [\App\Http\Controllers\BookingFinanceController::class, 'addBrokerageReceipt'])->where('id','[0-9]+')->name('admin.booking-finance.brokerage-receipts.create');
Route::post('/admin/booking-finance/bookings/{id}/allocations', [\App\Http\Controllers\BookingFinanceController::class, 'addAllocation'])->where('id','[0-9]+')->name('admin.booking-finance.allocations.create');
Route::post('/admin/booking-finance/bookings/{id}/payables', [\App\Http\Controllers\BookingFinanceController::class, 'addPayable'])->where('id','[0-9]+')->name('admin.booking-finance.payables.create');
Route::post('/admin/booking-finance/payables/{id}/payments', [\App\Http\Controllers\BookingFinanceController::class, 'addPayment'])->where('id','[0-9]+')->name('admin.booking-finance.payments.create');
Route::post('/admin/booking-finance/payable-types', [\App\Http\Controllers\BookingFinanceController::class, 'savePayableType'])->name('admin.booking-finance.payable-types.create');
Route::post('/admin/booking-finance/payable-types/{id}', [\App\Http\Controllers\BookingFinanceController::class, 'savePayableType'])->where('id','[0-9]+')->name('admin.booking-finance.payable-types.update');
Route::post('/admin/booking-finance/salary/{agentId}', [\App\Http\Controllers\BookingFinanceController::class, 'saveSalary'])->where('agentId','[0-9]+')->name('admin.booking-finance.salary.create');
Route::post('/admin/booking-finance/import/preview', [\App\Http\Controllers\BookingFinanceController::class, 'importPreview'])->name('admin.booking-finance.import.preview');
Route::post('/admin/booking-finance/import/{batchId}/commit', [\App\Http\Controllers\BookingFinanceController::class, 'importCommit'])->where('batchId','[0-9]+')->name('admin.booking-finance.import.commit');
Route::post('/admin/booking-finance/simulate', [\App\Http\Controllers\BookingFinanceController::class, 'simulate'])->name('admin.booking-finance.simulate');
