<?php
/**
 * export-sales.php  —  Shopping Net (imrashop)  ->  TOP JEMLA
 * -----------------------------------------------------------------------------
 * Read-only sales export endpoint. Returns DELIVERED (livré) sale lines as JSON.
 *
 *   GET  http://207.154.254.26/export-sales.php?key=SECRET[&date=][&from=&to=][&days=N]
 *
 *   - key        : shared secret. Bad/missing key  =>  HTTP 401 + [].
 *   - date       : one specific day (YYYY-MM-DD).
 *   - from / to  : a date range (accepts from/to, date_from/date_to, du/au,
 *                  start/end). Either bound may be given alone.
 *   - days       : delivered within the last N days (e.g. days=3650 to backfill
 *                  all old orders). Capped at 5000.
 *   - if none of the above is given => last DEFAULT_DAYS days (a safe window so
 *     a missed daily pull never loses orders). TOP JEMLA de-dups on external_id,
 *     so returning extra days is harmless.
 *
 * Dates accepted as YYYY-MM-DD or DD/MM/YYYY.
 *
 * "Delivered" = the order's `delivred_at` timestamp is set (and it is not
 * cancelled/deleted). This file only ever runs SELECT — it never writes.
 *
 * The DB credentials are NOT hard-coded here: they are read at runtime from an
 * existing api_statu_*.php file that already connects to the imrashop database.
 * -----------------------------------------------------------------------------
 */

/* ===== CONFIG ===== */
$SECRET       = 'tj_imrashop_9F3kZq7Lx2Wp';  // <-- secret to give TOP JEMLA (change if you want)
$DEFAULT_DAYS = 90;                           // <-- window returned when no date/range is asked
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

// --- helpers ---
function q_pick($keys) {
    foreach ($keys as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') {
            return (string) $_GET[$k];
        }
    }
    return '';
}
function q_norm_date($s) {                       // -> YYYY-MM-DD (validated) or ''
    $s = trim($s);
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s)) {
        return $s;
    }
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return '';
}

$single = q_norm_date(q_pick(array('date', 'day', 'jour')));
$from   = q_norm_date(q_pick(array('from', 'date_from', 'du', 'start', 'debut', 'min_date')));
$to     = q_norm_date(q_pick(array('to', 'date_to', 'au', 'end', 'fin', 'max_date')));
$days   = (int) q_pick(array('days', 'jours', 'last'));

// --- build the delivered-date condition (values are strictly validated dates) ---
if ($single !== '') {
    $cond = "DATE(l.delivred_at) = '$single'";
} elseif ($from !== '' || $to !== '') {
    $parts = array();
    if ($from !== '') { $parts[] = "DATE(l.delivred_at) >= '$from'"; }
    if ($to   !== '') { $parts[] = "DATE(l.delivred_at) <= '$to'"; }
    $cond = implode(' AND ', $parts);
} else {
    if ($days <= 0)    { $days = $DEFAULT_DAYS; }
    if ($days > 5000)  { $days = 5000; }
    $cond = "DATE(l.delivred_at) >= DATE_SUB(CURDATE(), INTERVAL $days DAY)";
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

// --- delivered orders, with product reference from `products` ---
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
          AND $cond
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
