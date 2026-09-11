<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Sports\Providers\SportsProviderManager;

/**
 * Why the odds-prediction engine has no data feed, and what to do about it.
 *
 * DISABLED_NO_PROVIDER is an honest state — the engine refuses to fabricate
 * fixtures — but on its own it is not ACTIONABLE. An operator reading "no
 * sports provider configured" still has to discover that credentials live in
 * Admin → API, which of three vendors to pick, and which environment
 * variables the deployment reads. That discovery gap is what leaves a
 * correctly-installed deployment sitting at zero tickets.
 *
 * This advisor answers the question with facts the runtime can actually
 * verify: which vendors are registered, which environment variables are
 * present (never their VALUES), whether the credential store holds a sports
 * entry, and the concrete next step.
 *
 * It diagnoses only. It never registers a provider, never writes a
 * credential, and never invents a feed — a deployment with no data source
 * must keep reporting DISABLED_NO_PROVIDER.
 */
final class ProviderSetupAdvisor
{
    /** Engine cannot run: nothing is registered at all. */
    public const STATE_NO_PROVIDER = 'NO_PROVIDER_CONFIGURED';
    /** Providers exist but every one of them is failing. */
    public const STATE_ALL_FAILING = 'ALL_PROVIDERS_FAILING';
    /** At least one provider can serve data. */
    public const STATE_READY = 'READY';

    /**
     * The vendors this platform can talk to, in recommended order.
     *
     * `envKeys` are the environment variables the runtime actually reads
     * (see SportsIntelligence::registerProviders / ApiProviders), so the
     * advice can never drift from the code that does the registering.
     *
     * @var list<array{driver:string,label:string,envKeys:list<string>,signup:string,note:string}>
     */
    public const VENDORS = [
        [
            'driver' => 'api_football',
            'label' => 'API-Football',
            'envKeys' => ['API_FOOTBALL_KEY', 'WINDELS_API_FOOTBALL_KEY'],
            'signup' => 'https://dashboard.api-football.com/',
            'note' => 'Fullest coverage: fixtures, odds, statistics, standings and lineups. Free tier available.',
        ],
        [
            'driver' => 'thesportsdb',
            'label' => 'TheSportsDB',
            'envKeys' => ['WINDELS_THESPORTSDB_KEY'],
            'signup' => 'https://www.thesportsdb.com',
            'note' => 'Fixtures and results. The free tier key is "123"; odds coverage is limited, so tickets may still find no priced market.',
        ],
        [
            'driver' => 'sportmonks',
            'label' => 'SportMonks',
            'envKeys' => ['WINDELS_SPORTMONKS_TOKEN'],
            'signup' => 'https://my.sportmonks.com/',
            'note' => 'Fixtures, odds and round endpoints (bulk per-matchday odds).',
        ],
    ];

    /**
     * Diagnose the current provider situation.
     *
     * @param SportsProviderManager $providers the live manager
     * @param array $readiness the status() readiness block, when available
     * @param callable|null $envReader fn(string):string|false — injectable for tests
     * @param bool|null $storeHasSportsEntry whether Admin → API holds a sports credential
     */
    public static function diagnose(
        SportsProviderManager $providers,
        array $readiness = [],
        ?callable $envReader = null,
        ?bool $storeHasSportsEntry = null
    ): array {
        $env = $envReader ?? 'getenv';
        $registered = array_keys($providers->all());
        $configured = $providers->configured();

        // Which vendor credentials the ENVIRONMENT supplies. Only presence is
        // ever reported — a secret must not travel into a diagnostic payload.
        $envPresent = [];
        foreach (self::VENDORS as $vendor) {
            foreach ($vendor['envKeys'] as $key) {
                $value = $env($key);
                if (is_string($value) && trim($value) !== '') {
                    $envPresent[$vendor['driver']][] = $key;
                }
            }
        }

        if (!$configured) {
            $state = self::STATE_NO_PROVIDER;
            $headline = 'No sports data provider is connected, so no odds-prediction ticket can be generated.';
            $nextStep = 'Add a provider in Admin → API (Service: sports), or set one of the environment variables below and restart PHP.';
        } elseif (($readiness['engine'] ?? '') === 'BLOCKED') {
            $state = self::STATE_ALL_FAILING;
            $headline = 'Every configured sports provider is currently failing, so an empty day is a data outage — not "no qualified games".';
            $nextStep = 'Check the per-provider status (quota, authentication, timeout) on the Sports console Data feed panel.';
        } else {
            $state = self::STATE_READY;
            $headline = 'A sports data provider is connected.';
            $nextStep = 'No action needed.';
        }

        return [
            'state' => $state,
            'headline' => $headline,
            'nextStep' => $nextStep,
            'providersConfigured' => $configured,
            'registeredProviders' => $registered,
            'operationalProviders' => (int) ($readiness['operational'] ?? 0),
            'totalProviders' => (int) ($readiness['total'] ?? count($registered)),
            // Presence only — never the credential itself.
            'environmentCredentials' => $envPresent,
            'credentialStoreConfigured' => $storeHasSportsEntry,
            'adminUrl' => '/admin/api',
            'options' => self::options($envPresent),
            // The engine's refusal to invent data is a feature, not a fault.
            'disclaimer' => 'Nothing is fabricated while no provider is connected: the engine reports DISABLED_NO_PROVIDER instead of inventing fixtures, odds or predictions.',
        ];
    }

    /**
     * The concrete choices an operator has, each with the exact variable
     * name this runtime reads and whether it is already present.
     *
     * @param array<string,list<string>> $envPresent
     */
    public static function options(array $envPresent = []): array
    {
        $out = [];
        foreach (self::VENDORS as $vendor) {
            $present = $envPresent[$vendor['driver']] ?? [];
            $out[] = [
                'driver' => $vendor['driver'],
                'label' => $vendor['label'],
                'note' => $vendor['note'],
                'signup' => $vendor['signup'],
                'primaryEnvKey' => $vendor['envKeys'][0],
                'envKeys' => $vendor['envKeys'],
                'environmentConfigured' => $present !== [],
                'environmentKeysPresent' => $present,
            ];
        }
        return $out;
    }
}
