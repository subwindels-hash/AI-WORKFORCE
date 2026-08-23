<?php
namespace Aegis\Sports;
/** Calculates only from supplied settled ticket records; no synthetic statistics. */
class PerformanceAnalytics {
 public function summarize(array $tickets): array {
  $settled=array_values(array_filter($tickets,fn($t)=>in_array($t['settlement_status']??$t['status']??'',['WON','LOST','VOID','CANCELLED'],true))); $counts=['WON'=>0,'LOST'=>0,'VOID'=>0,'CANCELLED'=>0]; $odds=[];
  foreach($settled as $t){$s=$t['settlement_status']??$t['status'];$counts[$s]++;if(isset($t['total_odds'])&&is_numeric($t['total_odds']))$odds[]=(float)$t['total_odds'];}
  $decisive=$counts['WON']+$counts['LOST']; return ['dataAvailable'=>count($settled)>0,'totalTickets'=>count($tickets),'settledTickets'=>count($settled),'won'=>$counts['WON'],'lost'=>$counts['LOST'],'void'=>$counts['VOID'],'cancelled'=>$counts['CANCELLED'],'winRate'=>$decisive?round($counts['WON']/$decisive,4):null,'averageOdds'=>$odds?round(array_sum($odds)/count($odds),4):null,'roi'=>null,'profitLoss'=>null,'notes'=>['ROI and profit/loss require stored stake/accounting inputs and are intentionally unavailable']];
 }
}
