<?php
/**
 * export-sales.php
 * -----------------------------------------------------------------------------
 * Shopping Net (imrashop)  →  TOP JEMLA — Sales export endpoint (READ-ONLY).
 *
 * Usage:
 *   GET  http://207.154.254.26/export/export-sales.php?key=SECRET&date=YYYY-MM-DD
 *
 *   - key   : shared secret. Bad/missing key  =>  HTTP 401.
 *   - date  : optional. If missing  =>  today's date (server time).
 *
 * Returns: a JSON array, one element per sold line. Only DELIVERED (livré)
 *          orders are returned. This file only ever runs SELECT (read-only).
 *
 *   [ { "external_id":"1001", "date":"2026-07-19",
 *       "product":"اسم المنتج", "ref":"A1", "qty":2, "price":150 } ]
 * -----------------------------------------------------------------------------
 *
 *  ====== عمّر غير هاد البلوكة ديال CONFIG (ماتحتاجش تبدّل شي حاجة أخرى) ======
 */

// 1) السّر المشترك — بدّلو بسّر طويل وعشوائي، وعطيه لـ TOP JEMLA.
//    مثلا generi‑ه ب:  php -r "echo bin2hex(random_bytes(24));"
const SECRET = 'CHANGE_ME_LONG_RANDOM';

// 2) معلومات الاتصال بقاعدة البيانات (MySQL / MariaDB) ديال Shopping Net.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'imrashop';        // <-- اسم الداتابيز ديالك
const DB_USER = 'DBUSER';          // <-- مستعمل الداتابيز
const DB_PASS = 'DBPASS';          // <-- كلمة السّر ديال الداتابيز
const DB_CHARSET = 'utf8mb4';

// 3) اسم جدول الطلبيات، وأسماء الأعمدة فيه.
//    بدّل القيم اليمنى باش تطابق سكيمة الداتابيز ديالك.
const TBL_ORDERS  = 'orders';          // جدول الطلبيات
const COL_ID      = 'id';              // معرّف الطلب/السطر  -> external_id
const COL_DATE    = 'created_at';      // تاريخ البيع (DATE أو DATETIME)
const COL_PRODUCT = 'product_name';   // اسم المنتج        -> product
const COL_REF     = 'product_ref';    // مرجع/SKU المنتج    -> ref  (اختياري)
const COL_QTY     = 'qty';            // الكمية             -> qty
const COL_PRICE   = 'unit_price';     // ثمن البيع للوحدة   -> price
const COL_STATUS  = 'status';         // عمود حالة الطلب

// 4) القيمة اللي كتعني "مسلّم / livré" فعمود الحالة عندك.
//    إيلا كانت الحالة عندك رقم (مثلا 4) أو كلمة أخرى، بدّلها هنا.
//    نقدرو نقبلو أكثر من قيمة (مثلا 'livre' و 'delivered' و 'livré').
const DELIVERED_STATUSES = ['livre', 'livré', 'delivered', 'مسلمة', 'مسلّمة'];

/**  ================= ماتحتاجش تبدّل والو من هنا لتحت =================  */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// --- التحقّق من السّر (مقارنة آمنة ضد timing attacks) ---
$providedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
if (SECRET === 'CHANGE_ME_LONG_RANDOM' || !hash_equals(SECRET, $providedKey)) {
    http_response_code(401);
    echo '[]';
    exit;
}

// --- التاريخ: اختياري. نقبلو غير الصيغة YYYY-MM-DD، وإلا كنستعملو اليوم ---
$date = isset($_GET['date']) ? (string) $_GET['date'] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // placeholders للحالات المسلّمة (IN (?, ?, ...))
    $statusPlaceholders = implode(',', array_fill(0, count(DELIVERED_STATUSES), '?'));

    // أسماء الأعمدة/الجداول جايين من CONFIG (ماشي من المستعمل) => آمنين.
    // القيم (date, statuses) دايرين ب bound parameters => آمنين من SQL injection.
    $sql = 'SELECT '
         . '`' . COL_ID      . '` AS external_id, '
         . 'DATE(`' . COL_DATE . '`) AS `date`, '
         . '`' . COL_PRODUCT . '` AS product, '
         . '`' . COL_REF     . '` AS ref, '
         . '`' . COL_QTY     . '` AS qty, '
         . '`' . COL_PRICE   . '` AS price '
         . 'FROM `' . TBL_ORDERS . '` '
         . 'WHERE DATE(`' . COL_DATE . '`) = ? '
         . 'AND `' . COL_STATUS . '` IN (' . $statusPlaceholders . ')';

    $params = array_merge([$date], DELIVERED_STATUSES);

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // تنظيف/توحيد الأنواع فالخرجة
    $out = array_map(static function (array $r): array {
        return [
            'external_id' => (string) ($r['external_id'] ?? ''),
            'date'        => (string) ($r['date'] ?? ''),
            'product'     => (string) ($r['product'] ?? ''),
            'ref'         => (string) ($r['ref'] ?? ''),
            'qty'         => (float)  ($r['qty'] ?? 0),
            'price'       => (float)  ($r['price'] ?? 0),
        ];
    }, $rows);

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // ماكنكشفوش تفاصيل الخطأ للخارج
    http_response_code(500);
    error_log('export-sales.php: ' . $e->getMessage());
    echo '[]';
}
