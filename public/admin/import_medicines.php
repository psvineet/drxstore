<?php
/**
 * DRXStore - Bulk Import Medicines via CSV
 * Developed by Vineet
 *
 * BUG FIX: Page was not registered in index.php route map — preview redirect
 * returned a 404 so the session preview data was never displayed. Route
 * 'import_med' => 'admin/import_medicines' added to index.php.
 *
 * Additional fixes:
 *  - BOM stripping for UTF-8 CSVs exported from Excel
 *  - CRLF normalization before fgetcsv
 *  - requireAdmin() moved before sample download so auth is always enforced
 *  - Error reporting improved
 */
if (!defined('ROOT')) define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT.'/config/app.php';
require_once ROOT.'/views/layout_admin.php';
requireAdmin();

// ── Sample CSV download ───────────────────────────────────────────────────────
if (isset($_GET['sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_medicines.csv"');
    echo "name,generic,company,category,hsn,gst,rack\r\n";
    echo "Paracetamol 500mg,Acetaminophen,Sun Pharma,Tablet,30049099,12,A-1\r\n";
    echo "Amoxicillin 250mg,Amoxicillin,Cipla,Capsule,30041099,12,B-3\r\n";
    echo "Omeprazole 20mg,Omeprazole,Mankind,Capsule,30049099,12,C-2\r\n";
    echo "Cetirizine 10mg,Cetirizine HCL,Abbott,Tablet,30049099,12,A-5\r\n";
    exit;
}

$preview = [];
$errors  = [];

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = post('action');

    // ── Preview ──────────────────────────────────────────────────────────────
    if ($act === 'preview' && isset($_FILES['csv_file'])) {
        $f = $_FILES['csv_file'];

        if ($f['error'] !== UPLOAD_ERR_OK) {
            $uploadMessages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing server temp folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
            ];
            $errors[] = 'Upload failed: ' . ($uploadMessages[$f['error']] ?? 'Error code ' . $f['error']);
        } elseif (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), ['csv', 'txt'])) {
            $errors[] = 'Only .csv or .txt files are accepted.';
        } elseif ($f['size'] > 2 * 1024 * 1024) {
            $errors[] = 'File too large (max 2 MB).';
        } else {
            // Read entire file and normalise line endings + strip UTF-8 BOM
            $raw = file_get_contents($f['tmp_name']);
            if ($raw === false) {
                $errors[] = 'Could not read uploaded file.';
            } else {
                // Strip UTF-8 BOM (Excel adds this)
                if (str_starts_with($raw, "\xEF\xBB\xBF")) {
                    $raw = substr($raw, 3);
                }
                // Normalise Windows CRLF and old Mac CR to Unix LF
                $raw = str_replace(["\r\n", "\r"], "\n", $raw);
                $raw = trim($raw);

                if ($raw === '') {
                    $errors[] = 'The uploaded file is empty.';
                } else {
                    // Write normalised content to a temp file so fgetcsv works reliably
                    $tmpNorm = tempnam(sys_get_temp_dir(), 'drxcsv_');
                    file_put_contents($tmpNorm, $raw);

                    $handle = fopen($tmpNorm, 'r');
                    $header = fgetcsv($handle);

                    if (!$header || count($header) < 1) {
                        $errors[] = 'Could not read CSV header row. Make sure the file uses comma-separated format.';
                        fclose($handle);
                        @unlink($tmpNorm);
                    } else {
                        $header = array_map(fn($h) => strtolower(trim($h ?? '')), $header);
                        $rowNum = 1;

                        while (($row = fgetcsv($handle)) !== false) {
                            $rowNum++;
                            if (count($row) < 1) continue;
                            // Skip completely blank rows
                            if (implode('', $row) === '') continue;

                            // Map columns by header name with fallbacks
                            $mapped = [];
                            foreach ($header as $i => $col) {
                                $mapped[$col] = trim($row[$i] ?? '');
                            }

                            $name = $mapped['name']
                                 ?? $mapped['medicine_name']
                                 ?? $mapped['medicine']
                                 ?? trim($row[0] ?? '');

                            if (trim($name) === '') continue;

                            // GST: default 12 if missing or non-numeric
                            $gstRaw = $mapped['gst'] ?? $mapped['gst_percent'] ?? $mapped['gst%'] ?? '';
                            $gst    = is_numeric($gstRaw) ? (float)$gstRaw : 12;
                            $gst    = max(0, min(28, $gst)); // clamp to valid GST slab range

                            $preview[] = [
                                'name'     => $name,
                                'generic'  => $mapped['generic']       ?? $mapped['generic_name']   ?? trim($row[1] ?? ''),
                                'company'  => $mapped['company']       ?? $mapped['manufacturer']   ?? trim($row[2] ?? ''),
                                'category' => $mapped['category']      ?? $mapped['dosage_form']    ?? trim($row[3] ?? ''),
                                'hsn'      => $mapped['hsn']           ?? $mapped['hsn_code']       ?? trim($row[4] ?? ''),
                                'gst'      => $gst,
                                'rack'     => $mapped['rack']          ?? $mapped['rack_location']  ?? trim($row[6] ?? ''),
                            ];
                        }

                        fclose($handle);
                        @unlink($tmpNorm);

                        if (empty($preview)) {
                            $errors[] = 'No valid medicine rows found in the CSV. Check that the file has a header row and at least one data row with a medicine name.';
                        } else {
                            $_SESSION['csv_prev'] = $preview;
                            setFlash('info', count($preview) . ' row(s) ready to preview.');
                            header('Location: index.php?p=import_med');
                            exit;
                        }
                    }
                }
            }
        }
    }

    // ── Confirm import ────────────────────────────────────────────────────────
    if ($act === 'confirm' && !empty($_SESSION['csv_prev'])) {
        $rows = $_SESSION['csv_prev'];
        $imp  = 0;
        $dup  = 0;
        $errs = [];

        foreach ($rows as $r) {
            try {
                if ($db->findOne('medicines', fn($m) => strtolower(trim($m['name'] ?? '')) === strtolower(trim($r['name'])))) {
                    $dup++;
                    continue;
                }
                $db->insert('medicines', [
                    'name'          => $r['name'],
                    'generic_name'  => $r['generic'],
                    'company'       => $r['company'],
                    'category'      => $r['category'],
                    'hsn_code'      => $r['hsn'],
                    'gst_percent'   => $r['gst'],
                    'rack_location' => $r['rack'],
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                $imp++;
            } catch (Exception $e) {
                $errs[] = 'Row "' . e($r['name']) . '": ' . $e->getMessage();
            }
        }

        unset($_SESSION['csv_prev']);

        if (!empty($errs)) {
            setFlash('warning', "Import finished with issues: {$imp} added, {$dup} skipped (duplicates). Errors: " . implode('; ', array_slice($errs, 0, 3)));
        } else {
            setFlash('success', "Import complete: {$imp} medicine(s) added, {$dup} skipped (already exist).");
        }
        header('Location: index.php?p=medicines');
        exit;
    }

    // ── Cancel ────────────────────────────────────────────────────────────────
    if ($act === 'cancel') {
        unset($_SESSION['csv_prev']);
        header('Location: index.php?p=medicines');
        exit;
    }
}

// ── On GET: load preview from session if present ──────────────────────────────
if (!empty($_SESSION['csv_prev']) && empty($preview)) {
    $preview = $_SESSION['csv_prev'];
}

adminHeader('Import Medicines', 'import_med');
?>
<div class="page-hdr">
  <div>
    <div class="page-title">&#128229; Import Medicines</div>
    <div class="page-sub">Bulk-add medicines from a CSV file</div>
  </div>
  <a href="index.php?p=medicines" class="btn btn-ghost">&#8592; Back to Medicines</a>
</div>

<?php foreach ($errors as $er): ?>
<div class="alert alert-danger"><span class="alert-body"><?=e($er)?></span></div>
<?php endforeach; ?>

<?php if (empty($preview)): ?>
<!-- ── Step 1: Upload ─────────────────────────────────────────────────── -->
<div class="dash-grid">
  <!-- Upload form -->
  <div class="card">
    <div class="card-hdr"><div class="card-title">Upload CSV File</div></div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <?=csrfField()?>
        <input type="hidden" name="action" value="preview">
        <div class="form-group">
          <label class="form-label">Select CSV File <span class="req">*</span></label>
          <input class="form-control" type="file" name="csv_file" accept=".csv,.txt" required id="csvInput">
          <div class="form-hint">Max 2 MB. UTF-8 or ANSI encoding. Excel exports with BOM are supported.</div>
        </div>
        <div id="fileInfo" style="display:none;background:var(--g1);border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:.82rem"></div>
        <button type="submit" class="btn btn-primary" id="previewBtn">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          Preview Import
        </button>
      </form>
    </div>
  </div>

  <!-- Format guide -->
  <div class="card">
    <div class="card-hdr"><div class="card-title">CSV Format Guide</div></div>
    <div class="card-body">
      <div style="background:var(--g1);padding:12px 14px;border-radius:var(--rl);font-family:monospace;font-size:.76rem;line-height:1.85;overflow-x:auto;white-space:pre">name,generic,company,category,hsn,gst,rack
Paracetamol 500mg,Acetaminophen,Sun Pharma,Tablet,30049099,12,A-1
Amoxicillin 250mg,Amoxicillin,Cipla,Capsule,30041099,12,B-3</div>
      <div style="margin-top:12px;font-size:.78rem;line-height:1.7">
        <strong>Accepted column names (case-insensitive):</strong><br>
        <code>name</code> / <code>medicine_name</code> / <code>medicine</code> — <em>required</em><br>
        <code>generic</code> / <code>generic_name</code><br>
        <code>company</code> / <code>manufacturer</code><br>
        <code>category</code> / <code>dosage_form</code><br>
        <code>hsn</code> / <code>hsn_code</code><br>
        <code>gst</code> / <code>gst_percent</code> / <code>gst%</code> (default: 12)<br>
        <code>rack</code> / <code>rack_location</code>
      </div>
      <a href="index.php?p=import_med&sample=1" class="btn btn-ghost btn-sm" style="margin-top:14px">
        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Download Sample CSV
      </a>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── Step 2: Preview ────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-hdr">
    <div>
      <div class="card-title">Preview — <?=count($preview)?> medicine(s) ready to import</div>
      <div style="font-size:.76rem;color:var(--g5)">Exact duplicates (by name) will be skipped automatically.</div>
    </div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="btn btn-ghost btn-sm">Cancel</button>
    </form>
  </div>

  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Generic</th>
          <th>Company</th>
          <th>Form / Category</th>
          <th>HSN</th>
          <th>GST%</th>
          <th>Rack</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (array_slice($preview, 0, 100) as $i => $r): ?>
      <tr>
        <td class="text-muted text-sm"><?=$i+1?></td>
        <td class="fw-600"><?=e($r['name'])?></td>
        <td class="text-sm"><?=e($r['generic'] ?: '—')?></td>
        <td class="text-sm"><?=e($r['company'] ?: '—')?></td>
        <td><?= $r['category'] ? '<span class="chip chip-teal">'.e($r['category']).'</span>' : '<span class="text-muted">—</span>'?></td>
        <td class="text-sm text-muted"><?=e($r['hsn'] ?: '—')?></td>
        <td class="text-sm"><?=e($r['gst'])?>%</td>
        <td class="text-sm"><?=e($r['rack'] ?: '—')?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (count($preview) > 100): ?>
      <tr><td colspan="8" class="tc text-sm text-muted" style="padding:10px">
        … and <?=count($preview)-100?> more rows (all will be imported)
      </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="padding:14px 16px;border-top:1px solid var(--g3);display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="confirm">
      <button type="submit" class="btn btn-success" onclick="return confirm('Import <?=count($preview)?> medicine(s) into the database?')">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><polyline points="20 6 9 17 4 12"/></svg>
        Confirm Import (<?=count($preview)?> medicines)
      </button>
    </form>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="btn btn-ghost">Discard &amp; Start Over</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  var inp = document.getElementById('csvInput');
  var btn = document.getElementById('previewBtn');
  var fi  = document.getElementById('fileInfo');
  if (inp) {
    inp.addEventListener('change', function(){
      if (this.files[0]) {
        var mb = (this.files[0].size / 1024 / 1024).toFixed(2);
        fi.style.display = 'block';
        fi.innerHTML = '<strong>' + this.files[0].name + '</strong> &mdash; ' + mb + ' MB';
        btn.textContent = 'Preview Import';
      }
    });
  }
  var form = document.getElementById('uploadForm');
  if (form) {
    form.addEventListener('submit', function(){
      if (btn) { btn.disabled = true; btn.textContent = 'Uploading…'; }
    });
  }
})();
</script>
<?php adminFooter(); ?>
