<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/service_client.php';
require_login();
if($_SERVER['REQUEST_METHOD']!=='POST'){ redirect('/dashboard.php'); }
$u=current_user();
$action=(string)($_POST['action']??'send');
$feedback=trim((string)($_POST['feedback']??''));
$returnTo=trim((string)($_POST['return_to']??''));
if($returnTo==='' || !str_starts_with($returnTo,'/') || str_starts_with($returnTo,'//')){ $returnTo='/dashboard.php'; }
// return_to is a path from the browser (already includes the app base); strip it before redirect() re-adds it.
$base=rtrim(base_url(),'/');
if($base!=='' && str_starts_with($returnTo,$base.'/')){ $returnTo=substr($returnTo,strlen($base)); }

try {
    if(!csrf_valid()) throw new RuntimeException('Your session security token expired. Please try again.');
    $svc=service('feedback');
    if($action==='reply'){
        $svc->reply($u,(int)($_POST['notification_id']??0),$feedback);
        $_SESSION['feedback_sent']=true;
        $_SESSION['feedback_action']='reply';
        flash('success','Your reply was sent to the staff member.');
    } else {
        $rating=(int)($_POST['rating']??0);
        $svc->submit($u,$feedback,(string)($_POST['category']??'Other'),$rating>0?$rating:null);
        $_SESSION['feedback_sent']=true;
        $_SESSION['feedback_action']='send';
        flash('success','Your feedback was sent to the administrator.');
    }
} catch(Throwable $e){
    error_log('CT4 feedback failed: '.$e->getMessage());
    flash('error',$e->getMessage());
}
redirect($returnTo);
