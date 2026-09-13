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
 *     qty, price, type, livraison, loss, customer, phone, city, employee, image }
 *
 *   - type      = normal | change (تبديل) | remboursement (إرجاع), from montant sign:
 *                   montant > 0 => normal ;  == 0 => change ;  < 0 => remboursement
 *   - price     = net unit price for normal sales; 0 for change/remboursement
 *   - livraison = delivery amount of the order
 *   - loss      = خسارة for change/remboursement (0 for normal):
 *                   change        => delivery
 *                   remboursement => |montant| + delivery  (provisional)
 *
 *   - one output line per `multisale` line item (real product + quantity); orders with
 *     no multisale row fall back to a single line from `lists` (qty 1).
 *   - date          = delivery/distribution date (revenue day) = DATE(delivred_at)
 *   - qty           = real quantity from `multisale.quanity` (NOT the lists.quantity
 *                     label nor a montant/catalog guess, both of which were unreliable)
 *   - price         = GROSS unit price = (multisale line total / qty), delivery INCLUDED.
 *                     SUM(qty*price) over an order == SUM(multisale.price) == imrashop
 *                     "مجموع المبيعات" (lists.price under-reports multi-product orders)
 *   - livraison     = the order's delivery (on the first line only). TOP JEMLA computes
 *                     net = SUM(qty*price) - livraison == imrashop "الصافي"
 *   - ref (SKU)     = `products.reference` via `multisale.productID` (exact, by id)
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
// Item count from the `lists.quantity` label. That column is human text, e.g.
//   "منتوج واحد ب 199 درهم"  -> 1   (واحد)
//   "منتوجين ب 298 درهم"     -> 2   (dual form, ...جين)
//   "3 منتوجات ب 417 درهم"   -> 3   (leading digit)
// The number after "ب" is the LINE TOTAL, not the count, so it is ignored here.
function parse_count($txt) {
    $txt = (string) $txt;
    // an explicit digit right before "منت..." (e.g. "3 منتوجات")
    if (preg_match('/(\d+)\s*منت/u', $txt, $m)) { $n = (int) $m[1]; if ($n >= 1 && $n <= 100) return $n; }
    // a leading digit at the very start of the label
    if (preg_match('/^\s*(\d+)\b/u', $txt, $m))  { $n = (int) $m[1]; if ($n >= 2 && $n <= 100) return $n; }
    // dual form of منتج / منتوج ("...جين") => two
    if (preg_match('/منت(?:و)?ج(?:ين|ان)/u', $txt)) { return 2; }
    // Arabic number words (check longer/larger first)
    $words = array('عشرة'=>10,'تسعة'=>9,'ثمانية'=>8,'سبعة'=>7,'ستة'=>6,'خمسة'=>5,
                   'أربعة'=>4,'اربعة'=>4,'ثلاثة'=>3,'ثلاث'=>3,'اثنين'=>2,'اثنان'=>2,'واحد'=>1);
    foreach ($words as $w => $n) {
        if (function_exists('mb_strpos') ? (mb_strpos($txt, $w) !== false) : (strpos($txt, $w) !== false)) {
            return $n;
        }
    }
    return 1;
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

// ===== DIAGNOSTIC: &find=<id,id,...> — locate orders across ALL databases =====
// Dumps the raw `lists` row(s) for the given imrashop order numbers (id_order) or
// row ids, from every database that has a `lists` table, so we can see exactly
// where an order lives and which column (delivred_at / canceled_at / deleted_at /
// source / موزّع) keeps it out of the normal export. Read-only, key-gated.
$find = q_pick(array('find'));
if ($find !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $ids = array();
    foreach (explode(',', $find) as $x) { $x = (int) trim($x); if ($x > 0) { $ids[] = $x; } }
    $idlist = $ids ? implode(',', $ids) : '0';
    $dbs = array();
    if ($rd = mysqli_query($cn, "SHOW DATABASES")) {
        while ($row = mysqli_fetch_row($rd)) {
            $dn = $row[0];
            if (in_array($dn, array('information_schema', 'mysql', 'performance_schema', 'sys'))) { continue; }
            $dbs[] = $dn;
        }
    }
    $tables = array();
    if ($rt = mysqli_query($cn, "SHOW TABLES FROM `$DB_NAME`")) {
        while ($row = mysqli_fetch_row($rt)) { $tables[] = $row[0]; }
    }
    $out = array('connected_db' => $DB_NAME, 'db_user' => $DB_USER, 'databases' => $dbs,
                 'tables_in_connected_db' => $tables, 'matches' => array());
    foreach ($dbs as $dn) {
        $chk = @mysqli_query($cn, "SHOW TABLES FROM `$dn` LIKE 'lists'");
        if (!$chk || mysqli_num_rows($chk) == 0) { continue; }
        $rs = @mysqli_query($cn, "SELECT * FROM `$dn`.`lists` WHERE id_order IN ($idlist) OR id IN ($idlist)");
        if ($rs) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $row['__db'] = $dn;
                $out['matches'][] = $row;
            }
        } else {
            $out['matches'][] = array('__db' => $dn, '__error' => mysqli_error($cn));
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ===== DIAGNOSTIC: &probe=<id,id> — find an order across order/item tables =====
// For each candidate table, dump rows where ANY column equals one of the ids, so we
// can see where a manually-entered ('page') order stores its product/quantity.
$probe = q_pick(array('probe'));
if ($probe !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $ids = array();
    foreach (explode(',', $probe) as $x) { $x = (int) trim($x); if ($x > 0) { $ids[] = $x; } }
    $idlist = $ids ? implode(',', $ids) : '0';
    $cand = array('lists', 'outside_orders', 'sortielistproducts', 'multisale', 'new', 'sentlists');
    $res = array('ids' => $ids, 'results' => array());
    foreach ($cand as $tb) {
        $cols = array();
        if ($rc = @mysqli_query($cn, "SHOW COLUMNS FROM `$DB_NAME`.`$tb`")) {
            while ($cr = mysqli_fetch_assoc($rc)) { $cols[] = $cr['Field']; }
        } else { $res['results'][$tb] = 'no such table'; continue; }
        $ors = array();
        foreach ($cols as $c) { $ors[] = "`$c` IN ($idlist)"; }
        $where = $ors ? implode(' OR ', $ors) : '0';
        $rows = array();
        if ($rr = @mysqli_query($cn, "SELECT * FROM `$DB_NAME`.`$tb` WHERE $where LIMIT 30")) {
            while ($row = mysqli_fetch_assoc($rr)) { $rows[] = $row; }
            $res['results'][$tb] = array('columns' => $cols, 'matches' => $rows);
        } else {
            $res['results'][$tb] = array('columns' => $cols, 'error' => mysqli_error($cn));
        }
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ===== DIAGNOSTIC: &join=<id,id> — order + its multisale line items joined to products =====
$join = q_pick(array('join'));
if ($join !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $ids = array();
    foreach (explode(',', $join) as $x) { $x = (int) trim($x); if ($x > 0) { $ids[] = $x; } }
    $res = array();
    foreach ($ids as $oid) {
        $entry = array('order_id' => $oid);
        $rs = @mysqli_query($cn, "SELECT id, name, product, quantity, price, prix_de_laivraison, delivred_at FROM `$DB_NAME`.`lists` WHERE id = $oid");
        $entry['lists'] = $rs ? mysqli_fetch_assoc($rs) : null;
        $items = array();
        $q = "SELECT ms.id AS ms_id, ms.productID, ms.price AS ms_price, ms.quanity AS ms_qty,
                     p.name AS p_name, p.reference AS p_ref, p.price AS p_catalog
              FROM `$DB_NAME`.`multisale` ms
              LEFT JOIN `$DB_NAME`.`products` p ON p.id = ms.productID
              WHERE ms.listID = $oid";
        if ($ri = @mysqli_query($cn, $q)) { while ($row = mysqli_fetch_assoc($ri)) { $items[] = $row; } }
        else { $entry['items_error'] = mysqli_error($cn); }
        $entry['multisale_items'] = $items;
        $res[] = $entry;
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ===== DIAGNOSTIC: &mismatch=1 — orders where lists.price != SUM(multisale) =====
if (q_pick(array('mismatch')) !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $q = "SELECT l.id, l.price AS montant, l.prix_de_laivraison AS livr,
                 COALESCE(SUM(ms.price * ms.quanity), 0) AS ms_sum, COUNT(ms.id) AS n_items
          FROM `lists` l
          LEFT JOIN `multisale` ms ON ms.listID = l.id
          WHERE l.delivred_at IS NOT NULL AND l.canceled_at IS NULL AND l.deleted_at IS NULL AND $cond
          GROUP BY l.id";
    $rows = array(); $sumMont = 0.0; $sumMs = 0.0; $nMismatch = 0;
    if ($rq = mysqli_query($cn, $q)) {
        while ($g = mysqli_fetch_assoc($rq)) {
            $mont = (float) $g['montant']; if ($mont > 100000 || $mont < -100000) { $mont = 0; }
            $mss  = (float) $g['ms_sum'];
            $sumMont += $mont; $sumMs += $mss;
            if (abs($mont - $mss) > 1) {
                $nMismatch++;
                if (count($rows) < 40) {
                    $rows[] = array('id' => $g['id'], 'lists_price' => $mont,
                                    'multisale_sum' => round($mss, 2), 'diff' => round($mont - $mss, 2),
                                    'n_items' => (int) $g['n_items'], 'livr' => (float) $g['livr']);
                }
            }
        }
    }
    echo json_encode(array(
        'condition'        => $cond,
        'sum_lists_price'  => round($sumMont, 2),   // what total_gross currently uses
        'sum_multisale'    => round($sumMs, 2),     // sum of real per-product prices
        'diff'             => round($sumMont - $sumMs, 2),
        'orders_mismatch'  => $nMismatch,
        'examples'         => $rows,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ===== DIAGNOSTIC: &dbcounts=1 — delivered-order count per database for $cond =====
if (q_pick(array('dbcounts')) !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $dbs = array();
    if ($rd = mysqli_query($cn, "SHOW DATABASES")) {
        while ($row = mysqli_fetch_row($rd)) {
            $dn = $row[0];
            if (in_array($dn, array('information_schema', 'mysql', 'performance_schema', 'sys', 'phpmyadmin'))) { continue; }
            $dbs[] = $dn;
        }
    }
    $res = array('condition' => $cond, 'connected_db' => $DB_NAME, 'counts' => array());
    foreach ($dbs as $dn) {
        $chk = @mysqli_query($cn, "SHOW TABLES FROM `$dn` LIKE 'lists'");
        if (!$chk || mysqli_num_rows($chk) == 0) { $res['counts'][$dn] = 'no lists table'; continue; }
        $q = "SELECT COUNT(*) FROM `$dn`.`lists` l
              WHERE l.delivred_at IS NOT NULL AND l.canceled_at IS NULL AND l.deleted_at IS NULL AND $cond";
        $rs = @mysqli_query($cn, $q);
        if ($rs) { $r0 = mysqli_fetch_row($rs); $res['counts'][$dn] = (int) $r0[0]; }
        else     { $res['counts'][$dn] = 'ERR: ' . mysqli_error($cn); }
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

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

// --- delivered orders (one header row per order) ---
$empSelect = ($EMP_COL !== '') ? ", l.`$EMP_COL` AS employee" : "";
$sql = "SELECT l.id                 AS external_id,
               l.id_order           AS order_no,
               DATE(l.delivred_at)  AS delivered_date,
               DATE(l.created_at)   AS created_date,
               l.product            AS product,
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
        ORDER BY l.delivred_at, l.id";

// --- real line items from `multisale` (the trustworthy product + quantity) ---
// multisale(listID -> productID, quanity, price); productID -> products(id,name,reference).
// This is the source of truth: lists.product/lists.quantity are unreliable (null for
// manually-entered orders; the quantity label can even say "منتوجين" for a 1-piece sale),
// so we build every output line from multisale and fall back to the lists row only when
// an order has no multisale rows at all.
$msMap = array();
$msSql = "SELECT ms.listID AS lid, ms.productID AS pid, ms.price AS ms_price, ms.quanity AS ms_qty,
                 p.name AS p_name, p.reference AS p_ref
          FROM `multisale` ms
          JOIN `lists` l ON l.id = ms.listID
          LEFT JOIN `products` p ON p.id = ms.productID
          WHERE l.delivred_at IS NOT NULL AND l.canceled_at IS NULL AND l.deleted_at IS NULL AND $cond
          ORDER BY ms.listID, ms.id";
if ($mr = @mysqli_query($cn, $msSql)) {
    while ($m = mysqli_fetch_assoc($mr)) {
        $lid = (string) $m['lid'];
        if (!isset($msMap[$lid])) { $msMap[$lid] = array(); }
        $msMap[$lid][] = $m;
    }
}

$res = mysqli_query($cn, $sql);

$DEBUG    = (q_pick(array('debug')) !== '');   // &debug=1 -> diagnostic output
$refFilt  = q_pick(array('ref'));              // &ref=SN1023 -> only that ref, per-line

$out     = array();
$dbgRows = array();
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $orderId  = (string) $r['external_id'];
        $ddate    = (string) $r['delivered_date'];
        $createdD = (string) $r['created_date'];
        $orderNo  = (string) $r['order_no'];
        if ($orderNo === '' || $orderNo === '0') { $orderNo = $orderId; }
        $customer = (string) $r['customer'];
        $phone    = (string) $r['phone'];
        $cityv    = (string) $r['city'];
        $emp      = isset($r['employee']) ? (string) $r['employee'] : '';

        // ---- order total + delivery (varchar in `lists`; guard corrupt magnitudes) ----
        $montant = (float) $r['price'];
        if ($montant > 100000 || $montant < -100000) { $montant = 0; }
        $livr = (float) $r['livr'];
        if ($livr < 0 || $livr > 100000) { $livr = 0.0; }

        // ---- ORDER TYPE, from the sign of the order total (`lists.price`) ----
        //   > 0 => normal ;  == 0 => change (تبديل) ;  < 0 => remboursement (إرجاع)
        if ($montant > 0)      { $type = 'normal'; }
        elseif ($montant < 0)  { $type = 'remboursement'; }
        else                   { $type = 'change'; }

        // net order total (delivery excluded) = imrashop "الصافي"
        $net = $montant - $livr;
        if ($net < 0) { $net = $montant; }
        $net = round($net, 2);

        // order-level loss (only for change / remboursement)
        if     ($type === 'change')        { $orderLoss = round($livr, 2); }               // we ship the swap
        elseif ($type === 'remboursement') { $orderLoss = round(abs($montant) + $livr, 2); } // money back + delivery
        else                               { $orderLoss = 0.0; }

        // ---- build line items from `multisale`; fall back to the lists header row ----
        $items = isset($msMap[$orderId]) ? $msMap[$orderId] : array();
        $lines = array();
        if (!empty($items)) {
            foreach ($items as $m) {
                $q = (int) round((float) $m['ms_qty']);
                if ($q < 1 || $q > 200) { $q = 1; }         // real quantity, guarded
                $pn = ($m['p_name'] !== null && $m['p_name'] !== '')
                        ? (string) $m['p_name'] : clean_product((string) $r['product']);
                $rf = ($m['p_ref'] !== null) ? (string) $m['p_ref'] : '';
                // multisale.price is the LINE TOTAL for that product (already covers all its
                // units) — NOT a unit price. It is the reliable per-product sale amount;
                // lists.price only records part of a multi-product order.
                $lineTotal = (float) $m['ms_price'];
                if ($lineTotal < 0 || $lineTotal > 1000000) { $lineTotal = 0; }
                $lines[] = array('product' => $pn, 'ref' => $rf, 'qty' => $q,
                                 'gross' => $lineTotal);
            }
        } else {
            // no multisale row: one line from the lists header (qty defaults to 1),
            // ref resolved by name from the catalog.
            $rawProduct = (string) $r['product'];
            $productC   = clean_product($rawProduct);
            $rf = '';
            foreach (array($rawProduct, $productC) as $cand) {
                if ($cand === '') { continue; }
                if (isset($prodByName[$cand])) { $rf = $prodByName[$cand]['ref']; break; }
                $k = norm_name($cand);
                if ($k !== '' && isset($prodByNorm[$k])) { $rf = $prodByNorm[$k]['ref']; break; }
            }
            $lines[] = array('product' => $productC, 'ref' => $rf, 'qty' => 1,
                             'gross' => ($montant > 0 ? $montant : 0.0));
        }

        $grossSum = 0.0; foreach ($lines as $ln) { $grossSum += $ln['gross']; }
        $nLines = count($lines);

        foreach ($lines as $idx => $ln) {
            $q = $ln['qty'];
            if ($type === 'normal') {
                // GROSS line total straight from multisale (ms.price). SUM over the order
                // == imrashop "مجموع المبيعات" (delivery included). lists.price is NOT used
                // for the amount — it under-reports multi-product orders. Delivery stays in
                // `livraison`; TOP JEMLA computes net = SUM(qty*price) - livraison ("الصافي").
                $lineGross = $ln['gross'];                       // = ms.price (line total)
                $price = ($q > 0) ? round($lineGross / $q, 2) : $lineGross;  // unit price
                $lineLoss = 0.0;
            } else {
                $price    = 0.0;                              // change/refund: not a real sale
                $lineLoss = ($idx === 0) ? $orderLoss : 0.0;  // full loss once, on the first line
            }
            // external_id stays unique & stable: keep the order id for the first line (so
            // already-imported single-item orders don't duplicate), suffix any extra lines.
            $extId    = ($idx === 0) ? $orderId : ($orderId . '-' . ($idx + 1));
            $lineLivr = ($idx === 0) ? round($livr, 2) : 0.0;  // delivery once, on the first line

            $out[] = array(
                'external_id'    => $extId,
                'order_no'       => $orderNo,
                'date'           => $ddate,                       // accounting date = delivery date
                'delivered_date' => $ddate,
                'created_date'   => $createdD,
                'product'        => $ln['product'],
                'ref'            => $ln['ref'],
                'qty'            => $q,
                'price'          => $price,
                'type'           => $type,                        // normal | change | remboursement
                'livraison'      => $lineLivr,
                'loss'           => $lineLoss,
                'customer'       => $customer,
                'phone'          => $phone,
                'city'           => $cityv,
                'employee'       => $emp,
                'image'          => '',
            );

            if ($DEBUG) {
                $dbgRows[] = array(
                    'external_id' => $extId,
                    'ref'         => $ln['ref'],
                    'product'     => $ln['product'],
                    'montant'     => $montant,
                    'livr'        => $livr,
                    'qty_sent'    => $q,
                    'price_sent'  => $price,        // GROSS unit price (incl delivery)
                    'line_livr'   => $lineLivr,     // delivery for this order (first line only)
                    'type'        => $type,
                    'loss'        => $lineLoss,
                    'src'         => empty($items) ? 'lists' : 'multisale',
                );
            }
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
                             'lines' => 0, 'qty_sent_sum' => 0, 'revenue_sent' => 0.0);
        }
        $agg[$k]['lines']++;
        $agg[$k]['qty_sent_sum'] += (int) $d['qty_sent'];
        $agg[$k]['revenue_sent'] += (float) $d['qty_sent'] * (float) $d['price_sent'];
    }
    foreach ($agg as &$a) { $a['revenue_sent'] = round($a['revenue_sent'], 2); }
    unset($a);
    $summary = array_values($agg);
    // sort by line count desc so the busiest products are first
    usort($summary, function ($x, $y) { return $y['lines'] - $x['lines']; });

    $totalRevenue = 0.0; $totalLines = 0; $totalQty = 0; $totalLivr = 0.0;
    // per-type breakdown (normal / change / remboursement)
    $byType = array(
        'normal'        => array('count' => 0, 'revenue' => 0.0, 'loss' => 0.0),
        'change'        => array('count' => 0, 'revenue' => 0.0, 'loss' => 0.0),
        'remboursement' => array('count' => 0, 'revenue' => 0.0, 'loss' => 0.0),
    );
    $specialRows = array();   // the change/remboursement lines, listed out
    foreach ($dbgRows as $d) {
        $totalRevenue += (float) $d['qty_sent'] * (float) $d['price_sent'];
        $totalLines++;
        $totalQty  += (int) $d['qty_sent'];
        $totalLivr += (float) (isset($d['line_livr']) ? $d['line_livr'] : 0);
        $t = isset($d['type']) ? $d['type'] : 'normal';
        if (!isset($byType[$t])) { $byType[$t] = array('count'=>0,'revenue'=>0.0,'loss'=>0.0); }
        $byType[$t]['count']++;
        $byType[$t]['revenue'] += (float) $d['qty_sent'] * (float) $d['price_sent'];
        $byType[$t]['loss']    += (float) $d['loss'];
        if ($t !== 'normal') {
            $specialRows[] = array(
                'external_id' => $d['external_id'], 'ref' => $d['ref'],
                'product' => $d['product'], 'type' => $t,
                'montant' => $d['montant'], 'livr' => $d['livr'], 'loss' => $d['loss'],
            );
        }
    }
    foreach ($byType as &$bt) { $bt['revenue'] = round($bt['revenue'],2); $bt['loss'] = round($bt['loss'],2); }
    unset($bt);

    echo json_encode(array(
        'condition'         => $cond,
        'total_lines'       => $totalLines,   // output lines (one per multisale item)
        'total_qty_sent'    => $totalQty,     // sum of real quantities
        'total_gross'       => round($totalRevenue, 2),               // SUM(qty*price) = مجموع المبيعات
        'total_livraison'   => round($totalLivr, 2),                  // = سعر التوصيل
        'total_net'         => round($totalRevenue - $totalLivr, 2),  // gross - delivery = الصافي
        'total_revenue'     => round($totalRevenue - $totalLivr, 2),  // net (kept for back-compat)
        'by_type'           => $byType,       // normal / change / remboursement (count, revenue, loss)
        'special_orders'    => $specialRows,  // every تبديل/إرجاع line with its loss
        'by_ref'            => $summary,
        'rows'              => ($refFilt !== '') ? $dbgRows : 'add &ref=SNxxxx to see per-line detail',
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
