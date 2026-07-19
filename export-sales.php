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
 *   - from / to  : a date range (from/to, date_from/date_to, du/au, start/end).
 *   - days       : delivered within the last N days (backfill, capped 5000).
 *   - none given => last DEFAULT_DAYS days. TOP JEMLA de-dups on external_id.
 *
 * Output (one object per delivered sale line):
 *   { external_id, date, product, ref (SKU), qty, price }
 *
 * ref (SKU) is resolved from the `products` catalog by matching the product
 * name. Matching is done in PHP on a NORMALISED name (drops "(...)" pack notes,
 * dots and extra spaces) so almost every line carries its SKU even when the
 * stored name differs slightly from the catalog.
 *
 * "Delivered" = the order's `delivred_at` is set (and not cancelled/deleted).
 * SELECT only — never writes. DB credentials are read at runtime from an
 * existing api_statu_*.php file (no secret stored in this file).
 * -----------------------------------------------------------------------------
 */

/* ===== CONFIG ===== */
$SECRET       = 'tj_imrashop_9F3kZq7Lx2Wp';  // <-- secret to give TOP JEMLA
$DEFAULT_DAYS = 90;                           // <-- window when no date/range asked
/* ================= */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// --- auth ---
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
        if (isset($_GET[$k]) && $_GET[$k] !== '') { return (string) $_GET[$k]; }
    }
    return '';
}
function q_norm_date($s) {
    $s = trim($s);
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s)) { return $s; }
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m)) { return $m[3] . '-' . $m[2] . '-' . $m[1]; }
    return '';
}
// normalise a product name for matching (drop "(...)", dots, punctuation, extra spaces)
function norm_name($s) {
    $s = (string) $s;
    $s = preg_replace('/\([^)]*\)/u', ' ', $s);          // remove ( ... ) pack notes
    $s = str_replace(array('.', '،', ',', '-', '_'), ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);                // collapse whitespace
    $s = trim($s);
    if (function_exists('mb_strtolower')) { $s = mb_strtolower($s, 'UTF-8'); }
    return $s;
}

$single = q_norm_date(q_pick(array('date', 'day', 'jour')));
$from   = q_norm_date(q_pick(array('from', 'date_from', 'du', 'start', 'debut', 'min_date')));
$to     = q_norm_date(q_pick(array('to', 'date_to', 'au', 'end', 'fin', 'max_date')));
$days   = (int) q_pick(array('days', 'jours', 'last'));

if ($single !== '') {
    $cond = "DATE(l.delivred_at) = '$single'";
} elseif ($from !== '' || $to !== '') {
    $parts = array();
    if ($from !== '') { $parts[] = "DATE(l.delivred_at) >= '$from'"; }
    if ($to   !== '') { $parts[] = "DATE(l.delivred_at) <= '$to'"; }
    $cond = implode(' AND ', $parts);
} else {
    if ($days <= 0)   { $days = $DEFAULT_DAYS; }
    if ($days > 5000) { $days = 5000; }
    $cond = "DATE(l.delivred_at) >= DATE_SUB(CURDATE(), INTERVAL $days DAY)";
}

// --- DB credentials from an existing working API file ---
$DB_HOST = 'localhost'; $DB_USER = 'root'; $DB_PASS = ''; $DB_NAME = 'imrashop';
foreach (array('api_statu_soufian.php', 'api_statu_bestbigmall.php', 'api_statu_digylog.php') as $f) {
    $p = __DIR__ . '/' . $f;
    if (is_file($p) && ($src = @file_get_contents($p)) &&
        preg_match('/mysqli_connect\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) {
        $DB_HOST = $m[1]; $DB_USER = $m[2]; $DB_PASS = $m[3]; $DB_NAME = $m[4];
        break;
    }
}

$cn = @mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if (!$cn) { http_response_code(500); echo '[]'; exit; }
mysqli_set_charset($cn, 'utf8mb4');
mysqli_query($cn, "SET NAMES utf8mb4");

// --- build product-name -> reference map (exact + normalised keys) ---
$refByName = array();   // exact name
$refByNorm = array();   // normalised name
$pr = mysqli_query($cn, "SELECT name, reference FROM `products` WHERE reference IS NOT NULL AND reference <> ''");
if ($pr) {
    while ($row = mysqli_fetch_assoc($pr)) {
        $nm  = (string) $row['name'];
        $ref = (string) $row['reference'];
        if ($nm === '') { continue; }
        if (!isset($refByName[$nm])) { $refByName[$nm] = $ref; }
        $k = norm_name($nm);
        if ($k !== '' && !isset($refByNorm[$k])) { $refByNorm[$k] = $ref; }
    }
}

// --- delivered orders ---
$sql = "SELECT l.id                AS external_id,
               DATE(l.delivred_at) AS `date`,
               l.product           AS product,
               l.quantity          AS qty,
               l.price             AS price
        FROM `lists` l
        WHERE l.delivred_at IS NOT NULL
          AND l.canceled_at IS NULL
          AND l.deleted_at  IS NULL
          AND $cond
        ORDER BY l.delivred_at";

$res = mysqli_query($cn, $sql);

$out = array();
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $product = (string) $r['product'];
        // resolve ref: exact name first, then normalised name
        $ref = '';
        if ($product !== '' && isset($refByName[$product])) {
            $ref = $refByName[$product];
        } else {
            $k = norm_name($product);
            if ($k !== '' && isset($refByNorm[$k])) { $ref = $refByNorm[$k]; }
        }
        $qty = (float) $r['qty'];
        if ($qty <= 0) { $qty = 1; }
        $out[] = array(
            'external_id' => (string) $r['external_id'],
            'date'        => (string) $r['date'],
            'product'     => $product,
            'ref'         => $ref,
            'qty'         => $qty,
            'price'       => (float) $r['price'],
        );
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
