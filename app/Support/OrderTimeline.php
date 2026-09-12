<?php

namespace App\Support;

use App\Enums\CustomerOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Order;

/**
 * The customer-facing 5-stage progress bar (Received → Processing →
 * Shipping → Out for Delivery → Delivered) that replaces the template's
 * generic order-tracking bar, plus the three terminal states that sit
 * outside that line — Postponed, Cancelled, Returned (Section 17's
 * order-tracking.html note, driven by Section 03's status mapping).
 *
 * Backordered is surfaced the same way as Postponed: the order is
 * paused, not moving along the line (Question 14).
 */
class OrderTimeline
{
    private const STAGES = [
        CustomerOrderStatus::OrderReceived,
        CustomerOrderStatus::Processing,
        CustomerOrderStatus::Shipping,
        CustomerOrderStatus::OutForDelivery,
        CustomerOrderStatus::Delivered,
    ];

    /**
     * @return array{
     *     stages: array<int, array{label: string, reached: bool, current: bool}>,
     *     state: string,
     *     off_track: bool,
     *     history: array<int, array{status: string, at: string|null}>
     * }
     */
    public static function for(Order $order): array
    {
        $current = $order->customer_status;
        $offTrack = ! in_array($current, self::STAGES, true);

        $reachedIndex = $offTrack
            ? self::furthestReachedIndex($order)
            : (int) array_search($current, self::STAGES, true);

        return [
            'stages' => array_map(fn (CustomerOrderStatus $stage, int $index) => [
                'label' => $stage->value,
                'reached' => $index <= $reachedIndex,
                'current' => ! $offTrack && $index === $reachedIndex,
            ], self::STAGES, array_keys(self::STAGES)),
            'state' => $current->value,
            'off_track' => $offTrack,
            'history' => $order->statusHistory
                ->sortBy('id')
                ->map(fn ($entry) => [
                    'status' => self::customerLabel($entry->to_status),
                    'at' => $entry->created_at?->toDateTimeString(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * How far a Postponed/Cancelled/Returned order actually got before it
     * left the line — read from its own status history rather than
     * guessed, so a cancelled-at-Checking order and a returned-at-delivery
     * one don't render identically.
     */
    private static function furthestReachedIndex(Order $order): int
    {
        $furthest = -1;

        foreach ($order->statusHistory as $entry) {
            $status = OrderStatus::tryFrom((string) $entry->to_status);
            if ($status === null) {
                continue;
            }

            $index = array_search($status->customerStatus(), self::STAGES, true);
            if ($index !== false && $index > $furthest) {
                $furthest = $index;
            }
        }

        return $furthest;
    }

    private static function customerLabel(?string $internalStatus): string
    {
        $status = OrderStatus::tryFrom((string) $internalStatus);

        return $status?->customerStatus()->value ?? (string) $internalStatus;
    }
}
