<?php
require_once __DIR__.'/../../includes/helpers.php';
require_once __DIR__.'/../../includes/service_client.php';
require_login();
$id=(int)($_GET['id']??0);
if($id<1){http_response_code(400);exit('Invalid document record.');}
try{$file=service('legal')->documentFile($id);}catch(Throwable $e){$file=null;}
if(!$file){http_response_code(404);exit('No uploaded document was found for this record.');}
$ext=strtolower(pathinfo($file['path'],PATHINFO_EXTENSION));
$types=['pdf'=>'application/pdf','jpg'=>'image/jpeg','png'=>'image/png','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
header('X-Content-Type-Options: nosniff');
header('Content-Type: '.($types[$ext]??'application/octet-stream'));
$inline=in_array($ext,['pdf','jpg','png'],true);
header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.str_replace(['"',"\r","\n"],'_',basename($file['name'])).'"');
header('Content-Length: '.filesize($file['path']));
readfile($file['path']);
exit;
