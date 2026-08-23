<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Aegis/autoload.php';

/**
 * Base controller: builds the AEGIS Platform (domain services wired to the
 * database through CI3's model layer) once per request.
 */
class MY_Controller extends CI_Controller
{
    public \Aegis\Platform $platform;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Aegis_model');
                $disableReal = getenv('AEGIS_DISABLE_REAL_PROVIDERS') === '1';
        $this->platform = new \Aegis\Platform($this->Aegis_model, $disableReal);
            }

    /** JSON response helper. */
    protected function json($data, int $status = 200): void
    {
        http_response_code($status);
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function jsonError(string $message, int $status = 400, array $extra = []): void
    {
        $this->json(array_merge(['error' => $message], $extra), $status);
    }

    /** Read + validate a JSON request body. */
    protected function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return $this->input->post() ?: [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

/** JSON API base — always returns JSON, never a view. */
class Api_controller extends MY_Controller
{
    protected function requireFields(array $body, array $fields): ?array
    {
        $missing = [];
        foreach ($fields as $f) {
            if (!isset($body[$f]) || $body[$f] === '' || $body[$f] === null) $missing[] = $f;
        }
        if ($missing) {
            $this->jsonError('missing required fields: ' . implode(', ', $missing), 400);
            return null;
        }
        return $body;
    }
}
