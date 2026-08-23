<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Sports Intelligence public status only. Mutation endpoints await auth/RBAC foundation. */
class Api_sports extends Api_controller
{
    public function status()
    {
        $this->json($this->platform->sports->status());
    }
}
