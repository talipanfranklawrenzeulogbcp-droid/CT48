<?php
final class LegalComplianceService {
    private const MODULE='Legal & Compliance';
    public function __construct(private PDO $pdo, private AuditService $audit) {}
    public function obligations(): array { return $this->pdo->query('SELECT * FROM compliance_obligations ORDER BY reported_at DESC,created_at DESC,id DESC')->fetchAll(); }
    public function complianceReports(string $date='', ?int $limit=null): array {
        $this->ensureComplianceReportDocumentColumns();
        // A report is identified by report_name; ordinary obligations remain in their own list.
        $where=' WHERE report_name IS NOT NULL'; $params=[];
        if($date!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){ $where.=' AND DATE(COALESCE(reported_at,created_at))=?'; $params[]=$date; }
        $lim=$limit!==null ? ' LIMIT '.max(1,min(200,$limit)) : ''; $q=$this->pdo->prepare("SELECT * FROM compliance_obligations{$where} ORDER BY COALESCE(reported_at,created_at) DESC,id DESC{$lim}"); $q->execute($params); return $q->fetchAll();
    }
    public function complianceReportCount(string $date=''): int {
        $where=' WHERE report_name IS NOT NULL'; $params=[];
        if($date!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){ $where.=' AND DATE(COALESCE(reported_at,created_at))=?'; $params[]=$date; }
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM compliance_obligations'.$where); $q->execute($params); return (int)$q->fetchColumn();
    }
    private function ensureComplianceReportDocumentColumns(): void {
        // Additive migration: preserve existing compliance report rows and content.
        foreach (['report_document_path'=>'VARCHAR(500) NULL','report_document_name'=>'VARCHAR(255) NULL'] as $column=>$definition) {
            $check=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $check->execute(['compliance_obligations',$column]);
            if ((int)$check->fetchColumn()===0) $this->pdo->exec('ALTER TABLE compliance_obligations ADD COLUMN '.$column.' '.$definition);
        }
    }

    public function complianceReportDocumentFile(int $id): ?array {
        $this->ensureComplianceReportDocumentColumns();
        $q=$this->pdo->prepare('SELECT report_document_path,report_document_name FROM compliance_obligations WHERE id=? LIMIT 1');
        $q->execute([$id]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row || empty($row['report_document_path'])) return null;
        $base=realpath(dirname(__DIR__).'/storage/compliance_reports');
        $file=$base ? realpath($base.'/'.$row['report_document_path']) : false;
        if(!$base || !$file || !str_starts_with($file,$base.DIRECTORY_SEPARATOR) || !is_file($file)) return null;
        return ['path'=>$file,'name'=>(string)($row['report_document_name']??basename($file))];
    }

    private function storeComplianceReportUpload(array $upload): array {
        $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
        if($error!==UPLOAD_ERR_OK){
            $message=match($error){
                UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>'The selected document exceeds the server upload limit. Please choose a file of 10 MB or smaller.',
                UPLOAD_ERR_PARTIAL=>'The document was only partially uploaded. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR=>'The server upload folder is unavailable. Please contact the system administrator.',
                UPLOAD_ERR_CANT_WRITE=>'The server could not save the uploaded document. Please contact the system administrator.',
                UPLOAD_ERR_EXTENSION=>'The server stopped this upload. Please contact the system administrator.',
                default=>'The document upload failed. Please try again.'
            };
            throw new RuntimeException($message);
        }
        if((int)($upload['size']??0)>10*1024*1024) throw new RuntimeException('Documents must be 10 MB or smaller.');
        $tmp=(string)($upload['tmp_name']??'');
        if($tmp==='' || !is_uploaded_file($tmp)) throw new RuntimeException('Invalid uploaded file.');
        $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($tmp) ?: '';
        $allowed=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx'];
        if(!isset($allowed[$mime])) throw new RuntimeException('Allowed file types: PDF, JPG, PNG, DOC and DOCX.');
        $dir=dirname(__DIR__).'/storage/compliance_reports';
        if(!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('Compliance report document storage could not be created.');
        $stored=bin2hex(random_bytes(24)).'.'.$allowed[$mime];
        if(!move_uploaded_file($tmp,$dir.'/'.$stored)) throw new RuntimeException('Unable to store the compliance report document.');
        return [$stored,basename((string)($upload['name']??$stored))];
    }

    private function ensureDocumentColumn(string $column, string $definition): void {
        // Use information_schema rather than ALTER ... IF NOT EXISTS, which is not
        // supported consistently by all MySQL/MariaDB versions used in hosting.
        $check = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $check->execute(['employee_document_records', $column]);
        if ((int)$check->fetchColumn() === 0) {
            // The table and column names are fixed constants in this service.
            $this->pdo->exec('ALTER TABLE employee_document_records ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    public function ensureEmployeeDocumentChecklists(): void {
        // Additive migration: create the missing table only when needed. Existing
        // tables and checklist data remain untouched.
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS employee_document_records (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            health_record_id INT UNSIGNED NULL,
            employee_name VARCHAR(120) NOT NULL,
            document_name VARCHAR(180) NOT NULL,
            category VARCHAR(80) NOT NULL DEFAULT 'Recruitment',
            required_flag TINYINT(1) NOT NULL DEFAULT 1,
            status ENUM('Missing','Requested','Received','Verified','Expired','Not Applicable') NOT NULL DEFAULT 'Missing',
            issue_date DATE NULL,
            expiry_date DATE NULL,
            reference_no VARCHAR(120) NULL,
            document_path VARCHAR(500) NULL,
            uploaded_file_name VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_document_employee (employee_name),
            INDEX idx_document_status (status),
            INDEX idx_document_health_record (health_record_id),
            UNIQUE KEY uq_health_document (health_record_id, document_name),
            CONSTRAINT fk_document_health_record FOREIGN KEY (health_record_id) REFERENCES health_records(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $this->ensureDocumentColumn('document_path', 'VARCHAR(500) NULL');
        $this->ensureDocumentColumn('uploaded_file_name', 'VARCHAR(255) NULL');
        // Create a checklist for existing health records without duplicating data.
        $records=$this->pdo->query('SELECT id,employee_name FROM health_records ORDER BY checkup_date DESC,id DESC')->fetchAll();
        $checklist=['Medical Certificate'=>'Medical','Fit-to-Work Certificate'=>'Medical','Drug Test Result'=>'Medical','Chest X-ray Result'=>'Medical','Complete Blood Count (CBC)'=>'Medical','Urinalysis Result'=>'Medical','Hepatitis B Screening (if required)'=>'Medical','Valid Passport / Government ID'=>'Identity','Updated Resume / CV'=>'Recruitment','Employment Contract / Job Offer'=>'Employment','Work Visa / Work Permit (destination-dependent)'=>'Deployment','Training / Skills Certificates (if required)'=>'Qualifications','Pre-Departure Orientation Certificate (if applicable)'=>'Deployment','Police / NBI Clearance (if required)'=>'Clearance'];
        $ins=$this->pdo->prepare("INSERT IGNORE INTO employee_document_records(health_record_id,employee_name,document_name,category,required_flag,status) VALUES(?,?,?,?,1,'Missing')");
        foreach($records as $record) foreach($checklist as $name=>$category) $ins->execute([(int)$record['id'],$record['employee_name'],$name,$category]);
    }
    public function documentEmployeeOptions(): array {
        return $this->pdo->query('SELECT id,employee_name,checkup_date FROM health_records ORDER BY employee_name ASC,checkup_date DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function employeeDocumentRecords(): array {
        $this->ensureEmployeeDocumentChecklists();
        return $this->pdo->query('SELECT d.*,h.checkup_date,h.fitness_status FROM employee_document_records d LEFT JOIN health_records h ON h.id=d.health_record_id ORDER BY d.employee_name ASC,d.created_at DESC,d.document_name ASC')->fetchAll();
    }

    public function documentFile(int $id): ?array {
        $q=$this->pdo->prepare('SELECT document_path,uploaded_file_name FROM employee_document_records WHERE id=? LIMIT 1'); $q->execute([$id]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row || empty($row['document_path'])) return null;
        $base=realpath(dirname(__DIR__).'/storage/employee_documents'); $file=$base ? realpath($base.'/'.$row['document_path']) : false;
        if(!$base || !$file || !str_starts_with($file,$base.DIRECTORY_SEPARATOR) || !is_file($file)) return null;
        return ['path'=>$file,'name'=>(string)($row['uploaded_file_name']??basename($file))];
    }
    public function audits(): array { return $this->pdo->query('SELECT * FROM compliance_audits ORDER BY audit_date DESC,id DESC')->fetchAll(); }
    public function stats(): array { return [
        'obligations'=>(int)$this->pdo->query('SELECT COUNT(*) FROM compliance_obligations WHERE report_name IS NULL')->fetchColumn(),
        'overdue'=>(int)$this->pdo->query("SELECT COUNT(*) FROM compliance_obligations WHERE report_name IS NULL AND status <> 'Compliant' AND (status='Overdue' OR due_date < CURDATE())")->fetchColumn(),
        'audits'=>(int)$this->pdo->query('SELECT COUNT(*) FROM compliance_audits')->fetchColumn(),
    ]; }
    private function archiveRecord(string $table, int $id, string $name, ?array $user): void {
        $stmt=$this->pdo->prepare("SELECT * FROM `$table` WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new RuntimeException('Record not found.');
        $this->pdo->prepare('INSERT INTO archive_items(item_type,source_table,source_id,item_name,payload,deleted_by) VALUES(?,?,?,?,?,?)')
            ->execute(['record',$table,$id,$name,json_encode($row,JSON_UNESCAPED_UNICODE),$user['id']??null]);
    }

    public function handle(string $action,array $data,?array $user, array $files=[]): string {
        if($action==='add_report'){
            $name=trim((string)($data['report_name']??'')); $role=trim((string)($data['report_role']??'')); $contact=trim((string)($data['contact_no']??'')); $note=trim((string)($data['compliance_note']??''));
            if($name===''||$role===''||$contact===''||$note==='') throw new RuntimeException('Name, role, contact no and compliance are required.');
            if(mb_strlen($name)>120 || mb_strlen($role)>120 || mb_strlen($contact)>60 || mb_strlen($note)>60000) throw new RuntimeException('Please shorten one or more fields. Name and role are limited to 120 characters, contact to 60, and report details to 60,000 characters.');
            if(!preg_match('/^[0-9+() .\-]{7,60}$/',$contact)) throw new RuntimeException('Enter a valid contact number using digits and common phone symbols.');
            $this->ensureComplianceReportDocumentColumns();
            $documentPath=null; $documentName=null; $upload=$files['report_document']??null;
            if(is_array($upload) && ($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                [$documentPath,$documentName]=$this->storeComplianceReportUpload($upload);
            }
            $title=$name.' — Compliance Report';
            $s=$this->pdo->prepare('INSERT INTO compliance_obligations(title,report_name,report_role,contact_no,compliance_note,reported_at,category,owner,due_date,priority,status,report_document_path,report_document_name) VALUES(?,?,?,?,?,NOW(),?,?,CURDATE(),?,?,?,?)');
            $s->execute([$title,$name,$role,$contact,$note,'Compliance',$role,'Medium','Compliant',$documentPath,$documentName]);
            $this->audit->record($user,self::MODULE,'Create Compliance Report',$name.($documentName?' (attachment: '.$documentName.')':'')); return $documentName ? 'Compliance report and document saved.' : 'Compliance report saved.';
        }
        if($action==='add_obligation'){
            $title=trim((string)($data['title']??'')); if($title===''||empty($data['due_date'])) throw new RuntimeException('Title and due date are required.');
            $priority=(string)($data['priority']??'Medium'); $status=(string)($data['status']??'Open');
            if(!in_array($priority,['Low','Medium','High','Critical'],true)||!in_array($status,['Open','In Progress','Compliant','Overdue'],true)) throw new RuntimeException('Invalid compliance values.');
            $s=$this->pdo->prepare('INSERT INTO compliance_obligations(title,category,owner,due_date,priority,status) VALUES(?,?,?,?,?,?)');
            $s->execute([$title,trim((string)($data['category']??'')),trim((string)($data['owner']??'')),$data['due_date'],$priority,$status]);
            $this->audit->record($user,self::MODULE,'Create Compliance Obligation',$title); return 'Compliance obligation saved.';
        }
        if($action==='delete_report' || $action==='delete_obligation'){
            if(($user['role']??'')!=='Administrator') throw new RuntimeException('Only an administrator may delete compliance records.');
            $id=(int)($data['id']??0); if($id<=0) throw new RuntimeException('Invalid compliance record.');
            $isReport=$action==='delete_report';
            $stmt=$this->pdo->prepare('SELECT title,report_name FROM compliance_obligations WHERE id=? LIMIT 1'); $stmt->execute([$id]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$row) throw new RuntimeException('Compliance record not found.');
            if($isReport && empty($row['report_name'])) throw new RuntimeException('This record is an obligation, not a compliance report.');
            if(!$isReport && !empty($row['report_name'])) throw new RuntimeException('Use the compliance report action for this record.');
            $this->archiveRecord('compliance_obligations',$id,(string)($row['title']??('Compliance Record #'.$id)),$user);
            $this->pdo->prepare('DELETE FROM compliance_obligations WHERE id=?')->execute([$id]);
            $label=$isReport?'Compliance Report':'Compliance Obligation';
            $this->audit->record($user,self::MODULE,'Delete '.$label,'Archived ID '.$id); return $label.' deleted and moved to Archive.';
        }
        if($action==='add_audit'){
            $title=trim((string)($data['title']??'')); if($title===''||empty($data['audit_date'])) throw new RuntimeException('Audit title and date are required.');
            $status=(string)($data['status']??'Scheduled'); if(!in_array($status,['Scheduled','In Progress','Completed','Closed'],true)) throw new RuntimeException('Invalid audit status.');
            $s=$this->pdo->prepare('INSERT INTO compliance_audits(title,audit_date,auditor,status,findings) VALUES(?,?,?,?,?)');
            $s->execute([$title,$data['audit_date'],trim((string)($data['auditor']??'')),$status,trim((string)($data['findings']??''))]);
            $this->audit->record($user,self::MODULE,'Create Audit',$title); return 'Compliance audit saved.';
        }
        if($action==='update_employee_document'){
            $id=(int)($data['id']??0);
            $status=(string)($data['status']??'Missing');
            $allowed=['Missing','Requested','Received','Verified','Expired','Not Applicable'];
            if($id<1||!in_array($status,$allowed,true)) throw new RuntimeException('Invalid document record or status.');
            $newHealthRecordId=(int)($data['health_record_id']??0);
            if($newHealthRecordId<1) throw new RuntimeException('Please choose the employee who owns this document.');
            $ownerQ=$this->pdo->prepare('SELECT employee_name FROM health_records WHERE id=? LIMIT 1');
            $ownerQ->execute([$newHealthRecordId]); $ownerName=$ownerQ->fetchColumn();
            if($ownerName===false) throw new RuntimeException('The selected employee health record was not found.');
            $issue=trim((string)($data['issue_date']??'')); $expiry=trim((string)($data['expiry_date']??''));
            $notes=trim((string)($data['document_notes']??''));
            $q=$this->pdo->prepare('SELECT document_path,uploaded_file_name FROM employee_document_records WHERE id=?');
            $q->execute([$id]); $existing=$q->fetch(PDO::FETCH_ASSOC);
            if(!$existing) throw new RuntimeException('Document record not found.');
            $path=$existing['document_path']??null; $original=$existing['uploaded_file_name']??null;
            $upload=$files['document_file']??null;
            if(is_array($upload) && ($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                $uploadError=(int)($upload['error']??UPLOAD_ERR_OK);
                if($uploadError!==UPLOAD_ERR_OK){
                    $uploadErrorMessage=match($uploadError){
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected document exceeds the server upload limit. Please choose a file of 10 MB or smaller.',
                        UPLOAD_ERR_PARTIAL => 'The document was only partially uploaded. Please try again.',
                        UPLOAD_ERR_NO_TMP_DIR => 'The server upload folder is unavailable. Please contact the system administrator.',
                        UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded document. Please contact the system administrator.',
                        UPLOAD_ERR_EXTENSION => 'The server stopped this upload. Please contact the system administrator.',
                        default => 'The document upload failed. Please try again.'
                    };
                    throw new RuntimeException($uploadErrorMessage);
                }
                if((int)($upload['size']??0)>10*1024*1024) throw new RuntimeException('Documents must be 10 MB or smaller.');
                $tmp=(string)($upload['tmp_name']??'');
                if($tmp==='' || !is_uploaded_file($tmp)) throw new RuntimeException('Invalid uploaded file.');
                $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($tmp) ?: '';
                $allowed=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx'];
                if(!isset($allowed[$mime])) throw new RuntimeException('Allowed file types: PDF, JPG, PNG, DOC and DOCX.');
                $dir=dirname(__DIR__).'/storage/employee_documents';
                if(!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('Document storage could not be created.');
                $stored=bin2hex(random_bytes(24)).'.'.$allowed[$mime];
                if(!move_uploaded_file($tmp,$dir.'/'.$stored)) throw new RuntimeException('Unable to store the uploaded document.');
                $path=$stored; $original=basename((string)($upload['name']??$stored));
            }
            $q=$this->pdo->prepare('UPDATE employee_document_records SET health_record_id=?,employee_name=?,status=?,issue_date=?,expiry_date=?,notes=?,document_path=?,uploaded_file_name=? WHERE id=?');
            $q->execute([$newHealthRecordId,(string)$ownerName,$status,$issue!==''?$issue:null,$expiry!==''?$expiry:null,$notes!==''?$notes:null,$path,$original,$id]);
            $this->audit->record($user,self::MODULE,'Update Employee Document Record','Document record #'.$id.' assigned to '.$ownerName.($path?' (document saved)':''));
            return $upload && ($upload['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK ? 'Employee document and record saved.' : 'Employee document record updated.';
        }
        throw new RuntimeException('Unsupported Legal & Compliance action.');
    }
}
