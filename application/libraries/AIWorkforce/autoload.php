<?php
/**
 * AI Workforce domain loader.
 *
 * Some domain files group related classes, and interfaces must be loaded
 * before their implementors, so a per-class autoloader is not enough. We
 * require every domain file once in an explicit dependency-safe order:
 * interfaces/traits first, then everything else. No domain file has
 * top-level side effects.
 */
$ai_workforceDir = __DIR__;
$priority = [
    $ai_workforceDir . '/Providers/MarketDataProvider.php',   // provider interface
    $ai_workforceDir . '/Sports/Providers/SportsDataProvider.php', // sports provider interface + manager
    $ai_workforceDir . '/Lottery/LotteryProvider.php',       // lottery provider interface (before LoteriasApiProvider et al.)
    $ai_workforceDir . '/Persistence/Repositories.php',      // repository interfaces
    $ai_workforceDir . '/Persistence/FootballRepository.php', // football repository interface (before its implementations)
    $ai_workforceDir . '/Brokers/BrokerConnector.php',       // broker interface + manager
    $ai_workforceDir . '/Brokers/TradingConnector.php',      // order-capable broker interface
    $ai_workforceDir . '/MultiplierIntelligence/CrashGameProvider.php', // crash-game interface + abstract base (before AviatorProvider)
    $ai_workforceDir . '/MultiplierIntelligence/AbstractMultiplierAgent.php', // multiplier agent interface + abstract base
    $ai_workforceDir . '/Agents/AgentHelperTrait.php',       // trait
    $ai_workforceDir . '/Strategies/BuiltinStrategies.php',  // TradingStrategy interface + builtins
    $ai_workforceDir . '/Providers/CryptoExchangeProvider.php', // shared base for exchange REST providers (before subclasses)
    $ai_workforceDir . '/Providers/AlpacaProvider.php',
    $ai_workforceDir . '/Providers/OandaProvider.php',
    $ai_workforceDir . '/Providers/IbkrProvider.php',
];
foreach ($priority as $file) {
    if (is_file($file)) {
        require_once $file;
    }
}
foreach ([$ai_workforceDir . '/*.php', $ai_workforceDir . '/*/*.php', $ai_workforceDir . '/*/*/*.php'] as $pattern) {
    $files = glob($pattern);
    sort($files);
    foreach ($files as $file) {
        $base = basename($file);
        if ($base === 'autoload.php' || in_array($file, $priority, true)) {
            continue;
        }
        require_once $file;
    }
}
