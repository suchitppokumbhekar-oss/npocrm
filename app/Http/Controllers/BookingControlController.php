<?php

namespace App\Http\Controllers;

use App\Models\BookingControl;
use App\Models\Lead;
use App\Services\AccessService;
use App\Services\BookingControlService;
use Illuminate\Http\Request;

class BookingControlController extends Controller
{
    public function __construct(
        private AccessService $access,
        private BookingControlService $bookingControls,
    ) {}

    private function requireApprover(): void
    {
        if (! $this->access->isUnrestrictedAdmin()) {
            abort(403, 'Only an unrestricted Admin can access Booking Control.');
        }
    }

    public function index(Request $request)
    {
        $this->requireApprover();

        $query = BookingControl::query()
            ->with(['lead.project', 'lead.agent.user', 'requestedBy', 'approvedBy', 'rejectedBy'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END")
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        if ($request->filled('status') && in_array($request->input('status'), [
            BookingControl::PENDING,
            BookingControl::APPROVED,
            BookingControl::REJECTED,
            BookingControl::CANCELLED,
        ], true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $term = trim($request->input('q'));
            $query->whereHas('lead', function ($q) use ($term) {
                $q->where('customer_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('id', ctype_digit($term) ? (int) $term : -1);
            });
        }

        $counts = [
            'pending' => BookingControl::where('status', BookingControl::PENDING)->count(),
            'approved' => BookingControl::where('status', BookingControl::APPROVED)->count(),
            'rejected' => BookingControl::where('status', BookingControl::REJECTED)->count(),
        ];

        $controls = $query->paginate(30)->withQueryString();

        return view('admin.booking-control', compact('controls', 'counts'));
    }

    public function approve(Request $request, int $id)
    {
        $this->requireApprover();
        $validated = $request->validate(['note' => 'nullable|string|max:2000']);
        $control = BookingControl::findOrFail($id);

        try {
            $this->bookingControls->approve($control, $validated['note'] ?? null);
        } catch (\DomainException $e) {
            return back()->with('error', '🚫 ' . $e->getMessage());
        }

        return back()->with('success', '✅ Booking approved and control record updated.');
    }

    public function reject(Request $request, int $id)
    {
        $this->requireApprover();
        $validated = $request->validate(['reason' => 'required|string|min:3|max:2000']);
        $control = BookingControl::findOrFail($id);

        try {
            $this->bookingControls->reject($control, $validated['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', '🚫 ' . $e->getMessage());
        }

        return back()->with('success', 'Booking approval rejected. The requester can correct the details and resubmit.');
    }
}
