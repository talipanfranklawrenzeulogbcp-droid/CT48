<?php
final class HealthSafetyService {
    private const MODULE='Health, Safety & Welfare';
    public function __construct(private PDO $pdo, private AuditService $audit, private ?NotificationService $notes=null) {
        $this->ensureIncidentColumns();
    }

    /**
     * Additive runtime migration: existing incident rows and all other tables are preserved.
     */
    private function ensureIncidentColumns(): void {
        $columns = [
            'incident_time' => 'VARCHAR(8) NULL',
            'location' => 'VARCHAR(180) NULL',
            'incident_type' => 'VARCHAR(100) NULL',
            'employee_id' => 'VARCHAR(80) NULL',
            'injury_details' => 'TEXT NULL',
            'body_part_affected' => 'VARCHAR(180) NULL',
            'witnesses' => 'TEXT NULL',
            'immediate_action' => 'TEXT NULL',
            'root_cause' => 'TEXT NULL',
            'corrective_action' => 'TEXT NULL',
            'reported_by' => 'VARCHAR(120) NULL',
            'supervisor_name' => 'VARCHAR(120) NULL',
            'lost_time' => 'VARCHAR(80) NULL',
            'attachment_paths' => 'LONGTEXT NULL'
        ];
        try {
            $existing = [];
            foreach ($this->pdo->query('SHOW COLUMNS FROM safety_incidents')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $existing[$col['Field']] = true;
            }
            foreach ($columns as $name => $definition) {
                if (!isset($existing[$name])) {
                    $this->pdo->exec("ALTER TABLE safety_incidents ADD COLUMN `$name` $definition");
                }
            }
        } catch (Throwable $e) {
            // Do not prevent read-only use if the DB account cannot perform migrations.
            // A subsequent incident save will report a useful database error if columns are missing.
        }
    }

    private function saveIncidentAttachments(): array {
        if (empty($_FILES['attachments']) || !is_array($_FILES['attachments']['name'] ?? null)) return [];
        $base = __DIR__ . '/storage/health_safety/incidents';
        if (!is_dir($base) && !mkdir($base, 0750, true) && !is_dir($base)) {
            throw new RuntimeException('Server storage for incident attachments could not be created.');
        }
        $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'application/pdf'=>'pdf'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $saved = [];
        $names = $_FILES['attachments']['name'];
        $count = count($names);
        if ($count > 5) throw new RuntimeException('Attach no more than 5 files.');
        try {
            for ($i = 0; $i < $count; $i++) {
                $error = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                if ($error === UPLOAD_ERR_NO_FILE) continue;
                if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('An attachment failed to upload. Each file must be 8 MB or smaller.');
                $tmp = $_FILES['attachments']['tmp_name'][$i] ?? '';
                $size = (int)($_FILES['attachments']['size'][$i] ?? 0);
                if ($size < 1 || $size > 8 * 1024 * 1024 || !is_uploaded_file($tmp)) {
                    throw new RuntimeException('Each attachment must be a valid upload no larger than 8 MB.');
                }
                $mime = $finfo->file($tmp);
                if (!isset($allowed[$mime])) throw new RuntimeException('Attachments must be JPG, PNG, WEBP images or PDF files.');
                $stored = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
                if (!move_uploaded_file($tmp, $base . '/' . $stored)) throw new RuntimeException('Unable to store an incident attachment on the server.');
                $saved[] = 'storage/health_safety/incidents/' . $stored;
            }
        } catch (Throwable $e) {
            foreach ($saved as $relative) @unlink(__DIR__ . '/' . $relative);
            throw $e;
        }
        return $saved;
    }

    public function pdoForReporting(): PDO { return $this->pdo; }

    public function incidents(?string $date=null): array {
        if($date && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date)){ $s=$this->pdo->prepare('SELECT * FROM safety_incidents WHERE incident_date=? ORDER BY incident_date DESC,id DESC'); $s->execute([$date]); return $s->fetchAll(); }
        return $this->pdo->query('SELECT * FROM safety_incidents ORDER BY incident_date DESC,id DESC')->fetchAll();
    }
    public function healthRecords(?string $date=null): array {
        if($date && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date)){ $s=$this->pdo->prepare('SELECT * FROM health_records WHERE checkup_date=? ORDER BY checkup_date DESC,id DESC'); $s->execute([$date]); return $s->fetchAll(); }
        return $this->pdo->query('SELECT * FROM health_records ORDER BY checkup_date DESC,id DESC')->fetchAll();
    }
    public function stats(?string $date=null): array {
        if($date && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
 $q=$this->pdo->prepare('SELECT COUNT(*) FROM safety_incidents WHERE incident_date=?');$q->execute([$date]);$i=(int)$q->fetchColumn();
 $q=$this->pdo->prepare("SELECT COUNT(*) FROM safety_incidents WHERE incident_date=? AND status <> 'Closed'");$q->execute([$date]);$o=(int)$q->fetchColumn();
 $q=$this->pdo->prepare('SELECT COUNT(*) FROM health_records WHERE checkup_date=?');$q->execute([$date]);$h=(int)$q->fetchColumn();
}else{$i=(int)$this->pdo->query('SELECT COUNT(*) FROM safety_incidents')->fetchColumn();$o=(int)$this->pdo->query("SELECT COUNT(*) FROM safety_incidents WHERE status <> 'Closed'")->fetchColumn();$h=(int)$this->pdo->query('SELECT COUNT(*) FROM health_records')->fetchColumn();}
return ['incidents'=>$i,'open_incidents'=>$o,'health_records'=>$h];
    }
    public function fileRequests(string $date='', ?int $limit=null): array {
        $where=''; $params=[];
        if($date!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){ $where=' WHERE request_date=?'; $params[]=$date; }
        $lim=$limit!==null ? ' LIMIT '.max(1,min(200,$limit)) : '';
        $q=$this->pdo->prepare("SELECT * FROM health_safety_files{$where} ORDER BY request_date DESC,id DESC{$lim}"); $q->execute($params); return $q->fetchAll();
    }
    public function storageFile(int $id): ?array { $q=$this->pdo->prepare('SELECT id,file_name,file_type FROM data_storage WHERE id=?'); $q->execute([$id]); return $q->fetch() ?: null; }
    private function archiveRecord(string $table, int $id, string $name, ?array $user): void {
        $stmt=$this->pdo->prepare("SELECT * FROM `$table` WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new RuntimeException('Record not found.');
        $this->pdo->prepare('INSERT INTO archive_items(item_type,source_table,source_id,item_name,payload,deleted_by) VALUES(?,?,?,?,?,?)')
            ->execute(['record',$table,$id,$name,json_encode($row,JSON_UNESCAPED_UNICODE),$user['id']??null]);
    }

    public function handle(string $action, array $data, ?array $user): string {
        if($action==='add_incident'){
            $title=trim((string)($data['title']??'')); $employee=trim((string)($data['employee_name']??''));
            $date=(string)($data['incident_date']??'');
            if($title===''||$employee===''||!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date)) throw new RuntimeException('Incident title, involved person and a valid incident date are required.');
            $severity=(string)($data['severity']??'Medium'); $status=(string)($data['status']??'Open');
            if(!in_array($severity,['Low','Medium','High','Critical'],true)) throw new RuntimeException('Invalid severity.');
            if(!in_array($status,['Open','Under Investigation','Closed'],true)) throw new RuntimeException('Invalid status.');
            $type=(string)($data['incident_type']??'Other');
            $types=['Near Miss','Injury','Illness','Property Damage','Environmental','Unsafe Condition','Fire','Other'];
            if(!in_array($type,$types,true)) throw new RuntimeException('Invalid incident type.');
            $attachments=$this->saveIncidentAttachments();
            try {
                $sql='INSERT INTO safety_incidents(title,employee_name,incident_date,severity,status,description,incident_time,location,incident_type,employee_id,injury_details,body_part_affected,witnesses,immediate_action,root_cause,corrective_action,reported_by,supervisor_name,lost_time,attachment_paths) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                $s=$this->pdo->prepare($sql);
                $s->execute([
                    $title,$employee,$date,$severity,$status,trim((string)($data['description']??'')),
                    trim((string)($data['incident_time']??'')) ?: null,
                    trim((string)($data['location']??'')) ?: null,$type,
                    trim((string)($data['employee_id']??'')) ?: null,
                    trim((string)($data['injury_details']??'')) ?: null,
                    trim((string)($data['body_part_affected']??'')) ?: null,
                    trim((string)($data['witnesses']??'')) ?: null,
                    trim((string)($data['immediate_action']??'')) ?: null,
                    trim((string)($data['root_cause']??'')) ?: null,
                    trim((string)($data['corrective_action']??'')) ?: null,
                    trim((string)($data['reported_by']??($user['name']??''))) ?: null,
                    trim((string)($data['supervisor_name']??'')) ?: null,
                    trim((string)($data['lost_time']??'')) ?: null,
                    $attachments ? json_encode($attachments, JSON_UNESCAPED_SLASHES) : null
                ]);
            } catch (Throwable $e) {
                foreach ($attachments as $relative) @unlink(__DIR__ . '/' . $relative);
                throw $e;
            }
            $this->audit->record($user,self::MODULE,'Create Incident',$title);
            return 'Incident report saved with '.count($attachments).' attachment(s).';
        }
        if($action==='delete_incident'){
            $id=(int)($data['id']??0); if($id<=0) throw new RuntimeException('Invalid incident.');
            $this->archiveRecord('safety_incidents',$id,'Safety Incident #'.$id,$user);
            $this->pdo->prepare('DELETE FROM safety_incidents WHERE id=?')->execute([$id]);
            $this->audit->record($user,self::MODULE,'Delete Incident','Archived ID '.$id); return 'Incident deleted and moved to Archive.';
        }
        if($action==='request_file' || $action==='release_file'){
 $employee=trim((string)($data['employee_name']??'')); $date=(string)($data['file_date']??date('Y-m-d')); $notes=trim((string)($data['file_notes']??''));
 if($employee===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) throw new RuntimeException('Requester and valid date are required.');
 if($action==='request_file'){
   $file=trim((string)($data['file_name']??'')); $type=trim((string)($data['file_type']??''));
   if($file==='') throw new RuntimeException('Requested file is required.');
   $requesterId=(int)($user['id']??0) ?: null;
   $q=$this->pdo->prepare('INSERT INTO health_safety_files(employee_name,requester_user_id,file_name,file_type,action_type,request_date,notes) VALUES(?,?,?,?,?,?,?)');
   $q->execute([$employee,$requesterId,$file,$type,'Requested',$date,$notes]);
   $this->audit->record($user,self::MODULE,'Pending File Request',$file.' — '.$employee);
   try { $this->notes?->notifyRole('Administrator','file_request','New File Request',$employee.' requested: '.$file.($type!==''?' ('.$type.')':''),$user,$requesterId); } catch(Throwable $e) {}
   return 'File request received and added to Pending Request Files.';
 }
 $storageIds=$data['storage_file_ids']??[];
 if(!is_array($storageIds)) $storageIds=[$storageIds];
 $storageIds=array_values(array_unique(array_filter(array_map('intval',$storageIds),fn($id)=>$id>0)));
 if(!$storageIds) throw new RuntimeException('Choose at least one data/file from Data Storage first.');
 $match=$this->pdo->prepare("SELECT id,requester_user_id FROM health_safety_files WHERE employee_name=? AND action_type='Requested' ORDER BY id ASC");
 $match->execute([$employee]); $pendingRows=$match->fetchAll();
 $requesterId=$pendingRows[0]['requester_user_id']??null;
 $releasedNames=[];
 $update=$this->pdo->prepare("UPDATE health_safety_files SET action_type='Released',request_date=?,file_name=?,file_type=?,storage_file_id=?,released_at=NOW(),notes=? WHERE id=?");
 $insert=$this->pdo->prepare("INSERT INTO health_safety_files(employee_name,requester_user_id,file_name,file_type,storage_file_id,action_type,request_date,notes,released_at) VALUES(?,?,?,?,?,?,?,?,NOW())");
 foreach($storageIds as $index=>$storageId){
   $stored=$this->storageFile($storageId);
   if(!$stored) throw new RuntimeException('One of the selected data/files is no longer available in Data Storage.');
   $releasedNames[]=$stored['file_name'];
   $pending=$pendingRows[$index]??null;
   $rowRequesterId=$pending['requester_user_id']??$requesterId;
   if($pending){
     $update->execute([$date,$stored['file_name'],$stored['file_type'],$storageId,$notes,(int)$pending['id']]);
   } else {
     $insert->execute([$employee,$rowRequesterId,$stored['file_name'],$stored['file_type'],$storageId,'Released',$date,$notes]);
   }
   if($rowRequesterId){
     try { $this->notes?->notify((int)$rowRequesterId,'file_release','File Released','Your requested file has been released: '.$stored['file_name'],$user); } catch(Throwable $e) {}
   }
 }
 $this->audit->record($user,self::MODULE,'Release File',implode(', ',$releasedNames).' — '.$employee);
 return 'successfully sent !';
        }
        if($action==='add_health'){
            $employee=trim((string)($data['employee_name']??'')); if($employee===''||empty($data['checkup_date'])) throw new RuntimeException('Employee and checkup date are required.');
            $fitness=(string)($data['fitness_status']??'Pending'); if(!in_array($fitness,['Fit','Fit with Restrictions','Unfit','Pending'],true)) throw new RuntimeException('Invalid fitness status.');
            $s=$this->pdo->prepare('INSERT INTO health_records(employee_name,checkup_date,record_type,fitness_status,notes) VALUES(?,?,?,?,?)');
            $s->execute([$employee,$data['checkup_date'],trim((string)($data['record_type']??'')),$fitness,trim((string)($data['notes']??''))]);
            $this->audit->record($user,self::MODULE,'Create Health Record',$employee); return 'Health record saved.';
        }
        throw new RuntimeException('Unsupported Health & Safety action.');
    }
}
