<?php
if (!defined('ROOT')) define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT.'/config/app.php'; requireStaff();
verifyCsrf();
$mid=postInt('medicine_id'); $bid=postInt('batch_id'); $qty=postInt('qty');
$stripQty=max(1,postInt('strip_qty',1)); // units per strip (from hidden field)
// Whitelist qty_mode to prevent arbitrary values
$qtyModeRaw=post('qty_mode','unit');
$qtyMode=in_array($qtyModeRaw,['unit','strip'],true)?$qtyModeRaw:'unit';
// If strip mode, qty is already converted to units by JS before submit — just record for display
$stripsCount=($qtyMode==='strip'&&$stripQty>1)?max(1,(int)round($qty/$stripQty)):0;
// Persist discount selection from the form (use postInt helper, not raw $_POST)
$discVal=postInt('discount_id',0);
if(isset($_POST['discount_id'])){
    if($discVal>0) $_SESSION['cart_disc']=$discVal;
    elseif($discVal===0) { unset($_SESSION['cart_disc']); unset($_SESSION['cart_disc_name']); }
}
if(!$mid||!$bid||$qty<1){setFlash('danger','Invalid input.');header('Location: index.php?p=sales');exit;}
$med=$db->findOne('medicines',fn($m)=>$m['id']===$mid);
$batch=$db->findOne('batches',fn($b)=>$b['id']===$bid);
if(!$med||!$batch){setFlash('danger','Not found.');header('Location: index.php?p=sales');exit;}
$today=date('Y-m-d');
if(($batch['expiry_date']??'')<$today){setFlash('danger','Medicine expired.');header('Location: index.php?p=sales');exit;}
if((float)($batch['mrp']??0)<=0){setFlash('danger','MRP not set for this batch. Please update the batch MRP before selling.');header('Location: index.php?p=sales');exit;}
$inCart=0;foreach($_SESSION['cart'] as $ci)if($ci['batch_id']===$bid)$inCart+=$ci['qty'];
if(($batch['quantity']??0)<($qty+$inCart)){setFlash('danger','Insufficient stock. Available: '.max(0,($batch['quantity']??0)-$inCart));header('Location: index.php?p=sales');exit;}
$_SESSION['cart'][]=['medicine_id'=>$mid,'batch_id'=>$bid,'name'=>$med['name'],'batch'=>$batch['batch_no'],'qty'=>$qty,'mrp'=>(float)$batch['mrp'],'price'=>round((float)$batch['mrp']*$qty,2),'strip_qty'=>$stripQty,'strips'=>$stripsCount,'payment_method'=>post('payment_method','cash'),'upi_ref'=>post('upi_ref'),'cheque_no'=>post('cheque_no'),'cheque_bank'=>post('cheque_bank'),'cheque_date'=>post('cheque_date')];
setFlash('success',e($med['name']).' added.');
header('Location: index.php?p=sales');exit;
