<?php
namespace Aegis\Sports;
use Aegis\Persistence\AuditRepository;
use Aegis\Persistence\SportsRepository;
class TicketGovernance
{
 public function __construct(private SportsRepository $repo, private AuditRepository $audit) {}
 public function record(array $optimized, string $configurationVersion, ?int $modelVersionId = null): array {
  if (($optimized['status'] ?? '') !== 'QUALIFIED') return ['status'=>'NO_QUALIFIED_TICKET','reason'=>$optimized['reason'] ?? 'No compliant combination'];
  $id=$optimized['ticketId']; $sels=$optimized['selections']; $this->repo->saveTicket(['id'=>$id,'created_at'=>gmdate('c'),'model_version_id'=>$modelVersionId,'configuration_version'=>$configurationVersion,'total_odds'=>$optimized['totalOdds'],'selection_count'=>count($sels),'combined_probability'=>null,'confidence'=>null,'risk'=>'LOW','correlation'=>'LOW','data_quality_score'=>null,'status'=>'PENDING','approval_status'=>'PENDING_USER_APPROVAL','settlement_status'=>'PENDING','reason'=>null]);
  foreach($sels as $s) $this->repo->saveTicketSelection(['ticket_id'=>$id,'prediction_id'=>$s['predictionId'] ?? 'unlinked','match_id'=>$s['matchId'],'market'=>$s['market'] ?? 'UNSPECIFIED','selection'=>$s['selection'] ?? 'UNSPECIFIED','odds'=>$s['value']['odds'],'odds_timestamp'=>$s['oddsTimestamp'] ?? gmdate('c'),'model_probability'=>$s['prediction']['rawModelProbability'] ?? null,'calibrated_probability'=>$s['prediction']['calibratedProbability'] ?? null,'expected_value'=>$s['value']['expectedValue'],'risk'=>$s['risk']['classification'],'result'=>null,'status'=>'PENDING']);
  $this->audit->emit('SPORTS_TICKET_RECORDED','Sports ticket awaiting user approval',['ticketId'=>$id]); return ['status'=>'PENDING_USER_APPROVAL','ticketId'=>$id];
 }
 public function decide(string $id,bool $approve,string $actor,string $reason=''): array {
  $ticket=$this->repo->findTicket($id); if(!$ticket) throw new \InvalidArgumentException('ticket not found'); if(($ticket['approval_status']??'')!=='PENDING_USER_APPROVAL') throw new \RuntimeException('ticket already decided');
  $state=$approve?'APPROVED_NOT_EXECUTED':'REJECTED'; $this->repo->updateTicket($id,['approval_status'=>$state,'status'=>$approve?'APPROVED':'CANCELLED','reason'=>$reason]); $this->audit->emit($approve?'SPORTS_TICKET_APPROVED':'SPORTS_TICKET_REJECTED','Sports ticket decision; no external execution',['ticketId'=>$id,'reason'=>$reason],$actor); return ['ticketId'=>$id,'approvalStatus'=>$state,'externalExecution'=>false];
 }
}
