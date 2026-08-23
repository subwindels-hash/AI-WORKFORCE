<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Leads extends MY_Controller {
 public function index(){ $this->load->view('leads/index'); }
 public function pipeline(){ $this->load->view('leads/index',['pipeline'=>true]); }
}
