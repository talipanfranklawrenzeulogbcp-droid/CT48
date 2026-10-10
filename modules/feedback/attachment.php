<?php
require_once __DIR__.'/../../includes/helpers.php';
require_login();
$u=current_user();
$id=(int)($_GET['id']??0);
if($id<=0){http_response_code(404);exit('File not found.');}
$q=db()->prepare("SELECT a.*,c.notification_id,n.sender_user_id FROM feedback_attachments a JOIN feedback_cases c ON c.id=a.feedback_case_id JOIN admin_notifications n ON n.id=c.notification_id WHERE a.id=? AND n.type='feedback' AND n.deleted_at IS NULL LIMIT 1");
$q->execute([$id]);$file=$q->fetch();
if(!$file || (($u['role']??'')!=='Administrator' && (int)$file['sender_user_id']!==(int)$u['id'])){http_response_code(404);exit('File not found.');}
$path=dirname(__DIR__,2).'/storage/feedback_attachments/'.$file['stored_name'];
if(!is_file($path)){http_response_code(404);exit('File not found.');}
$mime=in_array($file['mime_type'],['image/png','image/jpeg','application/pdf','text/plain'],true)?$file['mime_type']:'application/octet-stream';
header('X-Content-Type-Options: nosniff');header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"],'_',basename($file['original_name'])).'"');readfile($path);exit;
