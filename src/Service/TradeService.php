<?php

namespace App\Service;

use App\Entity\TradeRequest;
use App\Entity\TradeTransaction;
use App\Service\Concerns\DeliversUserNotifications;

class TradeService
{
    use DeliversUserNotifications;

    public function __construct(
        private UserNotificationService $userNotificationService,
    ) {
    }

    /** Notify trade post owner when someone sends a trade offer. */
    public function sendTradeOffer(TradeRequest $tradeRequest): void
    {
        $tradePost = $tradeRequest->getTradePost();
        $receiverId = (int) $tradePost->getUser()->getId();

        $this->deliverUserNotification(
            $this->userNotificationService,
            $receiverId,
            'New Trade Offer',
            sprintf('%s wants to trade with you', $tradeRequest->getRequester()->getUsername()),
            [
                'type' => 'trade_offer',
                'tradeId' => $tradePost->getId(),
                'requestId' => $tradeRequest->getId(),
            ],
        );
    }

    /** Notify requester when the owner accepts their trade offer. */
    public function acceptTrade(TradeRequest $tradeRequest, TradeTransaction $transaction): void
    {
        $requesterId = (int) $tradeRequest->getRequester()->getId();

        $this->deliverUserNotification(
            $this->userNotificationService,
            $requesterId,
            'Trade Accepted',
            'Your trade offer was accepted. Waiting for admin verification.',
            [
                'type' => 'trade_accepted',
                'tradeId' => $tradeRequest->getTradePost()->getId(),
                'requestId' => $tradeRequest->getId(),
                'transactionId' => $transaction->getId(),
            ],
        );
    }

    /** Notify requester when the owner rejects their trade offer. */
    public function rejectTrade(TradeRequest $tradeRequest): void
    {
        $requesterId = (int) $tradeRequest->getRequester()->getId();

        $this->deliverUserNotification(
            $this->userNotificationService,
            $requesterId,
            'Trade Rejected',
            'Your trade offer was declined.',
            [
                'type' => 'trade_rejected',
                'tradeId' => $tradeRequest->getTradePost()->getId(),
                'requestId' => $tradeRequest->getId(),
            ],
        );
    }

    /** Notify other pending requesters when another offer was accepted. */
    public function notifyTradeOfferSuperseded(TradeRequest $tradeRequest): void
    {
        $requesterId = (int) $tradeRequest->getRequester()->getId();

        $this->deliverUserNotification(
            $this->userNotificationService,
            $requesterId,
            'Trade Closed',
            'Another offer was accepted for this listing.',
            [
                'type' => 'trade_superseded',
                'tradeId' => $tradeRequest->getTradePost()->getId(),
                'requestId' => $tradeRequest->getId(),
            ],
        );
    }

    /** Notify both parties when admin verifies the trade transaction. */
    public function notifyTransactionVerified(TradeTransaction $transaction): void
    {
        $tradePostId = $transaction->getTradeRequest()->getTradePost()->getId();
        $data = [
            'type' => 'trade_verified',
            'tradeId' => $tradePostId,
            'transactionId' => $transaction->getId(),
        ];

        foreach ([$transaction->getOwner(), $transaction->getRequester()] as $user) {
            $this->deliverUserNotification(
                $this->userNotificationService,
                (int) $user->getId(),
                'Trade Verified',
                'Your trade was verified by staff and is complete.',
                $data,
            );
        }
    }

    /** Notify both parties when admin rejects the trade transaction. */
    public function notifyTransactionRejected(TradeTransaction $transaction): void
    {
        $tradePostId = $transaction->getTradeRequest()->getTradePost()->getId();
        $data = [
            'type' => 'trade_rejected_admin',
            'tradeId' => $tradePostId,
            'transactionId' => $transaction->getId(),
        ];

        foreach ([$transaction->getOwner(), $transaction->getRequester()] as $user) {
            $this->deliverUserNotification(
                $this->userNotificationService,
                (int) $user->getId(),
                'Trade Not Approved',
                'Your trade could not be verified. See admin notes for details.',
                $data,
            );
        }
    }
}
