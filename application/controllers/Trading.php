<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/** User-facing trading workspace. Execution remains behind the existing
 * supervisor, risk gates, kill switch, and approval workflow. */
class Trading extends App_Controller
{
    public function index()
    {
        $data=$this->base('My Trading'); $connections=$this->platform->userBrokers->listForUser((int)$this->identity['id']);
        $accounts=[]; $positions=[];
        foreach($connections as $row){ if(empty($row['enabled'])) continue; try { $c=$this->platform->userBrokers->buildConnector($row); if(!$c) continue; $a=$c->account(); $a['broker']=$row['broker']; $a['label']=$row['label']; $accounts[]=$a; if(method_exists($c,'positions')) foreach((array)$c->positions() as $p) {$p['broker']=$row['broker']; $positions[]=$p;} } catch(\Throwable $e) { $accounts[]=['broker'=>$row['broker'],'label'=>$row['label'],'error'=>'Unavailable']; } }
        $data['connections']=$connections; $data['accounts']=$accounts; $data['positions']=$positions;
        $data['executions']=$this->platform->execution->executions(15); $data['proposals']=$this->platform->execution->proposals(null,15);
        $data['analysis']=$this->platform->providers->getAllHealth();
        $this->load->view('layout/header',$data); $this->load->view('trading/index',$data); $this->load->view('layout/footer');
    }
    private function base(string $title): array { $s=$this->platform->state(); return ['title'=>$title,'active'=>'trading','status'=>['tradingMode'=>$s['tradingMode'],'killSwitch'=>$s['killSwitch'],'providers'=>$this->platform->providers->getAllHealth()],'notice'=>$this->session->flashdata('notice'),'error'=>$this->session->flashdata('error')]; }
}
