<?php
namespace AIWorkforce\Sports;
/** Results remain pending until a provider marks a finished score as verified. */
class ResultVerificationEngine
{
 public function verify(array $result): array {
  if (empty($result['verified'])) return ['verified'=>false,'reason'=>'RESULT_UNVERIFIED'];
  $status=strtoupper((string)($result['status']??''));
  if (in_array($status,['VOID','CANCELLED','POSTPONED'],true)) return ['verified'=>true,'terminalStatus'=>'VOID','verifiedAt'=>gmdate('c')];
  if ($status!=='FINISHED') return ['verified'=>false,'reason'=>'MATCH_NOT_FINISHED'];
  if (!isset($result['homeScore'],$result['awayScore']) || !is_int($result['homeScore']) || !is_int($result['awayScore']) || $result['homeScore']<0 || $result['awayScore']<0) return ['verified'=>false,'reason'=>'RESULT_INVALID'];
  return ['verified'=>true,'terminalStatus'=>'FINISHED','homeScore'=>$result['homeScore'],'awayScore'=>$result['awayScore'],'verifiedAt'=>gmdate('c')];
 }
 public function settleSelection(array $selection,array $verified): array {
  if (empty($verified['verified'])) return ['status'=>'PENDING','reason'=>$verified['reason']??'RESULT_UNVERIFIED'];
  if (($verified['terminalStatus'] ?? '') === 'VOID') return ['status'=>'VOID','reason'=>'VERIFIED_VOID'];
  $home=(int)$verified['homeScore']; $away=(int)$verified['awayScore']; $total=$home+$away; $market=strtoupper((string)($selection['market']??'')); $pick=strtoupper((string)($selection['selection']??''));
  if ($market==='TOTAL_GOALS' && ($totalsLine = PredictionEngine::totalsLine($pick)) !== null) { $over = $total > $totalsLine[0]; return ['status'=>(($totalsLine[1]==='OVER') ? $over : !$over)?'WON':'LOST','reason'=>'VERIFIED_RESULT']; }
  if ($market==='BTTS' && in_array($pick,['YES','NO'],true)) { $both=$home>0 && $away>0; return ['status'=>(($pick==='YES')?$both:!$both)?'WON':'LOST','reason'=>'VERIFIED_RESULT']; }
  // Draw No Bet: the draw refunds the stake (VOID), otherwise the pick must win outright.
  if ($market==='DRAW_NO_BET' && in_array($pick,['HOME','AWAY'],true)) {
   if ($home===$away) return ['status'=>'VOID','reason'=>'DRAW_NO_BET_PUSH'];
   return ['status'=>(($pick==='HOME')?$home>$away:$away>$home)?'WON':'LOST','reason'=>'VERIFIED_RESULT'];
  }
  if ($market==='MATCH_RESULT') {
   $won=($pick==='HOME' && $home>$away) || ($pick==='DRAW' && $home===$away) || ($pick==='AWAY' && $away>$home);
   if (in_array($pick,['HOME','DRAW','AWAY'],true)) return ['status'=>$won?'WON':'LOST','reason'=>'VERIFIED_RESULT'];
  }
  if ($market==='DOUBLE_CHANCE') {
   $won=($pick==='HOME_OR_DRAW' && $home>=$away) || ($pick==='AWAY_OR_DRAW' && $away>=$home) || ($pick==='HOME_OR_AWAY' && $home!==$away);
   if (in_array($pick,['HOME_OR_DRAW','AWAY_OR_DRAW','HOME_OR_AWAY'],true)) return ['status'=>$won?'WON':'LOST','reason'=>'VERIFIED_RESULT'];
  }
  return ['status'=>'PENDING','reason'=>'MARKET_SETTLEMENT_RULE_UNAVAILABLE'];
 }
}
