<?php
final class HealthSafetyService {
    private const MODULE='Health, Safety & Welfare';
    public function __construct(private PDO $pdo, private AuditService $audit, private ?NotificationService $notes=null) {
        $this->ensureIncidentColumns();
        $this->ensureHealthRecordColumns();
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

    /** Additive compatibility migration so older deployments can render and save the current health table. */
    private function ensureHealthRecordColumns(): void {
        $columns = [
            'employee_name' => 'VARCHAR(120) NULL',
            'checkup_date' => 'DATE NULL',
            'record_type' => 'VARCHAR(120) NULL',
            'fitness_status' => "VARCHAR(40) NOT NULL DEFAULT 'Pending'",
            'notes' => 'TEXT NULL',
            'employee_id' => 'VARCHAR(80) NULL',
            'blood_pressure' => 'VARCHAR(40) NULL',
            'temperature' => 'DECIMAL(4,1) NULL',
            'allergies' => 'TEXT NULL',
            'medical_conditions' => 'TEXT NULL',
            'medications' => 'TEXT NULL',
            'lab_results' => 'TEXT NULL',
            'restrictions' => 'TEXT NULL',
            'next_review_date' => 'DATE NULL',
            'clinician_name' => 'VARCHAR(120) NULL',
            'confidential_notes' => 'TEXT NULL',
            'height_cm' => 'DECIMAL(5,1) NULL',
            'weight_kg' => 'DECIMAL(5,1) NULL',
            'bmi' => 'DECIMAL(5,1) NULL'
        ];
        try {
            $existing=[];
            foreach ($this->pdo->query('SHOW COLUMNS FROM health_records')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $existing[$col['Field']]=true;
            }
        } catch (Throwable $e) {
            error_log('CT4 health_records schema inspection failed: '.$e->getMessage());
            return;
        }
        foreach ($columns as $name=>$definition) {
            if (isset($existing[$name])) continue;
            try {
                $this->pdo->exec("ALTER TABLE health_records ADD COLUMN `$name` $definition");
            } catch (Throwable $e) {
                error_log("CT4 health_records migration {$name} failed: ".$e->getMessage());
            }
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
        if($date && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date)){ $s=$this->pdo->prepare('SELECT * FROM safety_incidents WHERE incident_date=? ORDER BY created_at DESC,id DESC'); $s->execute([$date]); return $s->fetchAll(); }
        return $this->pdo->query('SELECT * FROM safety_incidents ORDER BY created_at DESC,id DESC')->fetchAll();
    }
    public function incident(int $id): ?array {
        if ($id < 1) return null;
        $stmt=$this->pdo->prepare('SELECT * FROM safety_incidents WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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

    private function assertIncidentContributor(?array $user): void {
        $email=strtolower(trim((string)($user['email']??'')));
        if (!$user || !in_array((string)($user['role']??''),['Administrator','Staff'],true)
            || $email==='records.entry@greatsolomonmpservices.com') {
            throw new RuntimeException('You do not have permission to manage incident reports.');
        }
    }

    private function assertIncidentAdministrator(?array $user): void {
        if (!$user || (string)($user['role']??'')!=='Administrator') {
            throw new RuntimeException('Only an administrator can delete incident reports.');
        }
    }

    private function incidentPayload(array $data, ?array $user): array {
        $trim=static fn(string $key): string => trim((string)($data[$key]??''));
        $title=$trim('title');
        $employee=$trim('employee_name');
        $date=$trim('incident_date');
        $description=$trim('description');
        if ($title==='' || mb_strlen($title)>180 || $employee==='' || mb_strlen($employee)>120 || $description==='') {
            throw new RuntimeException('Incident title, involved person and a detailed description are required.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) {
            throw new RuntimeException('Enter a valid incident date.');
        }
        $severity=$trim('severity') ?: 'Medium';
        $status=$trim('status') ?: 'Open';
        $type=$trim('incident_type') ?: 'Other';
        if (!in_array($severity,['Low','Medium','High','Critical'],true)) throw new RuntimeException('Invalid severity.');
        if (!in_array($status,['Open','Under Investigation','Closed'],true)) throw new RuntimeException('Invalid status.');
        if (!in_array($type,['Near Miss','Injury','Illness','Property Damage','Environmental','Unsafe Condition','Fire','Other'],true)) throw new RuntimeException('Invalid incident type.');
        $time=$trim('incident_time');
        if ($time!=='' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',$time)) throw new RuntimeException('Enter a valid incident time.');
        $reportedBy=$trim('reported_by');
        if ($reportedBy==='') $reportedBy=trim((string)($user['name']??''));
        $employeeId=$trim('employee_id'); $location=$trim('location'); $supervisor=$trim('supervisor_name'); $bodyPart=$trim('body_part_affected'); $lostTime=$trim('lost_time');
        if ($location==='') throw new RuntimeException('Incident location / worksite is required.');
        if (mb_strlen($location)>180 || mb_strlen($employeeId)>80 || mb_strlen($supervisor)>120 || mb_strlen($bodyPart)>180 || mb_strlen($lostTime)>80 || mb_strlen($reportedBy)>120) {
            throw new RuntimeException('One or more incident fields exceed their allowed length.');
        }
        return [
            'title'=>$title, 'employee_name'=>$employee, 'incident_date'=>$date, 'severity'=>$severity,
            'status'=>$status, 'description'=>$description, 'incident_time'=>$time ?: null,
            'location'=>$location ?: null, 'incident_type'=>$type, 'employee_id'=>$employeeId ?: null,
            'injury_details'=>$trim('injury_details') ?: null, 'body_part_affected'=>$bodyPart ?: null,
            'witnesses'=>$trim('witnesses') ?: null, 'immediate_action'=>$trim('immediate_action') ?: null,
            'root_cause'=>$trim('root_cause') ?: null, 'corrective_action'=>$trim('corrective_action') ?: null,
            'reported_by'=>$reportedBy ?: null, 'supervisor_name'=>$supervisor ?: null, 'lost_time'=>$lostTime ?: null
        ];
    }

    public function handle(string $action, array $data, ?array $user): string {
        if ($action==='add_incident') {
            $this->assertIncidentContributor($user);
            $payload=$this->incidentPayload($data,$user);
            $attachments=$this->saveIncidentAttachments();
            $columns=array_keys($payload);
            $sql='INSERT INTO safety_incidents('.implode(',',array_map(static fn($c)=>'`'.$c.'`',$columns)).',attachment_paths) VALUES('.implode(',',array_fill(0,count($columns)+1,'?')).')';
            try {
                $stmt=$this->pdo->prepare($sql);
                $stmt->execute([...array_values($payload),$attachments ? json_encode($attachments,JSON_UNESCAPED_SLASHES) : null]);
            } catch (Throwable $e) {
                foreach ($attachments as $relative) @unlink(__DIR__.'/'.$relative);
                throw $e;
            }
            $this->audit->record($user,self::MODULE,'Create Incident',$payload['title']);
            return 'Incident report saved with '.count($attachments).' attachment(s).';
        }

        if ($action==='update_incident') {
            $this->assertIncidentContributor($user);
            $id=(int)($data['id']??0);
            $current=$this->incident($id);
            if (!$current) throw new RuntimeException('Incident report not found.');
            $payload=$this->incidentPayload($data,$user);
            $oldFiles=json_decode((string)($current['attachment_paths']??'[]'),true);
            if (!is_array($oldFiles)) $oldFiles=[];
            $oldFiles=array_values(array_filter($oldFiles,static fn($f)=>is_string($f)));
            $newFiles=$this->saveIncidentAttachments();
            if ($newFiles && count($oldFiles)+count($newFiles)>5) {
                foreach ($newFiles as $relative) @unlink(__DIR__.'/'.$relative);
                throw new RuntimeException('This report already has five attachments. Remove old attachments before adding more.');
            }
            $allFiles=array_values(array_merge($oldFiles,$newFiles));
            $assignments=array_map(static fn($c)=>'`'.$c.'`=?',array_keys($payload));
            $sql='UPDATE safety_incidents SET '.implode(',',$assignments).',attachment_paths=? WHERE id=?';
            try {
                $stmt=$this->pdo->prepare($sql);
                $stmt->execute([...array_values($payload),$allFiles ? json_encode($allFiles,JSON_UNESCAPED_SLASHES) : null,$id]);
            } catch (Throwable $e) {
                foreach ($newFiles as $relative) @unlink(__DIR__.'/'.$relative);
                throw $e;
            }
            $this->audit->record($user,self::MODULE,'Update Incident','Incident #'.$id.' — '.$payload['title']);
            return 'Incident report updated successfully.'.($newFiles ? ' Added '.count($newFiles).' attachment(s).' : '');
        }

        if ($action==='delete_incident') {
            $this->assertIncidentAdministrator($user);
            $id=(int)($data['id']??0);
            if($id<=0) throw new RuntimeException('Invalid incident.');
            $this->pdo->beginTransaction();
            try {
                $this->archiveRecord('safety_incidents',$id,'Safety Incident #'.$id,$user);
                $this->pdo->prepare('DELETE FROM safety_incidents WHERE id=?')->execute([$id]);
                $this->pdo->commit();
            } catch(Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }
            $this->audit->record($user,self::MODULE,'Delete Incident','Archived ID '.$id);
            return 'Incident deleted and moved to Archive.';
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
            if (!$user || strtolower(trim((string)($user['email']??''))) !== 'records.entry@greatsolomonmpservices.com') {
                throw new RuntimeException('Only the authorized Records Entry account can add employee health records.');
            }
            $employee=trim((string)($data['employee_name']??''));
            $date=trim((string)($data['checkup_date']??''));
            if($employee==='' || mb_strlen($employee)>120) throw new RuntimeException('Enter a valid employee name.');
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) {
                throw new RuntimeException('Enter a valid checkup date.');
            }
            $fitness=(string)($data['fitness_status']??'Pending');
            if(!in_array($fitness,['Fit','Fit with Restrictions','Unfit','Pending'],true)) throw new RuntimeException('Invalid fitness status.');
            $recordType=trim((string)($data['record_type']??''));
            if(mb_strlen($recordType)>120) throw new RuntimeException('Record type is too long.');
            $nextReview=trim((string)($data['next_review_date']??''));
            if($nextReview!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$nextReview) || !checkdate((int)substr($nextReview,5,2),(int)substr($nextReview,8,2),(int)substr($nextReview,0,4)))) {
                throw new RuntimeException('Enter a valid next review date.');
            }
            $heightRaw=trim((string)($data['height_cm']??''));
            $weightRaw=trim((string)($data['weight_kg']??''));
            $height=$heightRaw!=='' && is_numeric($heightRaw) ? (float)$heightRaw : null;
            $weight=$weightRaw!=='' && is_numeric($weightRaw) ? (float)$weightRaw : null;
            if($height!==null && ($height<50 || $height>250)) throw new RuntimeException('Height must be between 50 and 250 cm.');
            if($weight!==null && ($weight<2 || $weight>400)) throw new RuntimeException('Weight must be between 2 and 400 kg.');
            $bmi=($height!==null && $weight!==null && $height>0) ? round($weight / (($height/100) ** 2), 1) : null;
            $employeeId=trim((string)($data['employee_id']??''));
            if(mb_strlen($employeeId)>80) throw new RuntimeException('Employee ID is too long.');
            $s=$this->pdo->prepare('INSERT INTO health_records (employee_name,checkup_date,record_type,fitness_status,notes,employee_id,allergies,medical_conditions,medications,lab_results,restrictions,next_review_date,clinician_name,confidential_notes,height_cm,weight_kg,bmi) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([
                $employee,$date,$recordType,$fitness,trim((string)($data['notes']??'')),$employeeId,
                trim((string)($data['allergies']??'')),trim((string)($data['medical_conditions']??'')),
                trim((string)($data['medications']??'')),trim((string)($data['lab_results']??'')),
                trim((string)($data['restrictions']??'')),$nextReview!==''?$nextReview:null,
                trim((string)($data['clinician_name']??'')),trim((string)($data['confidential_notes']??'')),
                $height,$weight,$bmi
            ]);
            $this->audit->record($user,self::MODULE,'Create Health Record',$employee); return 'Health record saved.';
        }
        throw new RuntimeException('Unsupported Health & Safety action.');
    }
}
