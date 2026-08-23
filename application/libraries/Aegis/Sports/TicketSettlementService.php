<?php
namespace Aegis\Sports;
use Aegis\Persistence\AuditRepository;
use Aegis\Persistence\SportsRepository;
class TicketSettlementService {
 public function __construct(private SportsRepository $repo, private ResultVerificationEngine $verifier, private AuditRepository $audit) {}
 public function applyVerifiedResult(string $ticketId,int $matchId,array $result): array {
  $verified=$this->verifier->verify($result); if(empty($verified['verified'])) return ['status'=>'PENDING','reason'=>$verified['reason']];
  $ticket=$this->repo->findTicket($ticketId); if(!$ticket) throw new \InvalidArgumentException('ticket not found');
  foreach($this->repo->ticketSelections($ticketId) as $s) if((int)$s['match_id']===$matchId && $s['status']==='PENDING') { $out=$this->verifier->settleSelection(['market'=>$s['market'],'selection'=>$s['selection']],$verified); if($out['status']!=='PENDING') $this->repo->updateTicketSelection((int)$s['id'],['status'=>$out['status'],'result'=>$out['status']]); }
  $all=$this->repo->ticketSelections($ticketId); $states=array_column($all,'status'); $status=in_array('LOST',$states,true)?'LOST':(count($states)>0 && !in_array('PENDING',$states,true)?'WON':'PENDING');
  $this->repo->updateTicket($ticketId,['settlement_status'=>$status,'status'=>$status]); $this->audit->emit('SPORTS_TICKET_SETTLED','Sports ticket settlement updated',['ticketId'=>$ticketId,'status'=>$status]); return ['ticketId'=>$ticketId,'status'=>$status];
 }
}
