<?php
require_once __DIR__.'/../../includes/helpers.php'; require_once __DIR__.'/../../includes/service_client.php'; require_login(); $svc=service('assets');
if($_SERVER['REQUEST_METHOD']==='POST'){
  if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    flash('error','Your session expired or the security token is invalid. Refresh and try again.');
    redirect('/modules/asset_equipment/index.php');
  }
  try{$message=$svc->handle((string)($_POST['action']??''),$_POST,current_user());flash('success',$message);}
  catch(Throwable $e){error_log('Asset equipment action failed: '.$e->getMessage());flash('error','Unable to save record. Please review the submitted details and try again.');}
  redirect('/modules/asset_equipment/index.php');
}
$assets=$svc->assets(); $issuances=$svc->issuances();
$u=current_user();
$canUpdateIssuances = in_array((string)($u['role'] ?? ''), ['Administrator', 'Staff'], true);
page_header('Asset & Equipment Issuance','assets');show_flash();?>
<div class="asset-module-page">
<div class="gw-breadcrumb asset-breadcrumb"><span>Great Solomon Manpower Services Inc.</span><span aria-hidden="true">/</span><strong>Asset &amp; Equipment Issuance</strong></div>
<section class="gw-hero asset-module-hero"><div><div class="eyebrow">MODULE 4</div><h1>Asset &amp; Equipment Issuance Tracker</h1><p>Track equipment inventory, items available for borrowing, issuance, and returns.</p></div></section>

<section class="gw-quick-actions">
  <a href="#available-items"><span class="material-symbols-outlined">inventory_2</span> Items to Borrow</a>
  <a href="#issue"><span class="material-symbols-outlined">assignment_turned_in</span> Issue Equipment</a>
  <?php if(is_records_entry_account()): ?><a href="<?=e(url('/modules/asset_equipment/register_asset.php'))?>"><span class="material-symbols-outlined">add_box</span> Register Asset</a><?php endif; ?>
  <a href="#issuance-history"><span class="material-symbols-outlined">history</span> Issuance History</a>
</section>

<section class="gw-stats">
  <div class="gw-stat"><span class="gw-stat-label">Total Assets</span><div class="gw-stat-value"><?=count($assets)?></div><div class="gw-stat-meta">Asset registry</div></div>
  <div class="gw-stat"><span class="gw-stat-label">Available to Borrow</span><div class="gw-stat-value"><?=count(array_filter($assets,fn($a)=>($a['status']??'')==='Available' && (int)($a['available_quantity']??0)>0))?></div><div class="gw-stat-meta positive">Ready for issuance</div></div>
  <div class="gw-stat"><span class="gw-stat-label">Issued</span><div class="gw-stat-value"><?=count(array_filter($assets,fn($a)=>$a['status']==='Issued'))?></div><div class="gw-stat-meta">Currently assigned</div></div>
  <div class="gw-stat"><span class="gw-stat-label">Maintenance</span><div class="gw-stat-value"><?=count(array_filter($assets,fn($a)=>$a['status']==='Maintenance'))?></div><div class="gw-stat-meta warning">Needs service</div></div>
</section>

<section class="gw-panel asset-panel asset-section-spaced" id="available-items">
  <div class="gw-panel-head">
    <div>
      <h2>List of Items Available to Borrow</h2>
      <span>Equipment and asset inventory records</span>
    </div>
    <span class="material-symbols-outlined">inventory</span>
  </div>
  <div class="table-wrap asset-table-wrap">
    <table class="data-table" aria-label="Asset inventory">
      <thead>
        <tr>
          <th scope="col">Asset Tag</th>
          <th scope="col">Item / Description</th>
          <th scope="col">Category</th>
          <th scope="col">Location</th>
          <th scope="col">Total Stock</th>
          <th scope="col">Available</th>
          <th scope="col">Status</th>
          <th scope="col">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($assets as $a): 
          $avail = (int)($a['available_quantity'] ?? 0);
          $status = $a['status'] ?? 'Available';
          $statusClass = match($status) {
            'Available' => ($avail > 0 ? 'status-pill-success' : 'status-pill-warning'),
            'Issued' => 'status-pill-info',
            'Maintenance' => 'status-pill-warning',
            default => 'status-pill-neutral'
          };
        ?>
        <tr>
          <td><strong><?=e($a['asset_tag'])?></strong></td>
          <td><?=e($a['name'])?><?php if(!empty($a['serial_number'])): ?><br><small class="asset-serial">SN: <?=e($a['serial_number'])?></small><?php endif; ?></td>
          <td><?=e($a['category'] ?: 'General')?></td>
          <td><?=e($a['location'] ?: 'Head Office')?></td>
          <td><?=e($a['quantity'] ?? 1)?></td>
          <td><strong class="asset-quantity <?= $avail > 0 ? 'asset-quantity-positive' : 'asset-quantity-zero' ?>"><?=$avail?></strong></td>
          <td><span class="status-pill <?=$statusClass?>"><?=e($status)?></span></td>
          <td>
            <?php if($status === 'Available' && $avail > 0): ?>
              <button type="button" class="gw-btn primary asset-action-btn" onclick="selectAssetToBorrow('<?=(int)$a['id']?>')">Issue / Borrow</button>
            <?php else: ?>
              <span class="asset-unavailable">Unavailable</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$assets): ?>
        <tr><td colspan="8" class="empty asset-empty-state">No equipment or assets registered yet. You can register items below.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="record-form asset-section-spaced" id="issue">
  <div class="gw-panel-head"><h2>Issue Equipment</h2><span>Assign an available registered asset to an employee.</span></div>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="issue_asset">
    <div class="form-grid">
      <div>
        <label>Asset</label>
        <select id="assetSelect" name="asset_id" required>
          <option value="">Select available asset</option>
          <?php foreach($assets as $a): if(($a['status']??'')==='Available' && (int)$a['available_quantity']>0):?>
            <option value="<?=$a['id']?>"><?=e($a['asset_tag'].' — '.$a['name'])?> (<?=e($a['available_quantity'])?> available)</option>
          <?php endif; endforeach;?>
        </select>
      </div>
      <div><label>Employee Name</label><input id="employeeInput" name="employee_name" required placeholder="Employee full name"></div>
      <div><label>Issued Date</label><input type="date" name="issued_date" required value="<?=date('Y-m-d')?>"></div>
      <div><label>Expected Return</label><input type="date" name="expected_return"></div>
      <div class="full"><label>Notes / Purpose</label><textarea name="notes" placeholder="Purpose or condition details..."></textarea></div>
    </div>
    <div class="record-actions"><button class="gw-btn primary">Issue Equipment</button></div>
  </form>
</section>

<?php if(is_records_entry_account()): ?><section class="gw-panel form-launch-card asset-section-spaced"><div class="gw-panel-head"><div><h2>Register Equipment / Asset</h2><span>Add inventory items using the dedicated registration page.</span></div><a class="gw-btn primary" href="<?=e(url('/modules/asset_equipment/register_asset.php'))?>"><span class="material-symbols-outlined">add_box</span> Register Asset</a></div></section><?php endif; ?>
<section class="gw-panel asset-panel asset-section-spaced" id="issuance-history">
  <div class="gw-panel-head"><h2>Issuance History</h2><span><?=count($issuances)?> records</span></div>
  <div class="table-wrap asset-table-wrap">
    <table class="data-table" aria-label="Equipment issuance history">
      <thead>
        <tr><th>Asset</th><th>Employee</th><th>Issued</th><th>Expected Return</th><th>Status</th><th>Notes</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach($issuances as $r):?>
        <tr>
          <td><?=e($r['asset_tag'].' — '.$r['name'])?></td>
          <td><?=e($r['employee_name'])?></td>
          <td><?=e($r['issued_date'])?></td>
          <td><?=e($r['expected_return'])?></td>
          <td><span class="status-pill status-pill-<?=match($r['status']){'Returned'=>'success','Overdue'=>'warning','Not Returned'=>'warning',default=>'info'}?>"><?=e($r['status'])?></span></td>
          <td><?=e($r['notes'])?></td>
          <td>
            <?php if ($canUpdateIssuances): ?>
              <form method="post" class="asset-status-form">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                <input type="hidden" name="action" value="update_issuance_status">
                <input type="hidden" name="issuance_id" value="<?=(int)$r['id']?>">
                <label class="asset-status-label">
                  <span class="sr-only">Update status for <?=e($r['asset_tag'].' — '.$r['name'])?></span>
                  <select name="status" onchange="this.form.submit()">
                    <option value="Issued" <?=$r['status']==='Issued'?'selected':''?>>Issued</option>
                    <option value="Returned" <?=$r['status']==='Returned'?'selected':''?>>Returned</option>
                    <option value="Overdue" <?=$r['status']==='Overdue'?'selected':''?>>Overdue</option>
                    <option value="Not Returned" <?=$r['status']==='Not Returned'?'selected':''?>>Not Returned</option>
                  </select>
                </label>
              </form>
            <?php else: ?>
              <span class="asset-unavailable">View only</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach;if(!$issuances):?>
        <tr><td colspan="7" class="empty asset-empty-state">No issuance records.</td></tr>
        <?php endif;?>
      </tbody>
    </table>
  </div>
</section>

<script>
function selectAssetToBorrow(assetId) {
  const sel = document.getElementById('assetSelect');
  if (sel) {
    sel.value = assetId;
  }
  const issueSection = document.getElementById('issue');
  if (issueSection) {
    issueSection.scrollIntoView({ behavior: 'smooth' });
    const empInput = document.getElementById('employeeInput');
    if (empInput) empInput.focus();
  }
}
</script>
</div>
<?php page_footer(); ?>
