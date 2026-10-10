<?php
require_once __DIR__.'/../../includes/helpers.php'; require_once __DIR__.'/../../includes/service_client.php'; require_login(); $svc=service('assets');
if($_SERVER['REQUEST_METHOD']==='POST'){
  if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    flash('error','Your session expired or the security token is invalid. Refresh and try again.');
    redirect('/modules/asset_equipment/index.php');
  }
  try{$message=$svc->handle((string)($_POST['action']??''),$_POST,current_user(),$_FILES);flash('success',$message);}
  catch(Throwable $e){error_log('Asset equipment action failed: '.$e->getMessage());$msg=$e->getMessage();$safe=(($e instanceof RuntimeException) && (str_contains($msg,'asset image') || str_contains($msg,'Asset image') || str_contains($msg,'Asset images') || str_contains($msg,'Unsupported image') || str_contains($msg,'uploaded image') || str_contains($msg,'asset photo') || str_contains($msg,'Asset photo') || str_contains($msg,'upload temporary') || str_contains($msg,'server could not write')));flash('error',$safe?$msg:'Unable to save record. Please review the submitted details and try again.');}
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
          <th scope="col">Photo</th>
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
          $photoBase = hash('sha256', strtoupper(trim((string)$a['asset_tag'])));
          $photoDir = __DIR__.'/../../storage/asset_images/';
          $photoUrl = '';
          foreach (['jpg','png','webp'] as $photoExt) {
            if (is_file($photoDir.$photoBase.'.'.$photoExt)) { $photoUrl = url('/asset_image.php?id='.(int)$a['id']); break; }
          }
        ?>
        <tr>
          <td class="asset-photo-cell"><?php if($photoUrl!==''): ?><img class="asset-table-photo" src="<?=e($photoUrl)?>" alt="Photo of <?=e($a['name'])?>" loading="lazy"><?php else: ?><span class="asset-photo-placeholder" title="No asset photo"><span class="material-symbols-outlined" aria-hidden="true">image</span></span><?php endif; ?></td>
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
        <tr><td colspan="9" class="empty asset-empty-state">No equipment or assets registered yet. You can register items below.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="record-form asset-section-spaced" id="issue">
  <div class="gw-panel-head"><h2>Issue Equipment</h2><span>Assign an available registered asset to an employee.</span></div>
  <form method="post" enctype="multipart/form-data" id="assetIssueForm">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="issue_asset">
    <div class="form-grid">
      <div>
        <label>Asset</label>
        <select id="assetSelect" name="asset_id" required>
          <option value="">Select available asset</option>
          <?php foreach($assets as $a): if(($a['status']??'')==='Available' && (int)$a['available_quantity']>0):
            $photoBase = hash('sha256', strtoupper(trim((string)$a['asset_tag'])));
            $photoDir = __DIR__.'/../../storage/asset_images/';
            $hasPhoto = false;
            foreach (['jpg','png','webp'] as $photoExt) { if (is_file($photoDir.$photoBase.'.'.$photoExt)) { $hasPhoto = true; break; } }
          ?>
            <option value="<?=(int)$a['id']?>" data-photo-url="<?=$hasPhoto ? e(url('/asset_image.php?id='.(int)$a['id'])) : ''?>"><?=e($a['asset_tag'].' — '.$a['name'])?> (<?=e($a['available_quantity'])?> available)</option>
          <?php endif; endforeach;?>
        </select>
      </div>
      <div><label>Employee Name</label><input id="employeeInput" name="employee_name" required placeholder="Employee full name"></div>
      <div><label>Issued Date</label><input type="date" name="issued_date" required value="<?=date('Y-m-d')?>"></div>
      <div><label>Expected Return</label><input type="date" name="expected_return"></div>
      <div class="full"><label>Notes / Purpose</label><textarea name="notes" placeholder="Purpose or condition details..."></textarea></div>
      <div class="full asset-image-upload-field">
        <label for="assetImageInput">Asset Image <span class="asset-optional-label">Optional · JPG, PNG or WebP · max 5 MB</span></label>
        <input type="file" id="assetImageInput" name="asset_image" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
        <small>Upload a photo for the selected item, or replace its existing photo. The picture is saved with the asset and shown in the inventory.</small>
        <div id="assetImagePreview" class="asset-image-preview" aria-live="polite"><span class="material-symbols-outlined">image</span><span>Select an asset or choose a photo to preview it.</span></div>
      </div>
    </div>
    <div class="record-actions"><button class="gw-btn primary" type="submit">Issue Equipment</button></div>
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
(function(){
  const select = document.getElementById('assetSelect');
  const fileInput = document.getElementById('assetImageInput');
  const preview = document.getElementById('assetImagePreview');
  if (!preview) return;
  let objectUrl = null;
  function renderPreview(src, isLocal) {
    preview.replaceChildren();
    if (src) {
      const img = document.createElement('img');
      img.src = src;
      img.alt = isLocal ? 'Selected asset photo preview' : 'Current asset photo';
      img.className = 'asset-image-preview-photo';
      img.onerror = function(){ preview.replaceChildren(); preview.innerHTML = '<span class="material-symbols-outlined">image_not_supported</span><span>Image preview is not available.</span>'; };
      preview.appendChild(img);
      const caption = document.createElement('span');
      caption.textContent = isLocal ? 'New photo selected' : 'Current saved photo';
      preview.appendChild(caption);
    } else {
      const icon = document.createElement('span');
      icon.className = 'material-symbols-outlined';
      icon.textContent = 'image';
      const text = document.createElement('span');
      text.textContent = 'Choose an asset or photo to preview it.';
      preview.append(icon, text);
    }
  }
  function updatePreview() {
    if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
    const selectedFile = fileInput && fileInput.files && fileInput.files[0];
    if (selectedFile) {
      if (!/^image\/(jpeg|png|webp)$/.test(selectedFile.type) || selectedFile.size > 5*1024*1024) {
        fileInput.setCustomValidity(!/^image\/(jpeg|png|webp)$/.test(selectedFile.type) ? 'Choose a JPG, PNG, or WebP image.' : 'Choose an image no larger than 5 MB.');
        fileInput.reportValidity();
        renderPreview('', false);
        return;
      }
      fileInput.setCustomValidity('');
      objectUrl = URL.createObjectURL(selectedFile);
      renderPreview(objectUrl, true);
      return;
    }
    if (fileInput) fileInput.setCustomValidity('');
    const option = select && select.options[select.selectedIndex];
    renderPreview(option && option.dataset.photoUrl ? option.dataset.photoUrl : '', false);
  }
  select && select.addEventListener('change', updatePreview);
  fileInput && fileInput.addEventListener('change', updatePreview);
  const issueForm = document.getElementById('assetIssueForm');
  issueForm && issueForm.addEventListener('submit', function(event){
    if(fileInput && fileInput.files && fileInput.files[0] && fileInput.validationMessage){ event.preventDefault(); fileInput.reportValidity(); }
  });
  updatePreview();
})();
function selectAssetToBorrow(assetId) {
  const sel = document.getElementById('assetSelect');
  if (sel) {
    sel.value = assetId;
    sel.dispatchEvent(new Event('change', {bubbles:true}));
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
