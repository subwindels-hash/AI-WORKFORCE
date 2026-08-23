<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| Traditional MVC pages (server-rendered views) plus a JSON API layer.
*/
$route['default_controller'] = 'welcome';
$route['404_override'] = '';
$route['translate_uri_dashes'] = false;

// ---- pretty URLs for the MVC pages ---------------------------------------
$route['strategy'] = 'strategy_lab';
$route['strategy/backtest'] = 'strategy_lab/run_backtest';
$route['strategy/advance'] = 'strategy_lab/advance';
$route['kill-switch'] = 'welcome/kill_switch';
$route['mode'] = 'welcome/mode';
$route['paper'] = 'paper';
$route['paper/create'] = 'paper/create';
$route['paper/(:num)'] = 'paper/account/$1';
$route['paper/(:num)/order'] = 'paper/order/$1';
$route['paper/(:num)/tick'] = 'paper/tick/$1';
$route['paper/(:num)/deploy'] = 'paper/deploy/$1';
$route['paper/(:num)/positions/(:num)/close'] = 'paper/close/$1/$2';
$route['paper/(:num)/deployments/(:num)/toggle'] = 'paper/toggle/$1/$2';

// ---- JSON API (spec §17 subset for Phases 1–3) ---------------------------
$route['api/system/status'] = 'api_system/status';
$route['api/auth/login'] = 'api_auth/login';
$route['api/auth/logout'] = 'api_auth/logout';
$route['api/auth/me'] = 'api_auth/me';
$route['api/sports/status'] = 'api_sports/status';
$route['api/sports/tickets/(:any)/decide'] = 'api_sports/decide_ticket/$1';
$route['api/sports/tickets/(:any)/settle'] = 'api_sports/settle_ticket/$1';
$route['api/sports/results/verify'] = 'api_sports/verify_result';
$route['api/system/features'] = 'api_system/features';
$route['api/brokers'] = 'api_system/brokers';
$route['api/brokers/mt5/account'] = 'api_system/mt5_account';
$route['api/brokers/mt5/quote'] = 'api_system/mt5_quote';
$route['api/events'] = 'api_system/events';
$route['api/events/(:num)'] = 'api_system/events/$1';

$route['api/market-data/candles'] = 'api_marketdata/candles';
$route['api/market-data/quote'] = 'api_marketdata/quote';
$route['api/market-data/providers'] = 'api_marketdata/providers';

$route['api/analysis/run'] = 'api_analysis/run';
$route['api/analysis/history'] = 'api_analysis/history';
$route['api/analysis/(:any)'] = 'api_analysis/show/$1';
$route['api/agents'] = 'api_analysis/agents';
$route['api/agents/consensus'] = 'api_analysis/consensus';

$route['api/strategies'] = 'api_strategies/index';
$route['api/strategies/(:any)'] = 'api_strategies/show/$1';
$route['api/strategies/(:any)/status'] = 'api_strategies/status/$1';
$route['api/backtesting/run'] = 'api_strategies/run_backtest';
$route['api/backtesting/results'] = 'api_strategies/backtest_results';
$route['api/backtesting/results/(:any)'] = 'api_strategies/backtest_detail/$1';

$route['api/accounts'] = 'api_paper/accounts';
$route['api/accounts/create'] = 'api_paper/create_account';
$route['api/accounts/(:num)'] = 'api_paper/account/$1';
$route['api/accounts/(:num)/orders'] = 'api_paper/orders/$1';
$route['api/accounts/(:num)/order'] = 'api_paper/submit_order/$1';
$route['api/accounts/(:num)/positions'] = 'api_paper/positions/$1';
$route['api/accounts/(:num)/positions/(:num)/close'] = 'api_paper/close_position/$1/$2';
$route['api/accounts/(:num)/tick'] = 'api_paper/tick/$1';
$route['api/accounts/(:num)/deployments'] = 'api_paper/deployments/$1';
$route['api/accounts/(:num)/deploy'] = 'api_paper/deploy/$1';
$route['api/accounts/(:num)/deployments/(:num)/toggle'] = 'api_paper/toggle_deployment/$1/$2';

$route['api/journal'] = 'api_journal/index';
$route['api/journal/manual'] = 'api_journal/manual';
$route['api/analytics/summary'] = 'api_journal/summary';
$route['api/analytics/confidence-calibration'] = 'api_journal/calibration';

$route['api/risk/limits'] = 'api_system/risk_limits';
$route['api/risk/limits/update'] = 'api_system/update_risk_limits';
$route['api/trading/kill-switch'] = 'api_system/kill_switch';
$route['api/trading/mode'] = 'api_system/trading_mode';
$route['api/execution/preflight'] = 'api_system/execution_preflight';
$route['api/execution/approvals'] = 'api_system/execution_approvals';
$route['api/execution/approvals/request'] = 'api_system/execution_request_approval';
$route['api/execution/approvals/(:any)/decide'] = 'api_system/execution_decide/$1';
$route['api/execution/approvals/(:any)/route'] = 'api_system/execution_route/$1';
$route['api/trading/synthetic-paper'] = 'api_system/synthetic_paper';
