<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\WhatsAppAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppAccountController extends Controller
{
    public function __construct(private WhatsAppAccountService $accounts) {}

    private function user(): User
    {
        $id = (int) session('user_id');
        if ($id < 1) abort(401, 'Please login again.');
        return User::findOrFail($id);
    }

    public function index()
    {
        $user = $this->user();
        $rows = $user->whatsappAccounts()->get()->keyBy('account_type');

        return view('whatsapp.index', [
            'personal' => $rows->get('personal'),
            'business' => $rows->get('business'),
            'defaultAccountType' => $this->accounts->defaultTypeForUser($user),
        ]);
    }

    public function update(Request $request)
    {
        $user = $this->user();

        $validated = $request->validate([
            'personal' => ['nullable', 'string', 'max:30'],
            'business' => ['nullable', 'string', 'max:30'],
            'default_account_type' => ['nullable', 'in:personal,business'],
        ]);

        foreach (['personal', 'business'] as $type) {
            $raw = trim((string) ($validated[$type] ?? ''));
            if ($raw !== '' && ! $this->accounts->isValid($raw)) {
                return back()->withErrors([
                    $type => 'Enter a valid WhatsApp number with country code or a 10-digit Indian mobile number.',
                ])->withInput();
            }
        }

        DB::transaction(fn () => $this->accounts->saveForUser($user, $validated));

        return back()->with('success', '✅ WhatsApp accounts updated.');
    }

    public function accounts()
    {
        $user = $this->user();

        return response()->json([
            'default_account_type' => $this->accounts->defaultTypeForUser($user),
            'ok' => true,
            'accounts' => $this->accounts->accountsForUser($user->id)->map(fn ($a) => [
                'type'  => $a->account_type,
                'label' => $a->label(),
                'phone' => $a->phone,
            ])->values(),
        ]);
    }

    public function open(Request $request)
    {
        $user = $this->user();

        $validated = $request->validate([
            'account_type' => ['required', 'in:personal,business'],
            'phone'        => ['nullable', 'string', 'max:30'],
            'text'         => ['nullable', 'string', 'max:5000'],
        ]);

        $account = $user->whatsappAccounts()
            ->where('account_type', $validated['account_type'])
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return response()->json(['ok' => false, 'message' => 'That WhatsApp account is not configured for your CRM user.'], 422);
        }

        $recipient = $this->accounts->normalize($validated['phone'] ?? '');
        if ($recipient !== '' && ! $this->accounts->isValid($recipient)) {
            return response()->json(['ok' => false, 'message' => 'The phone number is not valid for WhatsApp.'], 422);
        }

        // Empty recipient intentionally opens WhatsApp's own contact/group picker.
        $url = $recipient !== ''
            ? 'https://wa.me/' . $recipient
            : 'https://api.whatsapp.com/send';
        if (($validated['text'] ?? '') !== '') {
            $url .= '?text=' . rawurlencode($validated['text']);
        }

        return response()->json([
            'ok' => true,
            'url' => $url,
            'account' => $account->label(),
            'account_phone' => $account->phone,
        ]);
    }
}
