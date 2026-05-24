<?php
/**
 * Reconciliation cron — runs every 10 minutes via server cron.
 * Catches anything webhooks missed and verifies webhook health.
 *
 * Triggered by an HTTP GET from cron with ?key=<WEBHOOK_SECRET>.
 *
 * Server cron line example:
 *   * /10 * * * * curl -s https://bqbstudio.com/jupiter/ginger-sync/api/cron-reconcile.php?key=SECRET >/dev/null
 */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../lib/sync_engine.php';

header('Content-Type: application/json');

// Auth: shared secret instead of session (cron has no session)
$key = $_GET['key'] ?? '';
$expected = $_ENV['WEBHOOK_SECRET'] ?? '';
if (!$expected || !hash_equals($expected, (string)$key)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

set_time_limit(300);

try {
    $db = new Supabase('service');
    $mappingsRes = $db->from('mappings', 'select=*&is_active=eq.true');
    $mappings = $mappingsRes['data'] ?? [];

    $totals = ['mappings' => count($mappings), 'trello_to_clickup' => 0, 'clickup_to_trello' => 0, 'errors' => 0, 'skipped' => 0];

    foreach ($mappings as $m) {
        $stats = reconcileMapping($db, $m, 'reconcile_cron');
        foreach ($stats as $k => $v) {
            if (isset($totals[$k])) $totals[$k] += (int)$v;
        }
    }

    // Webhook health check — touch last_checked_at on every active registration.
    // (A more thorough check would re-list webhooks via REST and detect disabled ones — TODO.)
    $db->update('webhook_registrations', 'status=eq.active', ['last_checked_at' => gmdate('c')]);

    echo json_encode(['ok' => true, 'totals' => $totals]);
} catch (Throwable $e) {
    error_log('cron-reconcile: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
