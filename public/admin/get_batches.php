<?php
if (!defined('ROOT')) define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT.'/config/app.php'; requireStaff();
header('Content-Type: text/html; charset=utf-8');
$mid=getInt('mid'); $today=date('Y-m-d');
if(!$mid){echo '<option value="">Select medicine</option>';exit;}
$batches=$db->find('batches',fn($b)=>(int)($b['medicine_id']??0)===(int)$mid&&($b['quantity']??0)>0&&($b['expiry_date']??'')>=$today);
if(empty($batches)){echo '<option value="">No valid batches</option>';exit;}
echo '<option value="">— Select Batch —</option>';
foreach($batches as $b){
    $sq = max(1, (int)($b['strip_qty']??1));
    $pt = $b['packing_type'] ?? '';
    $label = 'Batch '.e($b['batch_no']).' (Stock:'.$b['quantity'].' Exp:'.dateF($b['expiry_date']??'').')';
    if($pt) $label .= ' ['.htmlspecialchars($pt,ENT_QUOTES).($sq>1?' '.$sq.'/strip':'').']';
    printf('<option value="%d" data-stock="%d" data-mrp="%.4f" data-strip-qty="%d" data-packing="%s">%s</option>',
           $b['id'], $b['quantity'], $b['mrp'], $sq, htmlspecialchars($pt,ENT_QUOTES), $label);
}
