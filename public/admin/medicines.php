<?php
/**
 * DRXStore - Medicines with inline Import (CSV/XLSX) + Other category
 * Developed by Vineet
 */
if (!defined('ROOT')) define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT.'/config/app.php';
require_once ROOT.'/views/layout_admin.php';
requireStaff();

$cats = array_column($db->table('categories'),'name'); sort($cats);
$errors = []; $importResult = null; $importPreview = [];

// ── ADD / EDIT medicine ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $act = post('action'); $id = postInt('id');

    if (in_array($act,['add','edit'])) {
        $name    = post('name');
        $generic = post('generic');
        $company = post('company');
        $cat     = post('category');
        $catOther= post('category_other'); // manual entry when "Other" selected
        $hsn     = post('hsn');
        $gst     = postFloat('gst_pct', 12);
        $desc    = post('description');
        $rack    = post('rack');

        // If "Other" chosen and manual entered, use it and save to categories
        if ($cat === 'Other' && $catOther !== '') {
            $catFinal = trim($catOther);
            // Add to categories list if not exists
            if (!$db->findOne('categories', fn($c) => strtolower($c['name']) === strtolower($catFinal))) {
                $db->insert('categories', ['name' => $catFinal, 'type' => 'custom']);
                $cats[] = $catFinal; sort($cats);
            }
            $cat = $catFinal;
        }

        if (!$name)    $errors[] = 'Medicine name is required.';
        if (!$company) $errors[] = 'Company/Manufacturer is required.';
        if (empty($errors)) {
            $data = ['name'=>$name,'generic_name'=>$generic,'company'=>$company,'category'=>$cat,'hsn_code'=>$hsn,'gst_percent'=>$gst,'description'=>$desc,'rack_location'=>$rack,'updated_at'=>date('Y-m-d H:i:s')];
            if ($act==='edit'&&$id) { $db->update('medicines',fn($m)=>$m['id']===$id,$data); setFlash('success','"'.$name.'" updated.'); }
            else { $data['created_at']=date('Y-m-d H:i:s'); $db->insert('medicines',$data); setFlash('success','"'.$name.'" added.'); }
            header('Location: index.php?p=medicines'); exit;
        }
    }

    // ── IMPORT ────────────────────────────────────────────────────
    if ($act === 'import_preview' && isset($_FILES['import_file'])) {
        $f = $_FILES['import_file'];
        $uploadMessages=[UPLOAD_ERR_INI_SIZE=>'File exceeds server upload limit.',UPLOAD_ERR_FORM_SIZE=>'File exceeds form size limit.',UPLOAD_ERR_PARTIAL=>'File only partially uploaded.',UPLOAD_ERR_NO_FILE=>'No file was uploaded.',UPLOAD_ERR_NO_TMP_DIR=>'Missing temp folder on server.',UPLOAD_ERR_CANT_WRITE=>'Failed to write file to disk.',UPLOAD_ERR_EXTENSION=>'A PHP extension blocked the upload.'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed: ' . ($uploadMessages[$f['error']] ?? 'Error code '.$f['error'].'.');
        } elseif ($f['size'] > 5 * 1024 * 1024) {
            $errors[] = 'File too large (max 5 MB).';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            try {
                if ($ext === 'csv' || $ext === 'txt') {
                    $importPreview = parseCsvMedicines($f['tmp_name']);
                } elseif ($ext === 'xlsx' || $ext === 'xls') {
                    if (!class_exists('ZipArchive')) {
                        $errors[] = 'XLSX import requires the PHP zip extension (not installed on this server). Please upload a CSV file instead.';
                    } else {
                        $importPreview = parseXlsxMedicines($f['tmp_name']);
                    }
                } else {
                    $errors[] = 'Invalid file format. Only .csv or .xlsx files are accepted.';
                }
            } catch (Throwable $ex) {
                $errors[] = 'Could not parse file: ' . $ex->getMessage();
            }
            if (empty($importPreview) && empty($errors)) {
                $errors[] = 'No valid medicine rows found. Ensure the file has a header row with at least a "name" column and one data row.';
            }
            if (!empty($importPreview)) {
                // Collect any GST warnings from rows, strip _gst_warn before storing
                $gstWarns=[];
                foreach($importPreview as &$pr){ if(isset($pr['_gst_warn'])){ $gstWarns[]=$pr['_gst_warn']; unset($pr['_gst_warn']); } }
                unset($pr);
                startSession();
                $_SESSION['med_import'] = $importPreview;
                if(!empty($gstWarns)) $_SESSION['med_import_warns']=$gstWarns;
            }
        }
    }

    if ($act === 'import_confirm') {
        startSession();
        if (!empty($_SESSION['med_import'])) {
            $rows = $_SESSION['med_import']; $imp = 0; $dup = 0; $errs = [];
            foreach ($rows as $r) {
                try {
                    if ($db->findOne('medicines',fn($m)=>strtolower(trim($m['name']??''))===strtolower(trim($r['name'])))) { $dup++; continue; }
                    // Handle custom category
                    if (!empty($r['category']) && !in_array($r['category'], $cats)) {
                        if (!$db->findOne('categories',fn($c)=>strtolower($c['name'])===strtolower($r['category']))) {
                            $db->insert('categories',['name'=>$r['category'],'type'=>'custom']);
                            $cats[] = $r['category'];
                        }
                    }
                    $db->insert('medicines',['name'=>trim($r['name']),'generic_name'=>trim($r['generic']),'company'=>trim($r['company']),'category'=>trim($r['category']),'hsn_code'=>trim($r['hsn']),'gst_percent'=>(float)($r['gst']??12),'rack_location'=>trim($r['rack']),'created_at'=>date('Y-m-d H:i:s')]);
                    $imp++;
                } catch (Throwable $ex) {
                    $errs[] = 'Row "'.e($r['name']??'?').'": '.$ex->getMessage();
                }
            }
            unset($_SESSION['med_import'], $_SESSION['med_import_warns']);
            if (!empty($errs)) {
                setFlash('warning',"Import finished with issues: {$imp} added, {$dup} skipped. Errors: ".implode('; ', array_slice($errs,0,3)));
            } else {
                setFlash('success',"Import complete: {$imp} medicine(s) added, {$dup} duplicate(s) skipped.");
            }
            header('Location: index.php?p=medicines'); exit;
        }
    }
    if ($act === 'import_cancel') { startSession(); unset($_SESSION['med_import'],$_SESSION['med_import_warns']); header('Location: index.php?p=medicines'); exit; }

}

// Sample CSV download (GET, no CSRF needed — read-only)
if (get('action')==='sample_csv_dl') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="medicines_sample.csv"');
    echo "name,generic,company,category,hsn,gst,rack\r\n";
    $sampleRows=[['Paracetamol 500mg','Acetaminophen','Sun Pharma','Tablet','30049099','12','A-1'],['Amoxicillin 250mg','Amoxicillin','Cipla','Capsule','30041099','12','B-3'],['Omeprazole 20mg','Omeprazole','Mankind','Capsule','30049099','12','C-2']];
    foreach($sampleRows as $sr) echo implode(',', $sr)."\r\n";
    exit;
}

if (get('action')==='delete'&&getInt('id')) {
    verifyCsrfGet();
    $mid=getInt('id');
    if($db->count('batches',fn($b)=>($b['medicine_id']??0)===$mid)){setFlash('danger','Cannot delete — has batches.');}
    else{$db->delete('medicines',fn($m)=>$m['id']===$mid);setFlash('success','Deleted.');}
    header('Location: index.php?p=medicines');exit;
}

$q=get('q',''); $fcat=get('fcat','');
$page = max(1, getInt('page', 1));

if ($db instanceof MySQLDB) {
    $pdo = $db->pdo();
    $where = ["1=1"]; $vals = [];
    if ($q) { $ql='%'.$q.'%'; $where[]="(name LIKE ? OR company LIKE ? OR generic_name LIKE ?)"; array_push($vals,$ql,$ql,$ql); }
    if ($fcat) { $where[]="category=?"; $vals[]=$fcat; }
    $whereSQL = implode(' AND ', $where);
    $cntSt = $pdo->prepare("SELECT COUNT(*) FROM medicines WHERE {$whereSQL}"); $cntSt->execute($vals);
    $total = (int)$cntSt->fetchColumn();
    $offset = ($page-1)*PER_PAGE;
    $st = $pdo->prepare("SELECT * FROM medicines WHERE {$whereSQL} ORDER BY name ASC LIMIT ".PER_PAGE." OFFSET {$offset}");
    $st->execute($vals);
    $meds = $st->fetchAll();
    $pag = ['items'=>$meds,'total'=>$total,'pages'=>(int)ceil($total/PER_PAGE),'page'=>$page,'per_page'=>PER_PAGE];
} else {
    $meds=$db->table('medicines'); usort($meds,fn($a,$b)=>strcasecmp($a['name'],$b['name']));
    if($q){$ql=strtolower($q);$meds=array_values(array_filter($meds,fn($m)=>(strpos(strtolower($m['name']??''),$ql)!==false)||(strpos(strtolower($m['company']??''),$ql)!==false)));}
    if($fcat){$meds=array_values(array_filter($meds,fn($m)=>($m['category']??'')===$fcat));}
    $pag=paginate($meds,$page,PER_PAGE);
}

$edit=null; if(get('action')==='edit'&&getInt('id'))$edit=$db->findOne('medicines',fn($m)=>$m['id']===getInt('id'));
startSession();
if(!empty($_SESSION['med_import'])&&empty($importPreview))$importPreview=$_SESSION['med_import'];

// ── Helper: parse CSV ─────────────────────────────────────────────
function parseCsvMedicines(string $path): array {
    $rows=[]; $importErrors=[];
    // Read entire file, strip UTF-8 BOM (Excel adds \xEF\xBB\xBF), normalise line endings
    $raw = file_get_contents($path);
    if ($raw === false) return [];
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
    if ($raw === '') return [];
    $tmp = tempnam(sys_get_temp_dir(), 'drxmed_');
    file_put_contents($tmp, $raw);
    $handle = fopen($tmp, 'r');
    if (!$handle) { @unlink($tmp); return []; }
    $header=fgetcsv($handle); if(!$header){ fclose($handle); @unlink($tmp); return []; }
    $header=array_map(fn($h)=>strtolower(trim($h??'')),$header);
    // Validate required header column exists
    $hasName = in_array('name',$header)||in_array('medicine_name',$header)||in_array('medicine',$header);
    if(!$hasName){ fclose($handle); @unlink($tmp); return []; }
    $rowNum=1;
    while(($row=fgetcsv($handle))!==false){
        $rowNum++;
        if(implode('',$row)==='')continue; // skip blank rows
        $m=[]; foreach($header as $i=>$col)$m[$col]=trim($row[$i]??'');
        $name=$m['name']??$m['medicine_name']??$m['medicine']??'';
        if(trim($name)==='')continue;
        // GST validation: must be numeric 0-28 or default 12
        $gstRaw=$m['gst']??$m['gst_percent']??$m['gst%']??'';
        if($gstRaw!==''&&!is_numeric($gstRaw)){
            // reject row with bad GST format — use error marker so caller can report
            $rows[]=['name'=>trim($name),'generic'=>trim($m['generic']??$m['generic_name']??''),'company'=>trim($m['company']??$m['manufacturer']??''),'category'=>trim($m['category']??$m['dosage_form']??''),'hsn'=>trim($m['hsn']??$m['hsn_code']??''),'gst'=>12,'rack'=>trim($m['rack']??$m['rack_location']??''),'_gst_warn'=>"Row {$rowNum}: GST '".htmlspecialchars($gstRaw,ENT_QUOTES)."' not numeric, defaulted to 12%"];
            continue;
        }
        $gst=$gstRaw===''?12:(float)$gstRaw;
        $gst=max(0,min(28,$gst)); // clamp to valid GST slab range (0–28%)
        $rows[]=['name'=>trim($name),'generic'=>trim($m['generic']??$m['generic_name']??''),'company'=>trim($m['company']??$m['manufacturer']??''),'category'=>trim($m['category']??$m['dosage_form']??''),'hsn'=>trim($m['hsn']??$m['hsn_code']??''),'gst'=>$gst,'rack'=>trim($m['rack']??$m['rack_location']??'')];
    }
    fclose($handle); @unlink($tmp); return $rows;
}

// ── Helper: parse XLSX (basic, no external library) ───────────────
function parseXlsxMedicines(string $path): array {
    // XLSX is a ZIP file containing XML
    $zip=new ZipArchive(); if($zip->open($path)!==true)return[];
    $sharedXml=$zip->getFromName('xl/sharedStrings.xml');
    $sheetXml=$zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if(!$sheetXml)return[];
    // Parse shared strings
    $strings=[];
    if($sharedXml){
        preg_match_all('/<si>.*?<t[^>]*>([^<]*)<\/t>.*?<\/si>/s',$sharedXml,$m);
        $strings=$m[1]??[];
    }
    // Parse sheet rows
    $rows=[]; $header=[];
    preg_match_all('/<row[^>]*>(.*?)<\/row>/s',$sheetXml,$rowMatches);
    foreach($rowMatches[1] as $ri=>$rowXml){
        preg_match_all('/<c[^>]*r="([A-Z]+)\d+"[^>]*(?:t="([^"]*)")?[^>]*>.*?<v>(\d+)<\/v>.*?<\/c>|<c[^>]*r="([A-Z]+)\d+"[^>]*>.*?<v>([^<]*)<\/v>.*?<\/c>/s',$rowXml,$cells,PREG_SET_ORDER);
        $rowData=[];
        foreach($cells as $cell){
            $col=$cell[1]?:$cell[4]; $type=$cell[2]??''; $val=$cell[3]?:$cell[5];
            if($type==='s'&&isset($strings[(int)$val]))$val=html_entity_decode($strings[(int)$val]);
            $rowData[$col]=$val;
        }
        if(empty($rowData))continue;
        // Convert column letters to array
        ksort($rowData); $vals=array_values($rowData);
        if($ri===0){$header=array_map('strtolower',$vals);continue;}
        if(empty($vals[0]))continue;
        $map=[]; foreach($header as $i=>$h)$map[$h]=$vals[$i]??'';
        $name=$map['name']??$map['medicine_name']??$vals[0]??'';
        if(!trim($name))continue;
        $rows[]=['name'=>trim($name),'generic'=>trim($map['generic']??$map['generic_name']??''),'company'=>trim($map['company']??$map['manufacturer']??''),'category'=>trim($map['category']??''),'hsn'=>trim($map['hsn']??$map['hsn_code']??''),'gst'=>is_numeric($g=$map['gst']??$map['gst_percent']??'12')?(float)$g:12,'rack'=>trim($map['rack']??$map['rack_location']??'')];
    }
    return $rows;
}

// ── EXPORT CSV ────────────────────────────────────────────────────
if (get('action') === 'export_csv') {
    $allMeds = $db->table('medicines');
    usort($allMeds, fn($a,$b) => strcasecmp($a['name'], $b['name']));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="medicines_export_'.date('Ymd').'.csv"');
    echo "ï»¿"; // UTF-8 BOM for Excel
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['name','generic','company','category','hsn','gst','rack','description']);
    foreach($allMeds as $m) {
        fputcsv($fp, [
            $m['name'] ?? '',
            $m['generic_name'] ?? '',
            $m['company'] ?? '',
            $m['category'] ?? '',
            $m['hsn_code'] ?? '',
            $m['gst_percent'] ?? 12,
            $m['rack_location'] ?? '',
            $m['description'] ?? '',
        ]);
    }
    fclose($fp);
    exit;
}

// ── EXPORT XLSX ───────────────────────────────────────────────────
if (get('action') === 'export_xlsx') {
    $allMeds = $db->table('medicines');
    usort($allMeds, fn($a,$b) => strcasecmp($a['name'], $b['name']));
    $rows = [['Name','Generic Name','Company','Category','HSN Code','GST %','Rack Location','Description']];
    foreach($allMeds as $m) {
        $rows[] = [
            $m['name'] ?? '',
            $m['generic_name'] ?? '',
            $m['company'] ?? '',
            $m['category'] ?? '',
            $m['hsn_code'] ?? '',
            $m['gst_percent'] ?? 12,
            $m['rack_location'] ?? '',
            $m['description'] ?? '',
        ];
    }
    // Build minimal XLSX using ZipArchive
    $tmpXlsx = tempnam(sys_get_temp_dir(), 'drx') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($tmpXlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    // [Content_Types].xml
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    // _rels/.rels
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    // xl/_rels/workbook.xml.rels
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    // xl/workbook.xml
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Medicines" sheetId="1" r:id="rId1"/></sheets></workbook>');
    // Build shared strings & sheet data
    $strings = []; $strIdx = [];
    $getIdx = function($val) use (&$strings, &$strIdx) {
        $val = (string)$val;
        if (!isset($strIdx[$val])) { $strIdx[$val] = count($strings); $strings[] = $val; }
        return $strIdx[$val];
    };
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $cols = ['A','B','C','D','E','F','G','H'];
    foreach($rows as $ri => $row) {
        $sheetXml .= '<row r="'.($ri+1).'">';
        foreach($row as $ci => $cell) {
            $col = $cols[$ci] ?? chr(65+$ci);
            $ref = $col.($ri+1);
            if(is_numeric($cell) && !str_starts_with((string)$cell, '0')) {
                $sheetXml .= '<c r="'.$ref.'" t="n"><v>'.htmlspecialchars((string)$cell,ENT_XML1).'</v></c>';
            } else {
                $idx = $getIdx($cell);
                $sheetXml .= '<c r="'.$ref.'" t="s"><v>'.$idx.'</v></c>';
            }
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    // sharedStrings.xml
    $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
    foreach($strings as $s) $ssXml .= '<si><t>'.htmlspecialchars($s,ENT_XML1).'</t></si>';
    $ssXml .= '</sst>';
    $zip->addFromString('xl/sharedStrings.xml', $ssXml);
    // styles.xml (minimal)
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>');
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="medicines_export_'.date('Ymd').'.xlsx"');
    header('Content-Length: '.filesize($tmpXlsx));
    readfile($tmpXlsx);
    unlink($tmpXlsx);
    exit;
}


adminHeader('Medicines','medicines');
// Reload categories (may have just added a custom one)
$cats=array_column($db->table('categories'),'name'); sort($cats);
?>
<div class="page-hdr">
  <div><div class="page-title">Medicines</div><div class="page-sub"><?=$db->count('medicines')?> registered</div></div>
  <div class="page-actions">
    <div style="position:relative;display:inline-block" id="exportWrap">
      <button class="btn btn-ghost" onclick="toggleExportMenu()" type="button">
        <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle;margin-right:4px"><path d="M3 17h14M10 3v10M6 9l4 4 4-4"/></svg>Export
        <svg viewBox="0 0 12 8" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-left:2px"><polyline points="1 1 6 7 11 1"/></svg>
      </button>
      <div id="exportMenu" style="display:none;position:absolute;right:0;top:calc(100% + 4px);background:#fff;border:1.5px solid var(--navy);border-radius:var(--rl);box-shadow:0 6px 20px rgba(10,35,66,.13);z-index:9999;min-width:160px;overflow:hidden">
        <a href="index.php?p=medicines&action=export_csv" style="display:flex;align-items:center;gap:8px;padding:10px 14px;font-size:.82rem;color:var(--g8);text-decoration:none;border-bottom:1px solid var(--g2)" onmouseover="this.style.background='var(--g1)'" onmouseout="this.style.background=''">
          <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 17h14M10 3v10M6 9l4 4 4-4"/></svg>Export as CSV
        </a>
        <a href="index.php?p=medicines&action=export_xlsx" style="display:flex;align-items:center;gap:8px;padding:10px 14px;font-size:.82rem;color:var(--g8);text-decoration:none" onmouseover="this.style.background='var(--g1)'" onmouseout="this.style.background=''">
          <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 17h14M10 3v10M6 9l4 4 4-4"/></svg>Export as Excel
        </a>
      </div>
    </div>
    <button class="btn btn-ghost" onclick="toggleImport()">
      <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle;margin-right:4px"><path d="M3 3h14M10 17V7M6 11l4-4 4 4"/></svg>Import
    </button>
    <button class="btn btn-primary" onclick="openModal('mModal')">
      <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" style="vertical-align:middle;margin-right:4px"><line x1="10" y1="4" x2="10" y2="16"/><line x1="4" y1="10" x2="16" y2="10"/></svg>Add Medicine
    </button>
  </div>
</div>

<!-- ── Inline Import Panel ── -->
<div id="importPanel" style="display:<?=!empty($importPreview)?'block':'none'?>;margin-bottom:16px">
<div class="card">
  <div class="card-hdr"><div class="card-title">Import Medicines from CSV or XLSX</div><button type="button" class="btn btn-ghost btn-sm" onclick="toggleImport()">Close</button></div>
  <div class="card-body">
    <?php
    // Show GST warnings if any
    if(!empty($_SESSION['med_import_warns'])):
        foreach($_SESSION['med_import_warns'] as $w): ?>
    <div class="alert alert-warning" style="margin-bottom:8px"><span class="alert-body">&#9888; <?=e($w)?></span></div>
    <?php endforeach;
    unset($_SESSION['med_import_warns']);
    endif; ?>
    <?php if(empty($importPreview)):?>
    <form method="POST" enctype="multipart/form-data">
      <?=csrfField()?><input type="hidden" name="action" value="import_preview">
      <div style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <div class="form-group" style="margin:0"><label class="form-label">Select File (CSV or XLSX) <span class="req">*</span></label><input class="form-control" type="file" name="import_file" accept=".csv,.xlsx,.xls,.txt" required></div>
        <button type="submit" class="btn btn-primary">&#128269; Preview</button>
      </div>
      <div class="form-hint" style="margin-top:8px">
        Columns: <strong>name</strong>, generic, company, category, hsn, gst (%), rack. First row must be headers.
        &nbsp;&mdash;&nbsp;<a href="index.php?p=medicines&action=sample_csv_dl" style="font-size:.8rem">&#8595; Download Sample CSV</a>
      </div>
    </form>
    <?php else:?>
    <div style="margin-bottom:12px"><strong><?=count($importPreview)?></strong> medicine(s) ready to import. Exact duplicates (by name) will be skipped.</div>
    <div class="table-wrap" style="max-height:260px;overflow-y:auto"><table class="tbl">
      <thead><tr><th>#</th><th>Name</th><th>Generic</th><th>Company</th><th>Form</th><th>GST%</th><th>Rack</th></tr></thead>
      <tbody>
      <?php foreach(array_slice($importPreview,0,50) as $ri=>$r):?>
      <tr>
        <td class="text-muted text-sm"><?=$ri+1?></td>
        <td class="fw-600"><?=e($r['name'])?></td>
        <td class="text-sm"><?=e($r['generic']?:'&mdash;')?></td>
        <td class="text-sm"><?=e($r['company']?:'&mdash;')?></td>
        <td><?=e($r['category']?:'&mdash;')?></td>
        <td class="text-sm"><?=e($r['gst'])?>%</td>
        <td class="text-sm"><?=e($r['rack']?:'&mdash;')?></td>
      </tr>
      <?php endforeach; if(count($importPreview)>50):?><tr><td colspan="7" class="tc text-muted text-sm">...and <?=count($importPreview)-50?> more rows (all will be imported)</td></tr><?php endif;?>
      </tbody>
    </table></div>
    <div class="flex gap-2" style="margin-top:14px;padding-top:12px;border-top:1px solid var(--g3)">
      <form method="POST">
        <?=csrfField()?>
        <input type="hidden" name="action" value="import_confirm">
        <button type="submit" class="btn btn-success" onclick="return confirm('Import <?=count($importPreview)?> medicine(s) into the database?')">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><polyline points="20 6 9 17 4 12"/></svg>
          Confirm &amp; Import (<?=count($importPreview)?> medicines)
        </button>
      </form>
      <form method="POST">
        <?=csrfField()?>
        <input type="hidden" name="action" value="import_cancel">
        <button type="submit" class="btn btn-ghost">Discard &amp; Start Over</button>
      </form>
    </div>
    <?php endif;?>
  </div>
</div>
</div>

<!-- Search / Filter -->
<div class="card mb-2"><div class="card-body" style="padding:10px 16px">
  <form method="GET" class="flex gap-2 flex-wrap items-center">
    <input type="hidden" name="p" value="medicines">
    <div class="search-bar"><input type="text" name="q" id="lsearch" value="<?=e($q)?>" placeholder="Search name or company..."></div>
    <select class="form-control" name="fcat" style="width:auto">
      <option value="">All Categories</option>
      <?php foreach($cats as $c):?><option value="<?=e($c)?>" <?=$fcat===$c?'selected':''?>><?=e($c)?></option><?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if($q||$fcat):?><a href="index.php?p=medicines" class="btn btn-ghost btn-sm">Clear</a><?php endif;?>
  </form>
</div></div>

<!-- Medicine list -->
<div class="card">
  <div class="card-hdr"><div class="card-title">Medicine List</div><span class="text-sm text-muted"><?=$pag['total']?> result(s)</span></div>
  <div class="card-body p0">
    <?php if(empty($pag['items'])):?>
    <div class="empty-state"><p>No medicines found. Add one to get started.</p></div>
    <?php else:?>
    <div class="table-wrap"><table class="tbl" id="lsearchTbl">
      <thead><tr><th>Name</th><th>Generic</th><th>Company</th><th>Form</th><th>GST%</th><th>Rack</th><th>Stock</th><th></th></tr></thead>
      <tbody>
      <?php foreach($pag['items'] as $m):
        $stock=(int)$db->sum('batches','quantity',fn($b)=>($b['medicine_id']??0)===$m['id']);
      ?>
      <tr>
        <td><div class="fw-600"><?=e($m['name'])?></div><?php if(!empty($m['description'])):?><div class="text-xs text-muted truncate" style="max-width:180px"><?=e($m['description'])?></div><?php endif;?></td>
        <td class="text-sm"><?=e($m['generic_name']??'&mdash;')?></td>
        <td class="text-sm"><?=e($m['company']??'&mdash;')?></td>
        <td><?php if(!empty($m['category'])):?><span class="chip chip-blue"><?=e($m['category'])?></span><?php else:?>&mdash;<?php endif;?></td>
        <td class="text-sm"><?=e($m['gst_percent']??0)?>%</td>
        <td class="text-sm text-muted"><?=e($m['rack_location']??'&mdash;')?></td>
        <td><?=stockChip($stock)?></td>
        <td><div class="flex gap-1">
          <a href="index.php?p=medicines&action=edit&id=<?=$m['id']?>" class="btn btn-ghost btn-sm">Edit</a>
          <a href="<?=csrfUrl('index.php?p=medicines&action=delete&id='.$m['id'])?>" class="btn btn-danger btn-sm" data-confirm="Delete &quot;<?=e($m['name'])?>?">Delete</a>
        </div></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table></div>
    <?=pagerHtml($pag,'index.php?p=medicines&q='.urlencode($q).'&fcat='.urlencode($fcat))?>
    <?php endif;?>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal-overlay <?=($edit||!empty($errors))?'open':''?>" id="mModal">
  <div class="modal modal-lg"><div class="modal-hdr"><span class="modal-title"><?=$edit?'Edit':'Add'?> Medicine</span><button class="modal-x" onclick="closeModal('mModal')">&#x2715;</button></div>
    <form method="POST"><div class="modal-body">
      <?=csrfField()?><input type="hidden" name="action" value="<?=$edit?'edit':'add'?>">
      <?php if($edit):?><input type="hidden" name="id" value="<?=$edit['id']?>"><?php endif;?>
      <?php foreach($errors as $er):?><div class="alert alert-danger"><span class="alert-body"><?=e($er)?></span></div><?php endforeach;?>
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Medicine Name <span class="req">*</span></label><input class="form-control" type="text" name="name" value="<?=e($edit['name']??post('name'))?>" required autofocus></div>
        <div class="form-group"><label class="form-label">Generic Name</label><input class="form-control" type="text" name="generic" value="<?=e($edit['generic_name']??post('generic'))?>"></div>
      </div>
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Manufacturer <span class="req">*</span></label><input class="form-control" type="text" name="company" value="<?=e($edit['company']??post('company'))?>" required></div>
        <div class="form-group"><label class="form-label">Dosage Form / Category</label>
          <select class="form-control" name="category" id="catSelect" onchange="toggleOtherCat()">
            <option value="">None</option>
            <?php foreach($cats as $c):?><option value="<?=e($c)?>" <?=($edit['category']??post('category'))===$c?'selected':''?>><?=e($c)?></option><?php endforeach;?>
            <option value="Other">--- Other (Custom) ---</option>
          </select>
          <div id="otherCatWrap" style="display:none;margin-top:6px">
            <input class="form-control" type="text" name="category_other" id="catOther" placeholder="Enter custom category name..." value="<?=e(post('category_other'))?>">
            <div class="form-hint">This category will be added to the list permanently.</div>
          </div>
        </div>
      </div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label">HSN Code</label><input class="form-control" type="text" name="hsn" value="<?=e($edit['hsn_code']??post('hsn'))?>" placeholder="30049099"></div>
        <div class="form-group"><label class="form-label">GST %</label>
          <select class="form-control" name="gst_pct">
            <?php foreach([0,5,12,18,28] as $g):?><option value="<?=$g?>" <?=(float)($edit['gst_percent']??post('gst_pct','12'))==(float)$g?'selected':''?>><?=$g?>%</option><?php endforeach;?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Rack / Location</label><input class="form-control" type="text" name="rack" value="<?=e($edit['rack_location']??post('rack'))?>" placeholder="A-12"></div>
      </div>
      <div class="form-group"><label class="form-label">Description / Notes</label><textarea class="form-control" name="description" rows="2"><?=e($edit['description']??post('description'))?></textarea></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="closeModal('mModal')">Cancel</button><button type="submit" class="btn btn-primary"><?=$edit?'Save Changes':'Add Medicine'?></button></div>
    </form></div>
</div>
<?php if($edit||!empty($errors)):?><script>openModal('mModal');</script><?php endif;?>
<?php /* importPanel display is now PHP-controlled (display:block when preview exists) */ ?>

<script>
function toggleExportMenu(){
  var m=document.getElementById('exportMenu');
  m.style.display=m.style.display==='none'?'block':'none';
}
document.addEventListener('click',function(e){
  var w=document.getElementById('exportWrap');
  if(w&&!w.contains(e.target)) document.getElementById('exportMenu').style.display='none';
});
function toggleImport(){
  var p=document.getElementById('importPanel');
  p.style.display=p.style.display==='none'?'block':'none';
}
function toggleOtherCat(){
  var sel=document.getElementById('catSelect');
  var wrap=document.getElementById('otherCatWrap');
  var inp=document.getElementById('catOther');
  if(sel.value==='Other'){wrap.style.display='block';inp.focus();}
  else{wrap.style.display='none';inp.value='';}
}
// On page load, show other field if "Other" was previously selected
document.addEventListener('DOMContentLoaded',function(){toggleOtherCat();});

// Override closeModal AFTER app.js loads (DOMContentLoaded fires after body scripts)
document.addEventListener('DOMContentLoaded', function() {
  var _origCloseMed = window.closeModal;
  window.closeModal = function(id) {
    if (id === 'mModal') {
      var url = new URL(window.location.href);
      url.searchParams.delete('action');
      url.searchParams.delete('id');
      window.history.replaceState({}, '', url.toString());
      var form = document.querySelector('#mModal form');
      if (form) {
        // Reset hidden fields and labels to "add" mode
        form.querySelectorAll('input[type=text],input[type=number],textarea').forEach(function(el){ el.value=''; });
        form.querySelectorAll('select').forEach(function(el){ el.selectedIndex=0; });
        var actInput = form.querySelector('input[name="action"]');
        if (actInput) actInput.value = 'add';
        var idInput = form.querySelector('input[name="id"]');
        if (idInput) idInput.remove();
        var title = document.querySelector('#mModal .modal-title');
        if (title) title.textContent = 'Add Medicine';
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.textContent = 'Add Medicine';
      }
      toggleOtherCat();
    }
    if (typeof _origCloseMed === 'function') _origCloseMed(id);
  };
});
</script>
<?php adminFooter();?>
