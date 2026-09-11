<?php
$ci = get_instance();
$fb = $ci->platform->football;
$gw = $fb->gateway();
$all = $gw->providerManager()->all();
echo "REGISTERED: " . json_encode(array_map(fn($p) => get_class($p) . ':' . $p->id(), $all)), "\n";
echo "supports standings: " . var_export($gw->supports('standings'), true), "\n";
echo "capabilities: " . json_encode($gw->capabilities()), "\n";
$prov = $gw->provider('api-football');
echo "provider class: " . ($prov ? get_class($prov) : 'NULL'), "\n";
echo "method_exists standings: " . var_export($prov ? method_exists($prov, 'standings') : false, true), "\n";
