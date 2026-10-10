<?php
final class HealthSafetyService {
    private const MODULE='Health, Safety & Welfare';
    public function __construct(private PDO $pdo, private AuditService $audit) {}

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
            if($title===''||$employee===''||empty($data['incident_date'])) throw new RuntimeException('Title, employee and incident date are required.');
            $severity=(string)($data['severity']??'Medium'); $status=(string)($data['status']??'Open');
            if(!in_array($severity,['Low','Medium','High','Critical'],true)) throw new RuntimeException('Invalid severity.');
            if(!in_array($status,['Open','Under Investigation','Closed'],true)) throw new RuntimeException('Invalid status.');
            $s=$this->pdo->prepare('INSERT INTO safety_incidents(title,employee_name,incident_date,severity,status,description) VALUES(?,?,?,?,?,?)');
            $s->execute([$title,$employee,$data['incident_date'],$severity,$status,trim((string)($data['description']??''))]);
            $this->audit->record($user,self::MODULE,'Create Incident',$title); return 'Safety incident saved.';
        }
        if($action==='delete_incident'){
            $id=(int)($data['id']??0); if($id<=0) throw new RuntimeException('Invalid incident.');
            $this->archiveRecord('safety_incidents',$id,'Safety Incident #'.$id,$user);
            $this->pdo->prepare('DELETE FROM safety_incidents WHERE id=?')->execute([$id]);
            $this->audit->record($user,self::MODULE,'Delete Incident','Archived ID '.$id); return 'Incident deleted and moved to Archive.';
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
