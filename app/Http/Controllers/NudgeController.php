<?php

namespace App\Http\Controllers;

use App\Models\Followup;
use App\Services\NudgeService;
use Illuminate\Http\Request;

class NudgeController extends Controller
{
    public function __construct(private NudgeService $nudges) {}

    public function open(Request $request)
    {
        if (! $this->nudges->canManageNudges()) {
            abort(403, 'Only admins and team managers can nudge an agent.');
        }

        $validated = $request->validate([
            'target_type' => ['required', 'in:followup,agent'],
            'target_id' => ['required', 'integer', 'min:1'],
            'account_type' => ['required', 'in:personal,business'],
        ]);

        $payload = $validated['target_type'] === 'followup'
            ? $this->nudges->buildForFollowup(Followup::findOrFail((int) $validated['target_id']))
            : $this->nudges->buildForAgent((int) $validated['target_id']);

        $user = \App\Models\User::findOrFail((int) session('user_id'));
        $account = $user->whatsappAccounts()
            ->where('account_type', $validated['account_type'])
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return response()->json(['ok' => false, 'message' => 'That WhatsApp account is not configured for your CRM user.'], 422);
        }

        $recipient = app(\App\Services\WhatsAppAccountService::class)->normalize($payload['phone']);
        $url = 'https://wa.me/' . $recipient . '?text=' . rawurlencode($payload['message']);

        $this->nudges->recordInitiated($payload, $validated['account_type']);

        return response()->json([
            'ok' => true,
            'url' => $url,
            'message' => $payload['message'],
            'nudge_count' => $validated['target_type'] === 'followup'
                ? $this->nudges->countForFollowup((int) $validated['target_id'])
                : $this->nudges->countForAgent((int) $validated['target_id']),
        ]);
    }
}
