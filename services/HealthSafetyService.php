<?php
final class HealthSafetyService {
    private const MODULE='Health, Safety & Welfare';
    public function __construct(private PDO $pdo, private AuditService $audit) {
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
        $base = dirname(__DIR__) . '/storage/health_safety/incidents';
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
            foreach ($saved as $relative) @unlink(dirname(__DIR__) . '/' . $relative);
            throw $e;
        }
        return $saved;
    }

    public function pdoForReporting(): PDO { return $this->pdo; }

    public function incidents(?string $date=null): array {
        if($date && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
            $s=$this->pdo->prepare('SELECT * FROM safety_incidents WHERE incident_date=? ORDER BY incident_date DESC,id DESC');
            $s->execute([$date]);
            return $s->fetchAll();
        }
        return $this->pdo->query('SELECT * FROM safety_incidents ORDER BY incident_date DESC,id DESC')->fetchAll();
    }

    public function incident(int $id): ?array {
        if ($id < 1) return null;
        $stmt=$this->pdo->prepare('SELECT * FROM safety_incidents WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function healthRecords(?string $date=null): array {
        if($date && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){ $s=$this->pdo->prepare('SELECT * FROM health_records WHERE checkup_date=? ORDER BY checkup_date DESC,id DESC'); $s->execute([$date]); return $s->fetchAll(); }
        return $this->pdo->query('SELECT * FROM health_records ORDER BY checkup_date DESC,id DESC')->fetchAll();
    }

    public function stats(?string $date=null): array {
        if($date && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM safety_incidents WHERE incident_date=?');$q->execute([$date]);$i=(int)$q->fetchColumn();
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM safety_incidents WHERE incident_date=? AND status <> 'Closed'");$q->execute([$date]);$o=(int)$q->fetchColumn();
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM health_records WHERE checkup_date=?');$q->execute([$date]);$h=(int)$q->fetchColumn();
        } else {
            $i=(int)$this->pdo->query('SELECT COUNT(*) FROM safety_incidents')->fetchColumn();
            $o=(int)$this->pdo->query("SELECT COUNT(*) FROM safety_incidents WHERE status <> 'Closed'")->fetchColumn();
            $h=(int)$this->pdo->query('SELECT COUNT(*) FROM health_records')->fetchColumn();
        }
        return ['incidents'=>$i,'open_incidents'=>$o,'health_records'=>$h];
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

    private function archiveRecord(string $table, int $id, string $name, ?array $user): void {
        $stmt=$this->pdo->prepare("SELECT * FROM `$table` WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new RuntimeException('Record not found.');
        $this->pdo->prepare('INSERT INTO archive_items(item_type,source_table,source_id,item_name,payload,deleted_by) VALUES(?,?,?,?,?,?)')
            ->execute(['record',$table,$id,$name,json_encode($row,JSON_UNESCAPED_UNICODE),$user['id']??null]);
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
                foreach ($attachments as $relative) @unlink(dirname(__DIR__).'/'.$relative);
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
                foreach ($newFiles as $relative) @unlink(dirname(__DIR__).'/'.$relative);
                throw new RuntimeException('This report already has five attachments. Remove old attachments before adding more.');
            }
            $allFiles=array_values(array_merge($oldFiles,$newFiles));
            $assignments=array_map(static fn($c)=>'`'.$c.'`=?',array_keys($payload));
            $sql='UPDATE safety_incidents SET '.implode(',',$assignments).',attachment_paths=? WHERE id=?';
            try {
                $stmt=$this->pdo->prepare($sql);
                $stmt->execute([...array_values($payload),$allFiles ? json_encode($allFiles,JSON_UNESCAPED_SLASHES) : null,$id]);
            } catch (Throwable $e) {
                foreach ($newFiles as $relative) @unlink(dirname(__DIR__).'/'.$relative);
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
        if($action==='add_health'){
            $employee=trim((string)($data['employee_name']??'')); if($employee===''||empty($data['checkup_date'])) throw new RuntimeException('Employee and checkup date are required.');
            $fitness=(string)($data['fitness_status']??'Pending'); if(!in_array($fitness,['Fit','Fit with Restrictions','Unfit','Pending'],true)) throw new RuntimeException('Invalid fitness status.');
            $height=($data['height_cm']??'')!==''?(float)$data['height_cm']:null;
            $weight=($data['weight_kg']??'')!==''?(float)$data['weight_kg']:null;
            if($height!==null && ($height<50 || $height>250)) throw new RuntimeException('Height must be between 50 and 250 cm.');
            if($weight!==null && ($weight<2 || $weight>400)) throw new RuntimeException('Weight must be between 2 and 400 kg.');
            $bmi=($height!==null && $weight!==null)?round($weight/pow($height/100,2),1):null;
            $s=$this->pdo->prepare('INSERT INTO health_records(employee_name,checkup_date,record_type,fitness_status,notes,employee_id,allergies,medical_conditions,medications,lab_results,restrictions,next_review_date,clinician_name,confidential_notes,height_cm,weight_kg,bmi) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$employee,$data['checkup_date'],trim((string)($data['record_type']??'')),$fitness,trim((string)($data['notes']??'')),trim((string)($data['employee_id']??'')),trim((string)($data['allergies']??'')),trim((string)($data['medical_conditions']??'')),trim((string)($data['medications']??'')),trim((string)($data['lab_results']??'')),trim((string)($data['restrictions']??'')),($data['next_review_date']??'')?:null,trim((string)($data['clinician_name']??'')),trim((string)($data['confidential_notes']??'')),$height,$weight,$bmi]);
            $healthId=(int)$this->pdo->lastInsertId();
            $checklist=['Medical Certificate'=>'Medical','Fit-to-Work Certificate'=>'Medical','Drug Test Result'=>'Medical','Chest X-ray Result'=>'Medical','Complete Blood Count (CBC)'=>'Medical','Urinalysis Result'=>'Medical','Hepatitis B Screening (if required)'=>'Medical','Valid Passport / Government ID'=>'Identity','Updated Resume / CV'=>'Recruitment','Employment Contract / Job Offer'=>'Employment','Work Visa / Work Permit (destination-dependent)'=>'Deployment','Training / Skills Certificates (if required)'=>'Qualifications','Pre-Departure Orientation Certificate (if applicable)'=>'Deployment','Police / NBI Clearance (if required)'=>'Clearance'];
            $doc=$this->pdo->prepare('INSERT IGNORE INTO employee_document_records(health_record_id,employee_name,document_name,category,required_flag,status) VALUES(?,?,?,?,1,\'Missing\')');
            foreach($checklist as $docName=>$category){$doc->execute([$healthId,$employee,$docName,$category]);}
            $this->audit->record($user,self::MODULE,'Create Health Record',$employee); return 'Health record saved and recruitment document checklist created.';
        }
        throw new RuntimeException('Unsupported Health & Safety action.');
    }
}
