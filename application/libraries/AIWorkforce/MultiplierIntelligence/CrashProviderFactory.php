<?php
namespace AIWorkforce\MultiplierIntelligence;

/**
 * Resolves the crash-game data source used by Multiplier Intelligence.
 *
 * Live Bustabit (or a custom WINDELS_CRASH_HISTORY_URL) is the default.
 * Simulation is only used when explicitly requested — never as a silent fallback.
 */
class CrashProviderFactory
{
    public static function make(array $options = []): CrashGameProviderInterface
    {
        $code = strtolower((string) ($options['code']
            ?? getenv('WINDELS_CRASH_PROVIDER')
            ?: 'bustabit'));

        if ($code === 'simulation' || $code === 'demo') {
            return new SimulationProvider();
        }
        if ($code === 'aviator_demo') {
            return new AviatorProvider(null, '', true);
        }

        return new LiveCrashProvider([
            'code' => $code === 'aviator' ? LiveCrashProvider::CODE : $code,
            'name' => $options['name'] ?? 'Bustabit (Live)',
            'url' => $options['url'] ?? (getenv('WINDELS_CRASH_HISTORY_URL') ?: ''),
            'apiKey' => $options['apiKey'] ?? (getenv('WINDELS_CRASH_API_KEY') ?: ''),
        ], $options['transport'] ?? null);
    }

    /** Honour admin `active_provider` when set; still refuse silent demo fallback. */
    public static function fromPlatform($platform = null, array $options = []): CrashGameProviderInterface
    {
        $code = $options['code'] ?? null;
        if ($code === null && is_object($platform) && method_exists($platform, 'state')) {
            try {
                $state = $platform->state();
                $code = $state['multiplier_config']['active_provider'] ?? null;
            } catch (\Throwable $e) {
                $code = null;
            }
        }
        if ($code === 'simulation' || $code === 'aviator_demo') {
            // Admin may still pick demo, but the member console defaults to live.
            // Member pages pass force_live=true.
            if (empty($options['allow_demo'])) {
                $code = LiveCrashProvider::CODE;
            }
        }
        if ($code) {
            $options['code'] = $code;
        }
        return self::make($options);
    }
}
