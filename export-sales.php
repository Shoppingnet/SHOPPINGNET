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
 *   { external_id, order_no, date, delivered_date, created_date, product, ref,
 *     qty, price, customer, phone, city, employee, image }
 *
 *   - date          = delivery/distribution date (revenue day) = DATE(delivred_at)
 *   - qty           = trustworthy item count (corrupted quantity rows -> 1)
 *   - price         = unit price = (order total - delivery) / qty
 *                     => qty * price = net montant (matches imrashop, delivery excl.)
 *   - ref (SKU)     = resolved from the `products` catalog by normalised name
 *
 * "Delivered" = the order's `delivred_at` is set (and not cancelled/deleted).
 * SELECT only — never writes. DB credentials are read at runtime from an
 * existing api_statu_*.php file (no secret stored in this file).
 * -----------------------------------------------------------------------------
 */

// This server runs with opcache.validate_timestamps=0 (compiled bytecode is
// cached and file changes are ignored until FPM reload). Evict our own cached
// copy on every request so a re-deployed file is always picked up fresh.
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }

/* ===== CONFIG ===== */
$SECRET       = 'tj_imrashop_9F3kZq7Lx2Wp';  // <-- secret to give TOP JEMLA
$DEFAULT_DAYS = 90;                           // <-- window when no date/range asked
$EMP_COL      = '';                           // <-- employee/agent column in `lists`, if any (else '')
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
// clean the displayed product name: strip a stray leading/trailing quantity marker
// like "x", "1x", "2 x" (the quantity belongs in `qty`, not in the name).
function clean_product($s) {
    $s = trim((string) $s);
    $s = preg_replace('/^\s*\d*\s*[xX]\s+/u', '', $s);   // leading "x " / "1x " / "2 x "
    $s = preg_replace('/\s+\d*\s*[xX]\s*$/u', '', $s);   // trailing " x" / " 1x"
    return trim($s);
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

// --- build product-name -> {ref, price} map (exact + normalised keys) ---
$prodByName = array();
$prodByNorm = array();
$pr = mysqli_query($cn, "SELECT name, reference, price FROM `products`");
if ($pr) {
    while ($row = mysqli_fetch_assoc($pr)) {
        $nm = (string) $row['name'];
        if ($nm === '') { continue; }
        $info = array('ref' => (string) $row['reference'], 'price' => (float) $row['price']);
        if (!isset($prodByName[$nm])) { $prodByName[$nm] = $info; }
        $k = norm_name($nm);
        if ($k !== '' && !isset($prodByNorm[$k])) { $prodByNorm[$k] = $info; }
    }
}

// --- delivered orders ---
$empSelect = ($EMP_COL !== '') ? ", l.`$EMP_COL` AS employee" : "";
$sql = "SELECT l.id                 AS external_id,
               l.id_order           AS order_no,
               DATE(l.delivred_at)  AS delivered_date,
               DATE(l.created_at)   AS created_date,
               l.product            AS product,
               l.quantity           AS qty,
               l.price              AS price,
               l.prix_de_laivraison AS livr,
               l.name               AS customer,
               l.tel                AS phone,
               l.city               AS city
               $empSelect
        FROM `lists` l
        WHERE l.delivred_at IS NOT NULL
          AND l.canceled_at IS NULL
          AND l.deleted_at  IS NULL
          AND $cond
        ORDER BY l.delivred_at";

$res = mysqli_query($cn, $sql);

$DEBUG    = (q_pick(array('debug')) !== '');   // &debug=1 -> diagnostic output
$refFilt  = q_pick(array('ref'));              // &ref=SN1023 -> only that ref, per-line

$out     = array();
$dbgRows = array();
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $rawProduct = (string) $r['product'];
        $product    = clean_product($rawProduct);

        // resolve ref from the catalog (exact name first, then normalised); match on
        // the raw and the cleaned name to maximise hits.
        $ref  = '';
        $info = null;
        foreach (array($rawProduct, $product) as $cand) {
            if ($cand === '') { continue; }
            if (isset($prodByName[$cand])) { $info = $prodByName[$cand]; break; }
            $k = norm_name($cand);
            if ($k !== '' && isset($prodByNorm[$k])) { $info = $prodByNorm[$k]; break; }
        }
        if ($info) { $ref = $info['ref']; }

        // `lists.price` is the order TOTAL montant (COD, incl. delivery); e.g.
        // order 651837 stores 349 = exactly what imrashop shows. `lists.quantity`
        // is UNRELIABLE — for many rows it holds the amount (349) instead of the
        // item count, yet genuine multi-item orders hold a real small count (2).
        //
        // TOP JEMLA computes revenue = price * qty, so we send:
        //   qty   = trustworthy item count (guarded against corrupted rows)
        //   price = unit price = net montant / qty
        // => revenue = qty * unit = net montant  (matches imrashop, delivery excl.)

        // net sale (without the delivery fee). Empty/0 stays 0 (order may be returned).
        $montant = (float) $r['price'];
        $livr    = (float) $r['livr'];
        $net     = $montant - $livr;
        if ($net < 0) { $net = $montant; }        // bogus delivery fee -> keep gross
        $net     = round($net, 2);

        // real item count. A raw quantity that is implausibly large, or that is
        // >= the montant, is the "amount-in-the-count-column" corruption -> treat
        // as 1. A small value (2, 3, ...) is a genuine multi-item order.
        $count = (int) $r['qty'];
        if ($count < 1 || $count > 20 || $count >= $montant) { $count = 1; }

        // unit price so that unit * count == net montant.
        $qty   = $count;
        $price = ($count > 0) ? round($net / $count, 2) : $net;

        $ddate = (string) $r['delivered_date'];
        $orderNo = (string) $r['order_no'];
        if ($orderNo === '' || $orderNo === '0') { $orderNo = (string) $r['external_id']; }

        $out[] = array(
            'external_id'    => (string) $r['external_id'],
            'order_no'       => $orderNo,
            'date'           => $ddate,                       // accounting date = delivery date
            'delivered_date' => $ddate,
            'created_date'   => (string) $r['created_date'],
            'product'        => $product,
            'ref'            => $ref,
            'qty'            => $qty,
            'price'          => $price,
            'customer'       => (string) $r['customer'],
            'phone'          => (string) $r['phone'],
            'city'           => (string) $r['city'],
            'employee'       => isset($r['employee']) ? (string) $r['employee'] : '',
            'image'          => '',                           // optional; TJ uses matched product image
        );

        if ($DEBUG) {
            $dbgRows[] = array(
                'external_id' => (string) $r['external_id'],
                'ref'         => $ref,
                'product'     => $product,
                'quantity_raw'=> (string) $r['qty'],   // exactly what `lists.quantity` holds
                'montant'     => $montant,             // `lists.price` (order total, COD)
                'livr'        => $livr,
                'qty_sent'    => $qty,                 // count we send after the guard
                'price_sent'  => $price,               // unit price we send
                'clamped'     => ((int) $r['qty'] !== $qty) ? 1 : 0,
            );
        }
    }
}

if ($DEBUG) {
    // filter to one ref if asked
    if ($refFilt !== '') {
        $keep = array();
        foreach ($dbgRows as $d) { if ($d['ref'] === $refFilt) { $keep[] = $d; } }
        $dbgRows = $keep;
    }
    // per-ref aggregation: line count, raw-quantity sum, sent-quantity sum, revenue
    $agg = array();
    foreach ($dbgRows as $d) {
        $k = $d['ref'] !== '' ? $d['ref'] : ('(no-ref) ' . $d['product']);
        if (!isset($agg[$k])) {
            $agg[$k] = array('ref' => $d['ref'], 'product' => $d['product'],
                             'lines' => 0, 'qty_raw_sum' => 0, 'qty_sent_sum' => 0,
                             'revenue_sent' => 0.0, 'clamped_lines' => 0);
        }
        $agg[$k]['lines']++;
        $agg[$k]['qty_raw_sum']  += (int) $d['quantity_raw'];
        $agg[$k]['qty_sent_sum'] += (int) $d['qty_sent'];
        $agg[$k]['revenue_sent'] += (float) $d['qty_sent'] * (float) $d['price_sent'];
        $agg[$k]['clamped_lines']+= (int) $d['clamped'];
    }
    foreach ($agg as &$a) { $a['revenue_sent'] = round($a['revenue_sent'], 2); }
    unset($a);
    $summary = array_values($agg);
    // sort by line count desc so the busiest products are first
    usort($summary, function ($x, $y) { return $y['lines'] - $x['lines']; });

    $totalRevenue = 0.0; $totalLines = 0; $totalQty = 0;
    foreach ($dbgRows as $d) {
        $totalRevenue += (float) $d['qty_sent'] * (float) $d['price_sent'];
        $totalLines++; $totalQty += (int) $d['qty_sent'];
    }

    echo json_encode(array(
        'condition'      => $cond,
        'total_lines'    => $totalLines,
        'total_qty_sent' => $totalQty,
        'total_revenue'  => round($totalRevenue, 2),
        'by_ref'         => $summary,
        'rows'           => ($refFilt !== '') ? $dbgRows : 'add &ref=SNxxxx to see per-line detail',
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
