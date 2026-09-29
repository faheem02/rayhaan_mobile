<?php
session_start();
require_once '../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$party = getById('general_parties', $id);
if (!$party) { echo '<div class="alert alert-danger">Party not found</div>'; exit; }

$transactions = $pdo->prepare("SELECT gt.*, u.username FROM general_transactions gt LEFT JOIN users u ON gt.created_by = u.id WHERE gt.party_id = ? ORDER BY gt.transaction_date ASC, gt.id ASC");
$transactions->execute([$id]);
$transactions = $transactions->fetchAll();

$opening = (float)$party['opening_balance'];
$total_receipts = array_sum(array_map(fn($t) => $t['type'] === 'receipt' ? (float)$t['amount'] : 0, $transactions));
$total_payments = array_sum(array_map(fn($t) => $t['type'] === 'payment' ? (float)$t['amount'] : 0, $transactions));
$closing = $opening + $total_receipts - $total_payments;
?>

<div class="row">
  <div class="col-md-5">
    <div class="card mb-3">
      <div class="card-header"><strong><?=htmlspecialchars($party['name'])?></strong></div>
      <div class="card-body small">
        <p class="mb-1"><strong>Phone:</strong> <?=htmlspecialchars($party['phone'] ?? '-')?></p>
        <p class="mb-1"><strong>Address:</strong> <?=htmlspecialchars($party['address'] ?? '-')?></p>
        <p class="mb-1"><strong>Opening:</strong> <?=formatCurrency($opening)?></p>
        <p class="mb-0"><strong>Balance:</strong> <span style="color:#dc2626;font-weight:700;"><?=formatCurrency($closing)?></span></p>
      </div>
    </div>
    <div class="text-center mb-3">
      <a href="view.php?id=<?=$id?>" class="btn btn-sm btn-primary"><i class="fas fa-external-link-alt"></i> Full Page</a>
      <a href="ledger_print.php?id=<?=$id?>" target="_blank" class="btn btn-sm btn-danger"><i class="fas fa-print"></i> Print</a>
    </div>
  </div>
  <div class="col-md-7">
    <div class="card">
      <div class="card-header"><strong>Transactions</strong></div>
      <div class="card-body p-0">
        <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
          <table class="table table-bordered table-sm mb-0">
            <thead class="thead-light" style="position:sticky;top:0;">
              <tr><th>Date</th><th>Type</th><th class="text-right">Receipt</th><th class="text-right">Payment</th><th class="text-right">Balance</th></tr>
            </thead>
            <tbody>
              <tr style="background:#f8f9fc;"><td colspan="4" class="text-right"><strong>Opening Balance</strong></td><td class="text-right"><strong><?=formatCurrency($opening)?></strong></td></tr>
              <?php $bal = $opening; foreach ($transactions as $t):
                $bal += $t['type'] === 'receipt' ? (float)$t['amount'] : -(float)$t['amount'];
              ?>
                <tr>
                  <td><?=formatDate($t['transaction_date'])?></td>
                  <td><span class="badge badge-<?=$t['type']==='receipt'?'success':'danger'?>"><?=ucfirst($t['type'])?></span></td>
                  <td class="text-right"><?=$t['type']==='receipt'?formatCurrency($t['amount']):'-'?></td>
                  <td class="text-right"><?=$t['type']==='payment'?formatCurrency($t['amount']):'-'?></td>
                  <td class="text-right"><strong><?=formatCurrency($bal)?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
