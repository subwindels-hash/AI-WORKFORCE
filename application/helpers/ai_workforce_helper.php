<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * View-safe access to platform state (views must not reach into controllers).
 */
final class AIWorkforce_PlatformStateHelper
{
    private static ?array $state = null;

    public static function current(): array
    {
        if (self::$state === null) {
            $ci = get_instance();
            if (isset($ci->platform)) {
                self::$state = $ci->platform->state();
            } else {
                self::$state = ['tradingMode' => 'ANALYSIS_ONLY', 'killSwitch' => ['active' => true]];
            }
        }
        return self::$state;
    }
}

/** View-safe unread-notification count (broadcast + signed-in operator). */
final class AIWorkforce_NotificationsHelper
{
    public static function unreadCount(): int
    {
        $ci = get_instance();
        if (!isset($ci->platform) || !isset($ci->platform->notifications)) return 0;
        try {
            $user = $ci->session->userdata('identity');
            $userId = is_array($user) && !empty($user['id']) ? (int) $user['id'] : null;
            return (int) $ci->platform->notifications->inbox($userId, true, 1)['unread'];
        } catch (Throwable $e) {
            return 0;
        }
    }
}

/** View-safe unread direct-message count (admin replies in the member's thread). */
final class AIWorkforce_MessagesHelper
{
    public static function unreadCount(): int
    {
        $ci = get_instance();
        try {
            $user = $ci->session->userdata('identity');
            if (!is_array($user) || empty($user['id'])) return 0;
            if (!isset($ci->AIWorkforce_model) || !isset($ci->AIWorkforce_model->messages)) return 0;
            return (int) $ci->AIWorkforce_model->messages->unreadForUser((int) $user['id']);
        } catch (Throwable $e) {
            return 0;
        }
    }
}
