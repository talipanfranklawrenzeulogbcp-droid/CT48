<?php
require_once __DIR__.'/../../includes/helpers.php'; require_once __DIR__.'/../../includes/service_client.php'; require_records_entry_account(); $svc=service('assets');
if($_SERVER['REQUEST_METHOD']==='POST'){
  if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    flash('error','Your session expired or the security token is invalid. Refresh and try again.');
    redirect('/modules/asset_equipment/register_asset.php');
  }
  try{$message=$svc->handle((string)($_POST['action']??''),$_POST,current_user(),$_FILES);flash('success',$message);}
  catch(Throwable $e){error_log('Asset registration failed: '.$e->getMessage());$msg=$e->getMessage();$safe=(($e instanceof RuntimeException) && (str_contains($msg,'asset image') || str_contains($msg,'Asset image') || str_contains($msg,'Asset images') || str_contains($msg,'Unsupported image') || str_contains($msg,'uploaded image') || str_contains($msg,'asset photo') || str_contains($msg,'Asset photo') || str_contains($msg,'upload temporary') || str_contains($msg,'server could not write')));flash('error',$safe?$msg:'Unable to register this item. Please review the submitted details and try again.');}
  redirect('/modules/asset_equipment/register_asset.php');
}
page_header('Register Equipment / Asset','assets');show_flash(); ?>
<div class="asset-module-page">
<div class="gw-breadcrumb asset-breadcrumb"><a href="<?=e(url('/modules/asset_equipment/index.php'))?>">Asset &amp; Equipment Issuance</a><span aria-hidden="true">/</span><strong>Register Equipment / Asset</strong></div>
<section class="gw-hero asset-module-hero"><div><div class="eyebrow">ASSET INVENTORY</div><h1>Register Equipment / Asset</h1><p>Add an item to the existing inventory without leaving the inventory listing workflow.</p></div></section>
<section class="record-form asset-registration-form asset-section-spaced"><div class="gw-panel-head"><h2>Asset Details</h2><span>New inventory record</span></div><form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="add_asset">
    <div class="form-grid">
      <div><label>Asset Tag</label><input name="asset_tag" required placeholder="e.g. AST-0007"></div>
      <div><label>Equipment / Item Name</label><input name="name" required placeholder="e.g. Digital Projector, Hard Hat"></div>
      <div><label>Category</label><input name="category" placeholder="e.g. Computer, Tool, Presentation, Safety"></div>
      <div><label>Serial Number</label><input name="serial_number" placeholder="Optional serial number"></div>
      <div><label>Quantity</label><input type="number" name="quantity" min="1" value="1" required></div>
      <div><label>Location</label><input name="location" placeholder="e.g. Head Office, Warehouse"></div>
      <div class="full asset-image-upload-field">
        <label for="registeredAssetImage">Asset Image <span class="asset-optional-label">Optional · JPG, PNG or WebP · max 5 MB</span></label>
        <input type="file" id="registeredAssetImage" name="asset_image" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
        <small>The photo will be linked to this asset tag and will appear in Asset &amp; Equipment Issuance.</small>
      </div>
    </div>
    <div class="record-actions asset-registration-actions">
      <button class="gw-btn primary">Register Item</button>
    </div>
  </form><div class="record-actions asset-registration-actions"><a class="gw-btn secondary" href="<?=e(url('/modules/asset_equipment/index.php'))?>">Cancel</a></div></section>
</div>
<?php page_footer(); ?>