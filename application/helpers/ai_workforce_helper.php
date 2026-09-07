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
/**
 * Risk-policy fields for an MT4/MT5 override form (§9).
 *
 * Every field is OPTIONAL: a blank value means "inherit", so an account or a
 * deployment only pins the values that genuinely differ from the platform
 * policy and keeps tracking the wider scope for everything else. The platform
 * value is shown as the placeholder so the administrator can see what they are
 * overriding.
 *
 * @param array $current  the override being edited (dailyLoss.percentLimit …)
 * @param array $platform the platform policy, used for the placeholders
 */
function ai_workforce_ea_policy_fields(array $current = [], array $platform = []): string
{
    $percent = static function (mixed $value): string {
        if ($value === null || $value === '' || !is_numeric($value)) return '';
        return number_format(\AIWorkforce\TradingProtection\ProtectionPolicy::toPercent($value), 2, '.', '');
    };
    $num = static function (mixed $value): string {
        return ($value === null || $value === '' || !is_numeric($value)) ? '' : (string) (float) $value;
    };
    $int = static function (mixed $value): string {
        return ($value === null || $value === '' || !is_numeric($value)) ? '' : (string) (int) $value;
    };
    $tri = static function (string $name, mixed $value, string $label): string {
        $on  = ($value === true)  ? ' selected' : '';
        $off = ($value === false) ? ' selected' : '';
        return sprintf(
            '<label>%s<select name="%s"><option value="">Inherit</option>'
            . '<option value="1"%s>On</option><option value="0"%s>Off</option></select></label>',
            e($label), e($name), $on, $off
        );
    };

    $daily   = (array) ($current['dailyLoss'] ?? []);
    $dd      = (array) ($current['drawdown'] ?? []);
    $spread  = (array) ($current['spread'] ?? []);
    $slip    = (array) ($current['slippage'] ?? []);
    $news    = (array) ($current['news'] ?? []);
    $emerg   = (array) ($current['emergency'] ?? []);
    $pDaily  = (array) ($platform['dailyLoss'] ?? []);
    $pDd     = (array) ($platform['drawdown'] ?? []);
    $pSpread = (array) ($platform['spread'] ?? []);
    $pSlip   = (array) ($platform['slippage'] ?? []);
    $pNews   = (array) ($platform['news'] ?? []);
    $pEmerg  = (array) ($platform['emergency'] ?? []);

    $fields = [
        sprintf('<label>Daily loss %%<input name="ov_loss_pct" type="number" min="0" max="50" step="0.1" placeholder="%s" value="%s"></label>',
            e($percent($pDaily['percentLimit'] ?? 0.03)), e($percent($daily['percentLimit'] ?? null))),
        sprintf('<label>Daily loss (fixed)<input name="ov_loss_fixed" type="number" min="0" step="1" placeholder="%s" value="%s"></label>',
            e($num($pDaily['fixedLimitUsd'] ?? null)), e($num($daily['fixedLimitUsd'] ?? null))),
        sprintf('<label>Max drawdown %%<input name="ov_dd_pct" type="number" min="0" max="90" step="0.1" placeholder="%s" value="%s"></label>',
            e($percent($pDd['percentLimit'] ?? 0.10)), e($percent($dd['percentLimit'] ?? null))),
        sprintf('<label>Max spread (points)<input name="ov_spread_points" type="number" min="0" step="0.5" placeholder="%s" value="%s"></label>',
            e($num($pSpread['maxPoints'] ?? 30)), e($num($spread['maxPoints'] ?? null))),
        sprintf('<label>Max slippage (points)<input name="ov_slip_points" type="number" min="0" step="0.5" placeholder="%s" value="%s"></label>',
            e($num($pSlip['maxPoints'] ?? 10)), e($num($slip['maxPoints'] ?? null))),
        $tri('ov_news_enabled', $news['enabled'] ?? null, 'News protection'),
        sprintf('<label>News minutes before<input name="ov_news_before" type="number" min="0" max="240" placeholder="%s" value="%s"></label>',
            e($int($pNews['minutesBefore'] ?? 5)), e($int($news['minutesBefore'] ?? null))),
        sprintf('<label>News minutes after<input name="ov_news_after" type="number" min="0" max="480" placeholder="%s" value="%s"></label>',
            e($int($pNews['minutesAfter'] ?? 30)), e($int($news['minutesAfter'] ?? null))),
        $tri('ov_emergency_close', $emerg['closePositionsOnKill'] ?? null, 'Close positions on kill'),
        $tri('ov_emergency_cancel', $emerg['cancelPendingOrdersOnKill'] ?? null, 'Cancel pending on kill'),
    ];

    return '<div class="grid two">' . implode('', $fields) . '</div>';
}

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
