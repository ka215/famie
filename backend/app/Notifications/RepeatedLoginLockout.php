<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class RepeatedLoginLockout extends Notification
{
    /** @param array{group_id: int, membership_id: int, display_name: string, username: string, occurred_at: string} $details */
    public function __construct(private array $details) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->details;
    }
}
