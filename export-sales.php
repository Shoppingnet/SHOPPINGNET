<?php
/**
 * export-sales.php  —  Shopping Net (imrashop)  ->  TOP JEMLA
 * -----------------------------------------------------------------------------
 * Read-only sales export endpoint. Returns DELIVERED (livré) sale lines as JSON.
 *
 *   GET  http://207.154.254.26/export-sales.php?key=SECRET&date=YYYY-MM-DD
 *
 *   - key   : shared secret. Bad/missing key  =>  HTTP 401 + [].
 *   - date  : optional. Missing => today's delivered sales (Africa/Casablanca).
 *
 * Output (one element per delivered sale line):
 *   [ { "external_id":"1001","date":"2026-07-19","product":"...",
 *       "ref":"SN9104","qty":2,"price":150 } ]
 *
 * "Delivered" = the order's `delivred_at` timestamp is set (and it is not
 * cancelled/deleted). This file only ever runs SELECT — it never writes.
 *
 * The DB credentials are NOT hard-coded here: they are read at runtime from an
 * existing api_statu_*.php file that already connects to the imrashop database,
 * so this file is safe to store/version without leaking the DB password.
 * -----------------------------------------------------------------------------
 */

/* ===== CONFIG ===== */
$SECRET = 'tj_imrashop_9F3kZq7Lx2Wp';   // <-- secret to give TOP JEMLA (change if you want)
/* ================= */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// --- auth: constant-time secret check ---
$key = isset($_GET['key']) ? (string) $_GET['key'] : '';
if (!hash_equals($SECRET, $key)) {
    http_response_code(401);
    echo '[]';
    exit;
}

date_default_timezone_set('Africa/Casablanca');

// --- date (optional): accept only YYYY-MM-DD, else default to today ---
$date = isset($_GET['date']) ? (string) $_GET['date'] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// --- DB credentials: read them from an existing working API file ---
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'imrashop';
foreach (array('api_statu_soufian.php', 'api_statu_bestbigmall.php', 'api_statu_digylog.php') as $f) {
    $p = __DIR__ . '/' . $f;
    if (is_file($p) && ($src = @file_get_contents($p)) &&
        preg_match('/mysqli_connect\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) {
        $DB_HOST = $m[1];
        $DB_USER = $m[2];
        $DB_PASS = $m[3];
        $DB_NAME = $m[4];
        break;
    }
}

// --- connect (read-only usage) ---
$cn = @mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if (!$cn) {
    http_response_code(500);
    echo '[]';
    exit;
}
mysqli_set_charset($cn, 'utf8mb4');

// --- delivered orders for the given day, with product reference from `products` ---
$d   = mysqli_real_escape_string($cn, $date);
$sql = "SELECT l.id                    AS external_id,
               DATE(l.delivred_at)     AS `date`,
               l.product               AS product,
               COALESCE(p.reference,'') AS ref,
               l.quantity              AS qty,
               l.price                 AS price
        FROM `lists` l
        LEFT JOIN `products` p ON p.name = l.product
        WHERE l.delivred_at IS NOT NULL
          AND l.canceled_at IS NULL
          AND l.deleted_at  IS NULL
          AND DATE(l.delivred_at) = '$d'
        ORDER BY l.delivred_at";

$res = mysqli_query($cn, $sql);

$out = array();
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $qty = (float) $r['qty'];
        if ($qty <= 0) { $qty = 1; }              // fall back to 1 if qty is blank/text
        $out[] = array(
            'external_id' => (string) $r['external_id'],
            'date'        => (string) $r['date'],
            'product'     => (string) $r['product'],
            'ref'         => (string) $r['ref'],
            'qty'         => $qty,
            'price'       => (float) $r['price'],
        );
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
