<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Sports Intelligence public status only. Mutation endpoints await auth/RBAC foundation. */
class Api_sports extends Api_controller
{
    public function status()
    {
        $this->json($this->platform->sports->status());
    }

    /** Human decision only. Requires the native session, CSRF token and sports.approve. */
    public function decide_ticket(string $id)
    {
        $user = $this->requirePermission('sports.approve');
        if (!$user) return;
        $body = $this->jsonBody();
        if (!isset($body['approve']) || !is_bool($body['approve'])) return $this->jsonError('body must include approve: boolean');
        try {
            $this->json(['ticket' => $this->platform->sports->governance->decide($id, $body['approve'], (string) $user['id'], (string) ($body['reason'] ?? ''))]);
        } catch (\InvalidArgumentException $e) { $this->jsonError($e->getMessage(), 404); }
        catch (\Throwable $e) { $this->jsonError($e->getMessage(), 409); }
    }
}
