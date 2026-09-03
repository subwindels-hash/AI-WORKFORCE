<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'core/MY_Controller.php';

/** Authenticated workspace pages. Visitors are sent to /login. */
class App_Controller extends MY_Controller
{
    protected array $identity;

    public function __construct()
    {
        parent::__construct();
        $this->identity = $this->requireLogin();
        // Surface the user's own saved broker connections to every authenticated
        // request so the dashboard, execution supervisor and paper engine see
        // the same connector set the user configured in /brokers/connect.
        try {
            $this->platform->bindUserConnectors((int) $this->identity['id']);
        } catch (\Throwable $e) {
            log_message('error', 'bindUserConnectors failed: ' . $e->getMessage());
        }
    }
}
