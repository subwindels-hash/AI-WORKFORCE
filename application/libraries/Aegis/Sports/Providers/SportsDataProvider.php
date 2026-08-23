<?php
namespace Aegis\Sports\Providers;

/** Provider-neutral boundary. No application layer may consume raw provider payloads. */
interface SportsDataProvider
{
    public function id(): string;
    /** ONLINE | DEGRADED | OFFLINE | RATE_LIMITED | AUTHENTICATION_ERROR | DATA_ERROR */
    public function health(): array;
    /** @return array<int,array<string,mixed>> normalized only by SportsDataNormalizer */
    public function fixtures(array $query): array;
    public function odds(string $fixtureExternalId): array;
    public function results(string $fixtureExternalId): array;
}

class SportsProviderManager
{
    /** @var array<string,SportsDataProvider> */
    private array $providers = [];
    public function register(SportsDataProvider $provider): void { $this->providers[$provider->id()] = $provider; }
    public function health(): array
    {
        $out = [];
        foreach ($this->providers as $id => $provider) $out[$id] = array_merge(['id' => $id], $provider->health());
        return $out;
    }
    public function configured(): bool { return count($this->providers) > 0; }
}
