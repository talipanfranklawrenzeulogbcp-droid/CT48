<?php
require_once __DIR__.'/../../includes/helpers.php';
require_once __DIR__.'/../../includes/service_client.php';
require_incident_report_access();
$reportId=(int)($_GET['report_id']??0);
$fileIndex=(int)($_GET['file']??-1);
if($reportId<1 || $fileIndex<0){ http_response_code(400); exit('Invalid attachment reference.'); }
try {
    $records=service('health')->incidents();
    $report=null;
    foreach($records as $record){ if((int)$record['id']===$reportId){$report=$record;break;} }
    if(!$report){ http_response_code(404); exit('Incident report not found.'); }
    $files=json_decode((string)($report['attachment_paths']??'[]'),true);
    if(!is_array($files)||!isset($files[$fileIndex])||!is_string($files[$fileIndex])||
       !preg_match('#^storage/health_safety/incidents/([a-f0-9]{32})\.(jpg|png|webp|pdf)$#',$files[$fileIndex],$m)){
        http_response_code(404); exit('Attachment not found.');
    }
    $path=dirname(__DIR__,2).'/'.$files[$fileIndex];
    if(!is_file($path)){http_response_code(404);exit('Attachment file is missing from server storage.');}
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    $allowed=['image/jpeg','image/png','image/webp','application/pdf'];
    if(!in_array($mime,$allowed,true)){http_response_code(415);exit('Unsupported attachment type.');}
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="incident-'.$reportId.'-attachment-'.($fileIndex+1).'.'.strtolower($m[2]).'"');
    readfile($path);
} catch(Throwable $e) { http_response_code(500); exit('Unable to retrieve attachment.'); }
