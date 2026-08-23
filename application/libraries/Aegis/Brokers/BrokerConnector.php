<?php
namespace Aegis\Brokers;

/**
 * Boundary for a broker integration. Connectors may report capability and
 * health, but they never receive strategy/agent objects. Order execution is
 * intentionally reserved for the Phase 5 execution supervisor.
 */
interface BrokerConnector
{
    public function id(): string;
    public function status(): array;
    public function capabilities(): array;
}

/** Collects registered connectors without exposing broker credentials. */
class BrokerManager
{
    /** @var array<string,BrokerConnector> */
    private array $connectors = [];

    public function register(BrokerConnector $connector): void
    {
        $this->connectors[$connector->id()] = $connector;
    }

    public function allStatus(): array
    {
        $out = [];
        foreach ($this->connectors as $id => $connector) {
            $out[$id] = array_merge($connector->status(), [
                'id' => $id,
                'capabilities' => $connector->capabilities(),
            ]);
        }
        return $out;
    }
}
