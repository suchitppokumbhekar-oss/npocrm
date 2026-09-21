<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Followup;
use App\Models\Lead;
use DomainException;

/**
 * Canonical work/action resolver for every CRM surface.
 *
 * Any page that presents a lead as actionable should use this service/component
 * instead of inventing its own action rules.
 */
class WorkActionService
{
    public function currentFollowup(Lead $lead): ?Followup
    {
        $role = (string) session('user_role');
        $userId = (int) session('user_id', 0);

        $query = Followup::query()
            ->where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_for')
            ->orderBy('id');

        if ($role === 'agent') {
            $agentId = (int) (Agent::where('user_id', $userId)->value('id') ?? 0);
            if (!$agentId) {
                return null;
            }
            $query->where('agent_id', $agentId);
        }

        return $query->first();
    }

    public function state(?Followup $followup): array
    {
        if (!$followup) {
            return [
                'has_followup' => false,
                'scheduled_at' => null,
                'is_future' => false,
                'is_due' => false,
                'is_overdue' => false,
            ];
        }

        $scheduledAt = $followup->scheduled_for;
        $isFuture = $scheduledAt && $scheduledAt->isFuture();
        $isOverdue = $scheduledAt && $scheduledAt->isPast();
        $isDue = !$scheduledAt || !$isFuture;

        return [
            'has_followup' => true,
            'scheduled_at' => $scheduledAt,
            'is_future' => $isFuture,
            'is_due' => $isDue,
            'is_overdue' => $isOverdue,
        ];
    }

    public function canWork(Lead $lead): bool
    {
        if ($lead->isLost() || $lead->isWon()) {
            return false;
        }
        return true;
    }


    public function assertCanAct(Followup $followup, bool $allowEarly = false): void
    {
        if ($followup->status !== 'pending') {
            throw new DomainException('This follow-up has already been completed.');
        }
        if ($followup->scheduled_for && $followup->scheduled_for->isFuture() && ! $allowEarly) {
            throw new DomainException('This follow-up is not due yet. Use Start Early if you intentionally want to act now.');
        }
    }

    public function actionLabels(): array
    {
        return [
            'call' => 'Call',
            'whatsapp' => 'WhatsApp',
            'share' => 'Share Lead',
            'done' => 'Done — Log Now',
            'early' => 'Start Early',
            'dispose' => 'Cancel Future Task',
            'history' => 'View History',
        ];
    }
}
