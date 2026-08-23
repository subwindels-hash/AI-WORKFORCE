<?php
namespace Aegis\Sports;
/** Results remain pending until a provider marks a finished score as verified. */
class ResultVerificationEngine
{
 public function verify(array $result): array {
  if (empty($result['verified'])) return ['verified'=>false,'reason'=>'RESULT_UNVERIFIED'];
  if (strtoupper((string)($result['status']??''))!=='FINISHED') return ['verified'=>false,'reason'=>'MATCH_NOT_FINISHED'];
  if (!isset($result['homeScore'],$result['awayScore']) || !is_int($result['homeScore']) || !is_int($result['awayScore']) || $result['homeScore']<0 || $result['awayScore']<0) return ['verified'=>false,'reason'=>'RESULT_INVALID'];
  return ['verified'=>true,'homeScore'=>$result['homeScore'],'awayScore'=>$result['awayScore'],'verifiedAt'=>gmdate('c')];
 }
 public function settleSelection(array $selection,array $verified): array {
  if (empty($verified['verified'])) return ['status'=>'PENDING','reason'=>$verified['reason']??'RESULT_UNVERIFIED'];
  $total=$verified['homeScore']+$verified['awayScore']; $market=$selection['market']??''; $pick=$selection['selection']??'';
  if ($market==='TOTAL_GOALS' && $pick==='OVER_1_5') return ['status'=>$total>1?'WON':'LOST','reason'=>'VERIFIED_RESULT'];
  return ['status'=>'PENDING','reason'=>'MARKET_SETTLEMENT_RULE_UNAVAILABLE'];
 }
}
