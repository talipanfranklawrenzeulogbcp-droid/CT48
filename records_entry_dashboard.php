<?php
require_once __DIR__.'/includes/helpers.php';
require_login();
require_records_entry_account();
page_header('Records Entry Workspace','entry-dashboard');
show_flash();
?>
<div class="gw-breadcrumb">
  <span class="material-symbols-outlined">home</span>
  <span>Great Solomon Manpower Services Inc.</span>
  <span>/</span>
  <strong>Records Entry Workspace</strong>
</div>

<section class="gw-hero dashboard-hero records-entry-hero">
  <div class="records-entry-hero-copy">
    <div class="eyebrow">CORE TRANSACTION 4 · RECORDS MANAGEMENT</div>
    <div class="dashboard-title-brand">
      <div class="brand-logo-white dashboard-logo-wrap">
        <img src="<?=e(url('/assets/logo2.svg'))?>" alt="Great Solomon Manpower Services Inc. logo" class="brand-logo-image">
      </div>
      <div>
        <h1>Records Entry Workspace</h1>
        <p>Welcome to the dedicated records workspace. Choose a task below to add employee health information, register equipment, or upload employee documents.</p>
      </div>
    </div>
    <div class="gw-hero-actions records-entry-hero-actions">
      <span class="gw-btn primary records-entry-account-chip">
        <span class="material-symbols-outlined">verified_user</span>
        Authorized Records Account
      </span>
      <span class="records-entry-email"><span class="material-symbols-outlined">account_circle</span> records.entry@greatsolomonmpservices.com</span>
    </div>
  </div>
  <div class="gw-hero-stat records-entry-hero-stat">
    <span class="material-symbols-outlined">dataset</span>
    <strong>03</strong>
    <span>Available entry tasks</span>
  </div>
</section>

<section class="records-entry-section">
  <div class="records-entry-section-heading">
    <div>
      <div class="eyebrow">QUICK ACCESS</div>
      <h2>What would you like to do?</h2>
      <p>Select a task to open its dedicated entry form.</p>
    </div>
    <span class="records-entry-section-count">3 TASKS</span>
  </div>

  <div class="records-entry-task-grid">
    <article class="gw-panel records-entry-task-card records-entry-health">
      <div class="records-entry-card-top">
        <div class="records-entry-task-icon"><span class="material-symbols-outlined">medical_information</span></div>
        <span class="records-entry-task-tag">HEALTH &amp; SAFETY</span>
      </div>
      <div class="records-entry-card-copy">
        <h3>Add Employee Health Record</h3>
        <p>Record employee health assessments and checkup details in the existing health records system.</p>
      </div>
      <div class="records-entry-card-meta"><span class="material-symbols-outlined">verified_user</span> Restricted health information</div>
      <a class="gw-btn primary records-entry-task-button" href="<?=e(url('/modules/health_safety/add_health_record.php'))?>">
        <span class="material-symbols-outlined">add_circle</span> Open health record form
        <span class="material-symbols-outlined records-entry-arrow">arrow_forward</span>
      </a>
    </article>

    <article class="gw-panel records-entry-task-card records-entry-assets">
      <div class="records-entry-card-top">
        <div class="records-entry-task-icon"><span class="material-symbols-outlined">inventory_2</span></div>
        <span class="records-entry-task-tag">ASSET INVENTORY</span>
      </div>
      <div class="records-entry-card-copy">
        <h3>Register Equipment / Asset</h3>
        <p>Add new equipment, asset tags, quantities, and locations to the existing inventory.</p>
      </div>
      <div class="records-entry-card-meta"><span class="material-symbols-outlined">database</span> Existing inventory retained</div>
      <a class="gw-btn primary records-entry-task-button" href="<?=e(url('/modules/asset_equipment/register_asset.php'))?>">
        <span class="material-symbols-outlined">add_circle</span> Open asset form
        <span class="material-symbols-outlined records-entry-arrow">arrow_forward</span>
      </a>
    </article>

    <article class="gw-panel records-entry-task-card records-entry-documents">
      <div class="records-entry-card-top">
        <div class="records-entry-task-icon"><span class="material-symbols-outlined">upload_file</span></div>
        <span class="records-entry-task-tag">EMPLOYEE DOCUMENTS</span>
      </div>
      <div class="records-entry-card-copy">
        <h3>Employee Document Upload</h3>
        <p>Enter employee document details and upload files through a separate document-entry interface.</p>
      </div>
      <div class="records-entry-card-meta"><span class="material-symbols-outlined">lock</span> Access-controlled documents</div>
      <a class="gw-btn primary records-entry-task-button" href="<?=e(url('/modules/legal_compliance/manage_employee_documents.php'))?>">
        <span class="material-symbols-outlined">upload</span> Open document upload
        <span class="material-symbols-outlined records-entry-arrow">arrow_forward</span>
      </a>
    </article>
  </div>
</section>

<section class="gw-panel records-entry-security-panel">
  <div class="records-entry-security-icon"><span class="material-symbols-outlined">shield_lock</span></div>
  <div>
    <h2>Secure records workspace</h2>
    <p>This workspace is reserved for the authorized Records Entry account. Existing records remain in the system database, and access to entry functions is checked by the server.</p>
  </div>
  <span class="records-entry-security-status"><span></span> Restricted access</span>
</section>
<?php page_footer(); ?>
