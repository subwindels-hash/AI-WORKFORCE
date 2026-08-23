<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Sports Intelligence public status only. Mutation endpoints await auth/RBAC foundation. */
class Api_sports extends Api_controller
{
    public function status()
    {
        $this->json($this->platform->sports->status());
    }

    public function performance()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $allowed = ['from', 'to', 'status', 'modelVersionId'];
        $filter = array_intersect_key($this->input->get(NULL, true) ?: [], array_flip($allowed));
        $this->json($this->platform->sports->performanceReport($filter));
    }

    /** Promotes a persisted provider result only after validation. */
    public function verify_result()
    {
        $user = $this->requirePermission('sports.settle'); if (!$user) return;
        $body = $this->jsonBody();
        if (!isset($body['matchId'], $body['providerId']) || !is_numeric($body['matchId']) || !is_numeric($body['providerId'])) return $this->jsonError('body must include numeric matchId and providerId');
        try { $this->json(['result' => $this->platform->sports->resultVerifier->verify((int)$body['matchId'], (int)$body['providerId'], (string)$user['id'])]); }
        catch (\InvalidArgumentException $e) { $this->jsonError($e->getMessage(), 404); }
        catch (\Throwable $e) { $this->jsonError($e->getMessage(), 409); }
    }

    /** Settles only from an already verified persisted provider result. */
    public function settle_ticket(string $id)
    {
        if (!$this->requirePermission('sports.settle')) return;
        $body = $this->jsonBody();
        if (!isset($body['matchId'], $body['providerId']) || !is_numeric($body['matchId']) || !is_numeric($body['providerId'])) return $this->jsonError('body must include numeric matchId and providerId');
        try { $this->json(['settlement' => $this->platform->sports->settlement->applyStoredResult($id, (int)$body['matchId'], (int)$body['providerId'])]); }
        catch (\InvalidArgumentException $e) { $this->jsonError($e->getMessage(), 404); }
        catch (\Throwable $e) { $this->jsonError($e->getMessage(), 409); }
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
