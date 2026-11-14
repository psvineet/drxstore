<?php
if (!defined('ROOT')) define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT.'/config/app.php'; require_once ROOT.'/views/layout_admin.php'; requireStaff();
if(!isset($_SESSION['cart'])) $_SESSION['cart']=[];
$medicines=$db->table('medicines'); usort($medicines,fn($a,$b)=>strcasecmp($a['name'],$b['name']));
$customers=$db->table('customers'); usort($customers,fn($a,$b)=>strcasecmp($a['name'],$b['name']));
$discounts=$db->find('discounts',fn($d)=>($d['active']??false));
// Calculate cart totals using per-medicine GST
$cartSub=0; $cartGst=0;
$medMapS=[]; foreach($db->table('medicines') as $m) $medMapS[$m['id']]=$m;
foreach($_SESSION['cart'] as $ci){
    $med=$medMapS[$ci['medicine_id']??0]??null;
    $gPct=(float)($med['gst_percent']??18);
    $inclAmt=(float)$ci['price']; // MRP×qty, GST inclusive
    $base=round($inclAmt/(1+$gPct/100),2);
    $cartSub += $base;
    $cartGst += round($inclAmt-$base,2);
}
$cartSub=round($cartSub,2); $cartGst=round($cartGst,2);
// cartTotal = sum of inclusive prices (what customer pays)
$cartTotal=0; foreach($_SESSION['cart'] as $ci) $cartTotal+=(float)$ci['price']; $cartTotal=round($cartTotal,2);
// Persist discount in session
if(getInt('discount_id')!==0)$_SESSION['cart_disc']=getInt('discount_id');
if(getInt('clear_disc')===1){unset($_SESSION['cart_disc']);unset($_SESSION['cart_disc_name']);}
$selDisc=$_SESSION['cart_disc']??0;
$discAmt=0;
if($selDisc){
    $disc=$db->findOne('discounts',fn($d)=>$d['id']===$selDisc&&($d['active']??false));
    if($disc){
        $discName=$disc['name'].' ('.($disc['type']==='percent'?$disc['value'].'%':money($disc['value'])).')';
        $_SESSION['cart_disc_name']=$discName;
        if(($disc['type']??'')==='percent') $discAmt=round($cartTotal*($disc['value']/100),2);
        else $discAmt=min((float)$disc['value'],$cartTotal);
    }else{unset($_SESSION['cart_disc']);}
}
$finalTotal=max(0,$cartTotal-$discAmt);
adminHeader('New Sale / POS','sales');
?>
<div class="page-hdr"><div><div class="page-title">New Sale — POS</div><div class="page-sub"><?=date('d M Y, h:i A')?></div></div>
<?php if(!empty($_SESSION['cart'])):?><span class="chip chip-blue"><?=count($_SESSION['cart'])?> item(s) in cart</span><?php endif;?></div>

<div style="display:grid;grid-template-columns:1fr 360px;gap:16px;align-items:start">
<div>
  <!-- Customer -->
  <div class="card mb-2"><div class="card-hdr"><div class="card-title">Customer</div></div><div class="card-body">
    <form method="POST" action="index.php?p=cart_setcust" class="flex gap-2 flex-wrap items-end">
      <?=csrfField()?>
      <div class="form-group" style="flex:1;min-width:180px;margin:0">
        <select class="form-control" name="customer_id" data-searchable data-placeholder="Walk-in Customer">
          <option value="">Walk-in Customer</option>
          <?php foreach($customers as $c):?><option value="<?=e($c['id'])?>" <?=($_SESSION['cart_cust']??'')==$c['id']?'selected':''?>><?=e($c['name'])?> — <?=e($c['phone'])?></option><?php endforeach;?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Set</button>
    </form>
    <?php if(!empty($_SESSION['cart_cust'])): $cc=$db->findOne('customers',fn($c)=>$c['id']==$_SESSION['cart_cust']);?>
      <?php if($cc):?><div class="alert alert-info mt-1" style="padding:6px 10px;font-size:.8rem"><span class="alert-body">Customer: <strong><?=e($cc['name'])?></strong> — <?=e($cc['phone'])?></span></div><?php endif;?>
    <?php endif;?>
  </div></div>

  <!-- Add medicine -->
  <div class="card"><div class="card-hdr"><div class="card-title">Add Medicine to Cart</div></div><div class="card-body">
    <form method="POST" action="index.php?p=cart_add">
      <?=csrfField()?>
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Medicine</label>
          <select class="form-control" name="medicine_id" id="medicine_id" onchange="loadBatches()" required data-searchable data-placeholder="— Search Medicine —">
            <option value="">— Select —</option>
            <?php foreach($medicines as $m):?><option value="<?=e($m['id'])?>"><?=e($m['name'])?></option><?php endforeach;?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Batch</label>
          <select class="form-control" name="batch_id" id="batch_id" onchange="updateBatchInfo()" required data-searchable data-placeholder="— Select Batch —">
            <option value="">Select medicine first</option>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:20px;padding:10px 14px;background:var(--g1);border-radius:var(--rl);margin-bottom:12px;font-size:.83rem;flex-wrap:wrap">
        <div><span class="text-muted">Stock:</span> <strong id="stock_display">—</strong></div>
        <div><span class="text-muted">MRP/Unit:</span> <strong id="mrp_display">—</strong></div>
        <div id="strip_info_wrap" style="display:none"><span class="text-muted">Pack:</span> <strong id="strip_info">—</strong></div>
      </div>
      <!-- Hidden strip data -->
      <input type="hidden" name="strip_qty" id="pos_strip_qty" value="1">
      <input type="hidden" name="qty_mode" id="pos_qty_mode_hidden" value="unit">
      <div class="form-row-2">
        <div class="form-group">
          <label class="form-label">Quantity &amp; Mode</label>
          <div class="flex gap-1 items-center">
            <input class="form-control" type="number" name="qty" id="qty" min="1" value="1" required oninput="posUpdateQtyCalc()" style="max-width:100px">
            <select class="form-control" id="pos_qty_mode" onchange="posToggleMode()" style="width:auto;min-width:90px">
              <option value="unit">Units</option>
              <option value="strip" id="pos_strip_option" disabled>Strips</option>
            </select>
          </div>
          <div id="pos_qty_hint" class="form-hint" style="color:var(--teal);margin-top:4px"></div>
        </div>
        <div class="form-group"><label class="form-label">Discount (applies to whole sale)</label>
          <div class="flex gap-1">
            <select class="form-control" name="discount_id" >
              <option value="">No Discount</option>
              <?php foreach($discounts as $d):?><option value="<?=e($d['id'])?>" <?=$selDisc==$d['id']?'selected':''?>><?=e($d['name'])?> (<?=($d['type']??'')==='percent'?$d['value'].'%':money($d['value'])?>)</option><?php endforeach;?>
            </select>
            <?php if($selDisc):?><a href="index.php?p=sales&clear_disc=1" class="btn btn-ghost btn-sm" style="white-space:nowrap">Clear</a><?php endif;?>
          </div>
          <?php if($discAmt>0):?><div class="form-hint" style="color:var(--green)">Discount applied: -<?=money($discAmt)?></div><?php endif;?>
        </div>
      </div>
      <!-- Payment method -->
      <div class="form-section">Payment Method</div>
      <div class="form-group"><label class="form-label">Payment Method</label>
        <select class="form-control" name="payment_method" id="payment_method" onchange="togglePayment()">
          <option value="cash">Cash</option>
          <option value="upi">UPI</option>
          <option value="cheque">Cheque</option>
          <option value="card">Card</option>
          <option value="credit">Credit (Pay Later)</option>
        </select>
      </div>
      <div id="pay_upi" class="pay-detail" style="display:none">
        <div class="form-group"><label class="form-label">UPI Reference Number</label><input class="form-control" type="text" name="upi_ref" placeholder="UPI transaction ID"></div>
      </div>
      <div id="pay_cheque" class="pay-detail" style="display:none">
        <div class="form-row-2">
          <div class="form-group"><label class="form-label">Cheque Number</label><input class="form-control" type="text" name="cheque_no" placeholder="Cheque No."></div>
          <div class="form-group"><label class="form-label">Bank Name</label><input class="form-control" type="text" name="cheque_bank" placeholder="Bank"></div>
        </div>
        <div class="form-group"><label class="form-label">Cheque Date</label><input class="form-control" type="date" name="cheque_date"></div>
      </div>
      <button type="submit" class="btn btn-success w-full">Add to Cart</button>
    </form>
  </div></div>
</div>

<!-- Cart -->
<div style="position:sticky;top:60px">
<div class="card"><div class="card-hdr"><div class="card-title">Cart</div>
  <?php if(!empty($_SESSION['cart'])):?><a href="<?=csrfUrl('index.php?p=cart_clear')?>" class="btn btn-ghost btn-sm" style="color:var(--red)" data-confirm="Clear cart?">Clear</a><?php endif;?>
</div>
<?php if(empty($_SESSION['cart'])):?>
  <div class="empty-state" style="padding:30px"><p>Cart is empty</p></div>
<?php else:?>
  <?php foreach($_SESSION['cart'] as $i=>$item):
    $iStrips=(int)($item['strips']??0); $iSQ=(int)($item['strip_qty']??1);
    if($iStrips>0&&$iSQ>1){
      $cartSub_line='Batch '.e($item['batch']).' | '.$iStrips.' strips × '.$iSQ.' = '.$item['qty'].' units @ '.money($item['mrp']??0).'/unit';
    } else {
      $cartSub_line='Batch '.e($item['batch']).' | '.$item['qty'].' units @ '.money($item['mrp']??0).'/unit';
    }
  ?>
  <div class="cart-row">
    <div style="flex:1;min-width:0"><div class="cart-name truncate"><?=e($item['name'])?></div><div class="cart-sub"><?=$cartSub_line?></div></div>
    <div class="fw-600 text-sm" style="white-space:nowrap"><?=money($item['price'])?></div>
    <a href="index.php?p=cart_remove&idx=<?=$i?>" class="btn btn-danger btn-sm" style="margin-left:4px">Remove</a>
  </div>
  <?php endforeach;?>
  <div class="cart-tots">
    <div class="cart-tot-row"><span>Subtotal</span><span><?=money($cartSub)?></span></div>
    <div class="cart-tot-row"><span>GST</span><span><?=money($cartGst)?></span></div>
    <?php if($discAmt>0):?><div class="cart-tot-row" style="color:var(--green)"><span>Discount</span><span>-<?=money($discAmt)?></span></div><?php endif;?>
    <div class="cart-tot-row grand"><span>Total</span><span><?=money($finalTotal)?></span></div>
  </div>
  <div style="padding:12px">
    <form method="POST" action="index.php?p=finalize">
      <?=csrfField()?>
      <input type="hidden" name="discount_id" value="<?=$selDisc?>">
      <input type="hidden" name="discount_amt" value="<?=$discAmt?>">
      <button type="submit" class="btn btn-primary btn-block btn-lg">Generate Invoice</button>
    </form>
  </div>
<?php endif;?>
</div></div>
</div>
<script>
function loadBatches(){
  var mid=document.getElementById('medicine_id');
  if(!mid||!mid.value){return;}
  fetch('index.php?p=get_batches&mid='+mid.value).then(r=>r.text()).then(h=>{
    var bs=document.getElementById('batch_id');
    if(bs){
      bs.innerHTML=h;
      // Auto-select first real batch when only one is available
      if(bs.options.length===2) bs.selectedIndex=1;
      updateBatchInfo();
    }
  });
}
function updateBatchInfo(){
  var bs=document.getElementById('batch_id');
  var sd=document.getElementById('stock_display'),md=document.getElementById('mrp_display');
  var siWrap=document.getElementById('strip_info_wrap'),si=document.getElementById('strip_info');
  var stripOpt=document.getElementById('pos_strip_option');
  var modeEl=document.getElementById('pos_qty_mode');

  if(!bs||!bs.value){
    if(sd)sd.textContent='—'; if(md)md.textContent='—';
    resetStripUI(); return;
  }
  var opt=bs.options[bs.selectedIndex];
  if(!opt||!opt.value){
    if(sd)sd.textContent='—'; if(md)md.textContent='—';
    resetStripUI(); return;
  }

  var stock=parseInt(opt.getAttribute('data-stock')||'0')||0;
  var mrp=parseFloat(opt.getAttribute('data-mrp')||'0');
  var sq=parseInt(opt.getAttribute('data-strip-qty')||'1')||1;
  var pt=opt.getAttribute('data-packing')||'';

  // Update info bar
  if(sd)sd.textContent=stock+' units'+(sq>1?' ('+Math.floor(stock/sq)+' strips)':'');
  if(md)md.textContent='₹'+mrp.toFixed(2);

  // Store strip_qty in hidden field
  var stripHidden=document.getElementById('pos_strip_qty');
  if(stripHidden)stripHidden.value=sq;

  // Show/hide pack info
  if(sq>1){
    if(siWrap)siWrap.style.display='';
    if(si)si.textContent=(pt||'Pack')+' · '+sq+' units/strip · ₹'+(mrp*sq).toFixed(2)+'/strip';
    // Enable Strips option
    if(stripOpt){stripOpt.disabled=false; stripOpt.textContent='Strips ('+sq+'/strip)';}
  } else {
    if(siWrap)siWrap.style.display='none';
    // Disable Strips option and reset to unit
    if(stripOpt){stripOpt.disabled=true; stripOpt.textContent='Strips';}
    if(modeEl&&modeEl.value==='strip')modeEl.value='unit';
  }
  posToggleMode();
}
function resetStripUI(){
  var stripHidden=document.getElementById('pos_strip_qty');
  if(stripHidden)stripHidden.value=1;
  var stripOpt=document.getElementById('pos_strip_option');
  if(stripOpt){stripOpt.disabled=true; stripOpt.textContent='Strips';}
  var mode=document.getElementById('pos_qty_mode');
  if(mode)mode.value='unit';
  var siWrap=document.getElementById('strip_info_wrap');
  if(siWrap)siWrap.style.display='none';
  posToggleMode();
}
function posToggleMode(){
  var sq=parseInt((document.getElementById('pos_strip_qty')||{}).value||1)||1;
  var mode=document.getElementById('pos_qty_mode');
  var modeVal=mode?mode.value:'unit';
  var modeHidden=document.getElementById('pos_qty_mode_hidden');
  if(modeHidden)modeHidden.value=modeVal;
  posUpdateQtyCalc();
}
function posUpdateQtyCalc(){
  var sq=parseInt((document.getElementById('pos_strip_qty')||{}).value||1)||1;
  var mode=document.getElementById('pos_qty_mode');
  var modeVal=mode?mode.value:'unit';
  var qtyEl=document.getElementById('qty');
  var hint=document.getElementById('pos_qty_hint');
  if(!qtyEl||!hint) return;
  if(modeVal==='strip'&&sq>1){
    var strips=parseInt(qtyEl.value)||0;
    var units=strips*sq;
    hint.innerHTML=strips+' strip'+(strips!==1?'s':'')+' &times; '+sq+' = <strong>'+units+' units</strong> | Total: <strong>₹'+(units*(parseFloat(document.getElementById('mrp_display')&&document.getElementById('mrp_display').textContent.replace('₹','')||0))).toFixed(2)+'</strong>';
  } else {
    var mrpVal=parseFloat((document.getElementById('mrp_display')||{}).textContent&&document.getElementById('mrp_display').textContent.replace('₹','')||0);
    var uqty=parseInt(qtyEl.value)||0;
    hint.innerHTML=uqty>0&&mrpVal>0?'Total: <strong>₹'+(uqty*mrpVal).toFixed(2)+'</strong>':'';
  }
}
// Intercept form submit: convert strip qty → unit qty before POST
document.addEventListener('DOMContentLoaded',function(){
  // Strips option disabled by default until a strip batch is selected
  var stripOpt=document.getElementById('pos_strip_option');
  if(stripOpt)stripOpt.disabled=true;

  // Bind via addEventListener so searchable-widget replacements still fire these
  var batchEl=document.getElementById('batch_id');
  if(batchEl) batchEl.addEventListener('change', updateBatchInfo);
  var modeEl=document.getElementById('pos_qty_mode');
  if(modeEl) modeEl.addEventListener('change', posToggleMode);

  var form=document.querySelector('form[action="index.php?p=cart_add"]');
  if(form){
    form.addEventListener('submit',function(e){
      var sq=parseInt((document.getElementById('pos_strip_qty')||{}).value||1)||1;
      var mode=document.getElementById('pos_qty_mode');
      var modeVal=mode?mode.value:'unit';
      var qtyEl=document.getElementById('qty');
      if(modeVal==='strip'&&sq>1&&qtyEl){
        var strips=parseInt(qtyEl.value)||0;
        if(strips<1){e.preventDefault();alert('Enter at least 1 strip.');return;}
        qtyEl.value=strips*sq; // convert to units before POST
      }
      var modeHidden=document.getElementById('pos_qty_mode_hidden');
      if(modeHidden)modeHidden.value=modeVal;
    });
  }
});
</script>
<?php adminFooter();?>
