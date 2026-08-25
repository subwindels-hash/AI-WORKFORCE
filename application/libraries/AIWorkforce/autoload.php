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
    $ai_workforceDir . '/Persistence/Repositories.php',      // repository interfaces
    $ai_workforceDir . '/Brokers/BrokerConnector.php',       // broker interface + manager
    $ai_workforceDir . '/Brokers/TradingConnector.php',      // order-capable broker interface
    $ai_workforceDir . '/Agents/AgentHelperTrait.php',       // trait
    $ai_workforceDir . '/Strategies/BuiltinStrategies.php',  // TradingStrategy interface + builtins
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
