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

/** @var array<string,mixed>|null */
$GLOBALS['ai_workforce_protection_cache'] = null;

/**
 * Current Automatic Kill Switch status for views.
 *
 * Read-only: views may display the protection state, never change it. A view
 * that cannot read the state reports PAUSED rather than NORMAL, so a broken
 * status read never renders as "safe".
 *
 * @return array<string,mixed>
 */
function ai_workforce_protection_status(): array
{
    if (is_array($GLOBALS['ai_workforce_protection_cache'])) return $GLOBALS['ai_workforce_protection_cache'];
    $fallback = \AIWorkforce\TradingProtection\AutomaticProtection::unverifiedStatus('Protection state unavailable.');
    if (!function_exists('get_instance')) return $fallback;
    $ci = get_instance();
    if (!isset($ci->platform, $ci->platform->protection)) return $fallback;
    try {
        $status = $ci->platform->protection->status();
    } catch (Throwable $e) {
        return $fallback;
    }
    return $GLOBALS['ai_workforce_protection_cache'] = is_array($status) ? $status : $fallback;
}

/**
 * Display metadata for a protection state (§8): colour, icon and label.
 *
 * @param array<string,mixed> $status
 * @return array{icon:string,label:string,tone:string,blocking:bool}
 */
function ai_workforce_protection_chip(array $status): array
{
    $state = (string) ($status['state'] ?? 'NORMAL');
    return match ($state) {
        'AUTOMATIC_KILL' => ['icon' => '🔴', 'label' => 'Automatic Kill Switch: ACTIVE', 'tone' => 'danger', 'blocking' => true],
        'AUTOMATIC_PAUSED' => ['icon' => '🟠', 'label' => 'Automatic Protection: PAUSED', 'tone' => 'warn', 'blocking' => true],
        'RECOVERY' => ['icon' => '🟡', 'label' => 'Automatic Protection: RECOVERY', 'tone' => 'warn', 'blocking' => true],
        'WARNING' => ['icon' => '🟠', 'label' => 'Automatic Protection: WARNING', 'tone' => 'warn', 'blocking' => false],
        'RESUMED' => ['icon' => '🔵', 'label' => 'Automatic Protection: RESUMED', 'tone' => 'ok', 'blocking' => false],
        default => ['icon' => '🟢', 'label' => 'Automatic Protection: NORMAL', 'tone' => 'ok', 'blocking' => false],
    };
}
