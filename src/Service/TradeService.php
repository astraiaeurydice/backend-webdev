<?php

namespace App\Service;

use App\Entity\TradePost;
use App\Entity\TradeRequest;
use App\Entity\TradeTransaction;
use App\Entity\User;
use App\Service\Concerns\DeliversUserNotifications;

class TradeService
{
    use DeliversUserNotifications;

    public function __construct(
        private UserNotificationService $userNotificationService,
    ) {
    }

    /** Confirm to poster + alert other users that a new trade listing exists. */
    public function notifyTradePostCreated(TradePost $tradePost): void
    {
        $owner = $tradePost->getUser();
        if (!$owner) {
            return;
        }

        $ownerId = (int) $owner->getId();
        $item = $tradePost->getItemOffered() ?? 'an item';

        $this->deliverUserNotification(
            $this->userNotificationService,
            $ownerId,
            'Trade Posted',
            sprintf('Your listing for "%s" is now live.', $item),
            [
                'type' => 'trade_post_created',
                'tradeId' => $tradePost->getId(),
            ],
        );

        $this->userNotificationService->deliverToAllUsers(
            'trade_listing_new',
            'New trade listing',
            sprintf('%s posted a trade for "%s".', $this->displayName($owner), $item),
            [
                'type' => 'trade_listing_new',
                'tradeId' => $tradePost->getId(),
            ],
            $ownerId,
        );
    }

    /** Notify trade post owner when someone sends a trade offer. */
    public function sendTradeOffer(TradeRequest $tradeRequest): void
    {
        $tradePost = $tradeRequest->getTradePost();
        $receiverId = (int) $tradePost->getUser()->getId();

        $requesterId = (int) $tradeRequest->getRequester()->getId();

        $this->deliverUserNotification(
            $this->userNotificationService,
            $receiverId,
            'New Trade Offer',
            sprintf('%s wants to trade with you', $this->displayName($tradeRequest->getRequester())),
            [
                'type' => 'trade_offer',
                'tradeId' => $tradePost->getId(),
                'requestId' => $tradeRequest->getId(),
            ],
        );

        $this->deliverUserNotification(
            $this->userNotificationService,
            $requesterId,
            'Offer Sent',
            sprintf('Your offer on "%s" was sent to the listing owner.', $tradePost->getItemOffered() ?? 'the trade'),
            [
                'type' => 'trade_offer_sent',
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

    private function displayName(?User $user): string
    {
        if (!$user) {
            return 'A collector';
        }

        $username = $user->getUsername();
        if (is_string($username) && $username !== '') {
            return $username;
        }

        $name = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $user->getEmail() ?? 'A collector';
    }
}
