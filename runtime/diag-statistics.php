<?php
chdir('/home/user/AI-WORKFORCE');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
require_once '/home/user/AI-WORKFORCE/application/libraries/AIWorkforce/autoload.php';

$provider = new \AIWorkforce\Sports\Providers\ApiFootballProvider('mock-key-12345', 'http://127.0.0.1:9377', 10);
echo "class: " . get_class($provider) . "\n";
echo "method standings: " . var_export(method_exists($provider, 'standings'), true) . "\n";

$manager = new \AIWorkforce\Sports\Providers\SportsProviderManager();
$manager->register($provider);
echo "manager ids: " . json_encode(array_keys($manager->all())) . "\n";

$repo = new class {
    public function __call($n, $a) { return null; }
};
$config = new \AIWorkforce\Football\FootballConfiguration();
$gw = new \AIWorkforce\Football\ProviderGateway($manager, $config, null);
echo "supports standings: " . var_export($gw->supports('standings'), true) . "\n";
$health = $provider->health();
echo "health: " . json_encode(['status' => $health['status'] ?? null, 'requestsToday' => $health['requestsToday'] ?? null, 'limitDaily' => $health['limitDaily'] ?? null]) . "\n";
$standings = $provider->standings('39', '2026');
echo "standings rows: " . count($standings) . " first: " . json_encode($standings[0] ?? null) . "\n";
