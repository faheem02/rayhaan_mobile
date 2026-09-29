<?php
session_start();
require_once __DIR__ . '/../../includes/functions.php';

$ids = trim($_GET['ids'] ?? '');
$imei = trim($_GET['imei'] ?? '');
$all = isset($_GET['all']);

$serials = [];
if ($imei !== '') {
    $stmt = $pdo->prepare("
        SELECT ps.*, p.name AS product_name, p.code AS product_code, p.sale_price
        FROM product_serials ps
        JOIN products p ON p.id = ps.product_id
        WHERE ps.imei_number = ? OR ps.serial_number = ?
    ");
    $stmt->execute([$imei, $imei]);
    $serials = $stmt->fetchAll();
} elseif ($ids !== '') {
    $id_arr = array_filter(array_map('intval', explode(',', $ids)));
    if (!empty($id_arr)) {
        $in = implode(',', $id_arr);
        $serials = $pdo->query("
            SELECT ps.*, p.name AS product_name, p.code AS product_code, p.sale_price
            FROM product_serials ps
            JOIN products p ON p.id = ps.product_id
            WHERE ps.id IN ($in)
            ORDER BY ps.id ASC
        ")->fetchAll();
    }
} elseif ($all) {
    $serials = $pdo->query("
        SELECT ps.*, p.name AS product_name, p.code AS product_code, p.sale_price
        FROM product_serials ps
        JOIN products p ON p.id = ps.product_id
        WHERE ps.status = 'available'
        ORDER BY p.name ASC, ps.id ASC
    ")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Print Barcode Stickers | RAYHAAN MOBILE KAHUTA</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
  <script src="../../assets/js/JsBarcode.all.min.js"></script>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
      margin: 0;
      padding: 15px;
      background: #f1f5f9;
      color: #0f172a;
    }
    .no-print {
      max-width: 900px;
      margin: 0 auto 20px auto;
      padding: 15px 20px;
      background: #ffffff;
      border-radius: 8px;
      box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }
    .btn {
      display: inline-block;
      padding: 8px 16px;
      font-size: 14px;
      font-weight: 600;
      border-radius: 6px;
      cursor: pointer;
      text-decoration: none;
      border: 1px solid transparent;
    }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-secondary { background: #64748b; color: #fff; }
    .btn:hover { opacity: 0.9; }

    /* Stickers Container */
    .stickers-wrap {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      justify-content: center;
      max-width: 960px;
      margin: 0 auto;
    }

    /* Single Sticker */
    .sticker {
      width: 50mm;
      min-height: 30mm;
      padding: 3mm 2.5mm;
      background: #ffffff;
      border: 1px dashed #94a3b8;
      border-radius: 4px;
      text-align: center;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      page-break-inside: avoid;
    }
    .store-title {
      font-size: 9px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #0f172a;
      line-height: 1.1;
    }
    .store-sub {
      font-size: 7.5px;
      color: #64748b;
      margin-bottom: 2px;
    }
    .product-title {
      font-size: 8.5px;
      font-weight: 700;
      color: #1e293b;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 45mm;
      margin: 1px auto;
    }
    .product-specs {
      font-size: 7.5px;
      color: #475569;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 45mm;
      margin: 0 auto 2px auto;
    }
    .barcode-svg {
      width: 100% !important;
      max-width: 44mm;
      height: 24px !important;
      display: block;
      margin: 0 auto;
    }
    .imei-txt {
      font-family: monospace;
      font-size: 8.5px;
      font-weight: 800;
      letter-spacing: 0.5px;
      color: #0f172a;
      margin-top: 1px;
    }

    /* Print media styling */
    @media print {
      body {
        background: #fff;
        padding: 0;
        margin: 0;
      }
      .no-print { display: none !important; }
      .stickers-wrap {
        gap: 0;
        display: block;
      }
      .sticker {
        border: none;
        border-radius: 0;
        margin: 0 auto;
        page-break-after: always;
      }
      @page {
        size: 50mm 30mm;
        margin: 0;
      }
    }
  </style>
</head>
<body>

<div class="no-print">
  <div>
    <h3 style="margin:0; font-size:18px;"><i class="fas fa-barcode"></i> Barcode Stickers (<?= count($serials) ?>)</h3>
    <small style="color:#64748b;">Ready for Thermal Barcode Printer (50mm x 30mm) or Standard Paper</small>
  </div>
  <div>
    <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print Stickers</button>
    <a href="imei_search.php" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Back to Search</a>
  </div>
</div>

<?php if (empty($serials)): ?>
  <div style="text-align:center; padding: 40px; background:#fff; max-width:600px; margin: 0 auto; border-radius:8px;">
    <h4>No mobile serials found.</h4>
    <p class="text-muted">Please check available stock or purchase records.</p>
    <a href="imei_search.php" class="btn btn-primary">Back</a>
  </div>
<?php else: ?>
  <div class="stickers-wrap">
    <?php foreach ($serials as $idx => $s): ?>
      <?php if (!empty($s['imei_number']) && $s['imei_number'] !== '-'): ?>
        <div class="sticker">
          <div>
            <div class="store-title">RAYHAAN MOBILE KAHUTA</div>
            <div class="store-sub">PH: 0336-5389945</div>
            <div class="product-title"><?= htmlspecialchars($s['product_name']) ?></div>
            <?php if (!empty($s['notes'])): ?>
              <div class="product-specs"><?= htmlspecialchars($s['notes']) ?></div>
            <?php endif; ?>
          </div>
          <div>
            <svg id="bc_<?= $idx ?>_1" class="barcode-svg"></svg>
            <div class="imei-txt">IMEI: <?= htmlspecialchars($s['imei_number']) ?></div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($s['serial_number']) && $s['serial_number'] !== '-' && $s['serial_number'] !== $s['imei_number']): ?>
        <div class="sticker">
          <div>
            <div class="store-title">RAYHAAN MOBILE KAHUTA</div>
            <div class="store-sub">PH: 0336-5389945</div>
            <div class="product-title"><?= htmlspecialchars($s['product_name']) ?></div>
            <?php if (!empty($s['notes'])): ?>
              <div class="product-specs"><?= htmlspecialchars($s['notes']) ?> (SIM 2)</div>
            <?php endif; ?>
          </div>
          <div>
            <svg id="bc_<?= $idx ?>_2" class="barcode-svg"></svg>
            <div class="imei-txt">IMEI 2: <?= htmlspecialchars($s['serial_number']) ?></div>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
window.onload = function() {
  if (typeof JsBarcode !== 'undefined') {
    <?php foreach ($serials as $idx => $s): ?>
      <?php if (!empty($s['imei_number']) && $s['imei_number'] !== '-'): ?>
        try {
          JsBarcode('#bc_<?= $idx ?>_1', '<?= htmlspecialchars(trim($s['imei_number'])) ?>', {
            format: 'CODE128',
            width: 1.15,
            height: 24,
            displayValue: false,
            margin: 0
          });
        } catch(e) {}
      <?php endif; ?>
      <?php if (!empty($s['serial_number']) && $s['serial_number'] !== '-' && $s['serial_number'] !== $s['imei_number']): ?>
        try {
          JsBarcode('#bc_<?= $idx ?>_2', '<?= htmlspecialchars(trim($s['serial_number'])) ?>', {
            format: 'CODE128',
            width: 1.15,
            height: 24,
            displayValue: false,
            margin: 0
          });
        } catch(e) {}
      <?php endif; ?>
    <?php endforeach; ?>
  }
};
</script>

</body>
</html>