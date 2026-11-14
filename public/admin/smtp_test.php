<?php
/**
 * SMTP test endpoint
 * Developed by Vineet
 */
if (!defined('ROOT')) define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT.'/config/app.php';
requireAdmin(); verifyCsrf();
$to=post('test_email');
if(!filter_var($to,FILTER_VALIDATE_EMAIL)){setFlash('danger','Invalid email.');header('Location: index.php?p=settings');exit;}
$cfg=getSettings();
// SECURITY (M1): Decrypt stored SMTP password before use — same logic as Mailer::fromSettings()
$rawPass = $cfg['smtp_pass'] ?? '';
if (str_starts_with($rawPass, 'ENC:')) {
    $blob    = base64_decode(substr($rawPass, 4));
    $iv      = substr($blob, 0, 12);
    $tag     = substr($blob, 12, 16);
    $enc     = substr($blob, 28);
    $key     = defined('SMTP_KEY') ? SMTP_KEY : '';
    $decoded = openssl_decrypt($enc, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    $rawPass = $decoded !== false ? $decoded : '';
}
$smtpCfg=['host'=>$cfg['smtp_host']??'','port'=>(int)($cfg['smtp_port']??587),'user'=>$cfg['smtp_user']??'','pass'=>$rawPass,'from'=>$cfg['smtp_from']??storeEmail(),'name'=>$cfg['smtp_name']??storeName(),'secure'=>$cfg['smtp_secure']??'tls'];
$result=Mailer::test($to,$smtpCfg);
if($result['success'])setFlash('success',$result['message']);
else setFlash('danger','SMTP test failed: '.$result['message']);
header('Location: index.php?p=settings');exit;
