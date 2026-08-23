<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * View-safe access to platform state (views must not reach into controllers).
 */
final class Aegis_PlatformStateHelper
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
