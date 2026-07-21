<?php
declare(strict_types=1);

final class SecurityService
{
    private PDO $db;
    private array $config;
    private string $root;
    private string $storage;
    private array $codeExtensions = [
        'php','phtml','php3','php4','php5','php7','php8','phar','inc',
        'js','html','htm','htaccess','ini','conf'
    ];

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
        $root = realpath((string)($config['root_path'] ?? ''));
        if (!$root || !is_dir($root)) throw new RuntimeException('La ruta de public_html no es válida.');
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->storage = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($this->storage === '') throw new RuntimeException('No se configuró la carpeta privada de seguridad.');
        $this->ensureDirectories();
    }

    public function rootPath(): string { return $this->root; }
    public function storagePath(): string { return $this->storage; }

    private function ensureDirectories(): void
    {
        foreach ([$this->storage,$this->storage.'/quarantine',$this->storage.'/backups',$this->storage.'/reports',$this->storage.'/locks'] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir,0750,true) && !is_dir($dir)) {
                throw new RuntimeException("No fue posible crear la carpeta privada: $dir");
            }
        }
        if (!is_file($this->storage.'/.htaccess')) @file_put_contents($this->storage.'/.htaccess',"Deny from all\nRequire all denied\n");
    }

    public function health(): array
    {
        $git = $this->runCommand([$this->gitBinary(),'--version'],$this->root,10);
        $clam = $this->runCommand([(string)($this->config['scan']['clamav_binary'] ?? 'clamscan'),'--version'],$this->root,10);
        $free = @disk_free_space($this->storage);
        $total = @disk_total_space($this->storage);
        return [
            'php_version'=>PHP_VERSION,
            'storage_writable'=>is_writable($this->storage),
            'storage_path'=>$this->storage,
            'root_path'=>$this->root,
            'zip_available'=>class_exists('ZipArchive'),
            'fileinfo_available'=>extension_loaded('fileinfo'),
            'exec_available'=>$this->canExecute(),
            'git_available'=>$git['code']===0,
            'git_version'=>trim($git['stdout'] ?: $git['stderr']),
            'clamav_available'=>$clam['code']===0,
            'clamav_version'=>trim($clam['stdout'] ?: $clam['stderr']),
            'disk_free'=>is_numeric($free)?(float)$free:0,
            'disk_total'=>is_numeric($total)?(float)$total:0,
            'is_git_repo'=>is_dir($this->root.'/.git'),
        ];
    }

    public function temporaryStorageStatus(int $minimumAgeHours=24): array
    {
        $path='/tmp';
        $free=@disk_free_space($path);$total=@disk_total_space($path);
        $inodeTotal=0;$inodeUsed=0;$inodeFree=0;$inodePercent=0.0;
        $inodeCheck=$this->runCommand(['/bin/df','-Pi',$path],$this->root,10);
        if(trim((string)$inodeCheck['stdout'])!==''){
            $lines=array_values(array_filter(array_map('trim',preg_split('/\R/',trim($inodeCheck['stdout']))?:[])));
            $last=$lines?end($lines):'';$parts=preg_split('/\s+/',(string)$last)?:[];
            if(count($parts)>=6){$inodeTotal=(int)$parts[1];$inodeUsed=(int)$parts[2];$inodeFree=(int)$parts[3];$inodePercent=(float)rtrim((string)$parts[4],'%');}
        }
        $candidates=$this->safeTemporaryCandidates($minimumAgeHours);
        $bytes=0;foreach($candidates as $item)$bytes+=(int)$item['size'];
        $used=is_numeric($total)&&is_numeric($free)?max(0,(float)$total-(float)$free):0;
        return [
            'path'=>$path,
            'available'=>is_dir($path)&&is_readable($path),
            'free'=>is_numeric($free)?(float)$free:0,
            'total'=>is_numeric($total)?(float)$total:0,
            'used'=>$used,
            'used_percent'=>(is_numeric($total)&&(float)$total>0)?round($used/(float)$total*100,1):0,
            'inode_total'=>$inodeTotal,
            'inode_used'=>$inodeUsed,
            'inode_free'=>$inodeFree,
            'inode_used_percent'=>$inodePercent,
            'safe_files'=>count($candidates),
            'safe_bytes'=>$bytes,
            'minimum_age_hours'=>max(1,$minimumAgeHours),
        ];
    }

    public function cleanSafeTemporaryFiles(string $user,int $minimumAgeHours=24): array
    {
        $lock=$this->acquireLock('temporary_cleanup');
        try{
            $before=$this->temporaryStorageStatus($minimumAgeHours);
            $deleted=0;$released=0;$failed=0;
            foreach($this->safeTemporaryCandidates($minimumAgeHours) as $item){
                $path=(string)$item['path'];
                if(!$this->isSafeTemporaryPath($path,$minimumAgeHours)){$failed++;continue;}
                if(@unlink($path)){$deleted++;$released+=(int)$item['size'];}else{$failed++;}
            }
            clearstatcache(true,'/tmp');
            $after=$this->temporaryStorageStatus($minimumAgeHours);
            $result=['deleted'=>$deleted,'released_bytes'=>$released,'failed'=>$failed,'before'=>$before,'after'=>$after];
            $this->logAction($user,'temporary_cleanup','/tmp',$failed?'partial':'success',$result);
            return $result;
        }finally{$this->releaseLock($lock);}
    }

    public function dashboard(): array
    {
        $lastScan = $this->db->query("SELECT * FROM ah_security_scans ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        $counts = $this->db->query("SELECT
            SUM(status='open') AS open_total,
            SUM(status='open' AND severity='critical') AS critical_total,
            SUM(status='open' AND severity='high') AS high_total,
            SUM(status='open' AND severity='medium') AS medium_total,
            SUM(status='open' AND severity='low') AS low_total
            FROM ah_security_findings")->fetch(PDO::FETCH_ASSOC) ?: [];
        $backup = $this->db->query("SELECT * FROM ah_security_backups WHERE status='ready' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        return ['last_scan'=>$lastScan,'findings'=>$counts,'last_backup'=>$backup,'health'=>$this->health(),'git'=>$this->gitStatus()];
    }

    public function recentScans(int $limit=15): array
    {
        $limit=max(1,min(100,$limit));
        return $this->db->query("SELECT * FROM ah_security_scans ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reconcileMissingOpenFindings(string $user='SYSTEM'): array
    {
        $rows=$this->db->query("SELECT id,path FROM ah_security_findings WHERE status='open' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $missing=[];
        foreach($rows as $row){
            try{$absolute=$this->absoluteFromRelative((string)$row['path']);}
            catch(Throwable $e){$missing[]=(int)$row['id'];continue;}
            if(!is_file($absolute))$missing[]=(int)$row['id'];
        }
        if(!$missing)return ['checked'=>count($rows),'removed'=>0];
        $this->db->beginTransaction();
        try{
            foreach(array_chunk($missing,250) as $ids){
                $marks=implode(',',array_fill(0,count($ids),'?'));
                $st=$this->db->prepare("DELETE FROM ah_security_findings WHERE status='open' AND id IN ($marks)");
                $st->execute($ids);
            }
            $this->db->commit();
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
        $result=['checked'=>count($rows),'removed'=>count($missing)];
        $this->logAction($user,'reconcile_missing_findings',$this->root,'ok',$result);
        return $result;
    }

    public function findings(string $status='open',string $severity='',int $limit=300): array
    {
        $where=[];$params=[];
        if ($status!=='') {$where[]='f.status=?';$params[]=$status;}
        if ($severity!=='') {$where[]='f.severity=?';$params[]=$severity;}
        $sql="SELECT f.*,s.started_at AS scan_started FROM ah_security_findings f LEFT JOIN ah_security_scans s ON s.id=f.scan_id";
        if ($where) $sql.=' WHERE '.implode(' AND ',$where);
        $sql.=" ORDER BY FIELD(f.severity,'critical','high','medium','low'),f.id DESC LIMIT ".max(1,min(1000,$limit));
        $st=$this->db->prepare($sql);$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function backups(int $limit=50): array
    {
        $limit=max(1,min(200,$limit));
        return $this->db->query("SELECT * FROM ah_security_backups ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function scan(string $mode,string $initiatedBy,bool $cli=false): array
    {
        $mode=in_array($mode,['quick','full'],true)?$mode:'quick';
        $lock=$this->acquireLock('scan');$scanId=0;
        try {
            $st=$this->db->prepare("INSERT INTO ah_security_scans(started_at,status,mode,initiated_by) VALUES(NOW(),'running',?,?)");
            $st->execute([$mode,$initiatedBy]);$scanId=(int)$this->db->lastInsertId();
            $maxContent=(int)($this->config['scan']['max_content_bytes']??8388608);
            $limit=$cli?0:max(100,(int)($this->config['scan']['web_file_limit']??12000));
            $files=0;$bytes=0;$errors=0;$sev=['critical'=>0,'high'=>0,'medium'=>0,'low'=>0];

            foreach ($this->fileIterator('scan') as $file) {
                if ($limit>0 && $files>=$limit) break;
                $absolute=$file->getPathname();$relative=$this->relativePath($absolute);
                if ($relative===''||$this->isExcluded($relative,'scan')) continue;
                try {
                    $size=(int)$file->getSize();$files++;$bytes+=max(0,$size);
                    $ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));
                    $shouldRead=$mode==='full'||in_array($ext,$this->codeExtensions,true)||$this->looksLikeUploadPath($relative)||$this->suspiciousFilename($relative);
                    $hash=$shouldRead?(string)(@hash_file('sha256',$absolute)?:''):'';
                    $rules=$this->inspectMetadata($absolute,$relative,$size,$ext);
                    if ($shouldRead && $size<=$maxContent) {
                        $content=@file_get_contents($absolute);
                        if (is_string($content)) $rules=array_merge($rules,$this->inspectContent($content,$relative,$ext));
                    }
                    $baseline=$this->baselineRow($relative);
                    if ($baseline && $hash!=='' && !hash_equals((string)$baseline['sha256'],$hash)) {
                        $rules[]=['severity'=>'low','code'=>'BASELINE_CHANGED','label'=>'Archivo modificado desde la línea base','evidence'=>'El hash SHA-256 no coincide con la última línea base aprobada.'];
                    }
                    foreach ($this->deduplicateRules($rules) as $rule) {
                        $this->storeFinding($scanId,$relative,$hash,$size,$file->getMTime(),$rule);
                        $sev[$rule['severity']]++;
                    }
                } catch (Throwable $e) {$errors++;}
            }

            $findings=array_sum($sev);$limited=(!$cli&&$limit>0&&$files>=$limit);
            $st=$this->db->prepare("UPDATE ah_security_scans SET finished_at=NOW(),status='finished',files_scanned=?,bytes_scanned=?,findings_count=?,critical_count=?,high_count=?,medium_count=?,low_count=?,errors_count=?,summary_json=? WHERE id=?");
            $st->execute([$files,$bytes,$findings,$sev['critical'],$sev['high'],$sev['medium'],$sev['low'],$errors,json_encode(['limited_by_web'=>$limited,'root'=>$this->root],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$scanId]);
            $this->reconcileMissingOpenFindings($initiatedBy);
            $this->logAction($initiatedBy,'scan_'.$mode,$this->root,'ok',['scan_id'=>$scanId,'findings'=>$findings]);
            if (($sev['critical']+$sev['high'])>0) $this->notify('Alerta de seguridad en Acción Honduras',"El escaneo #$scanId encontró ".($sev['critical']+$sev['high'])." hallazgos críticos o altos.");
            return ['scan_id'=>$scanId,'mode'=>$mode,'files_scanned'=>$files,'bytes_scanned'=>$bytes,'findings_count'=>$findings,'severity'=>$sev,'errors'=>$errors,'limited'=>$limited];
        } catch (Throwable $e) {
            if ($scanId>0) {
                $st=$this->db->prepare("UPDATE ah_security_scans SET finished_at=NOW(),status='failed',summary_json=? WHERE id=?");
                $st->execute([json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE),$scanId]);
            }
            throw $e;
        } finally {$this->releaseLock($lock);}
    }

    public function createBaseline(string $user): array
    {
        $lock=$this->acquireLock('baseline');
        try {
            $count=0;$errors=0;
            $st=$this->db->prepare("INSERT INTO ah_security_baseline(path,sha256,size_bytes,modified_at,created_at,updated_at) VALUES(?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE sha256=VALUES(sha256),size_bytes=VALUES(size_bytes),modified_at=VALUES(modified_at),updated_at=NOW()");
            foreach ($this->fileIterator('scan') as $file) {
                $relative=$this->relativePath($file->getPathname());
                if ($relative===''||$this->isExcluded($relative,'scan')||!$this->isCodeFile($relative)) continue;
                try {
                    $hash=@hash_file('sha256',$file->getPathname());if(!$hash)continue;
                    $st->execute([$relative,$hash,(int)$file->getSize(),date('Y-m-d H:i:s',$file->getMTime())]);$count++;
                } catch(Throwable $e){$errors++;}
            }
            $this->logAction($user,'baseline_update',$this->root,'ok',['files'=>$count,'errors'=>$errors]);
            return ['files'=>$count,'errors'=>$errors];
        } finally {$this->releaseLock($lock);}
    }

    public function quarantineFinding(int $findingId,string $user): array
    {
        $finding=$this->findingById($findingId);
        if(!$finding)throw new RuntimeException('Hallazgo no encontrado.');
        if($finding['status']==='quarantined')throw new RuntimeException('El archivo ya está en cuarentena.');
        $original=$this->absoluteFromRelative((string)$finding['path']);
        if(!is_file($original))throw new RuntimeException('El archivo ya no existe en public_html.');
        $hash=(string)(@hash_file('sha256',$original)?:'');
        $name=date('Ymd_His').'_'.bin2hex(random_bytes(6)).'.quarantine';$target=$this->storage.'/quarantine/'.$name;
        if(!@rename($original,$target) && (!@copy($original,$target)||!@unlink($original))) throw new RuntimeException('No fue posible mover el archivo a cuarentena.');
        @chmod($target,0600);
        $this->db->beginTransaction();
        try {
            $st=$this->db->prepare("INSERT INTO ah_security_quarantine(finding_id,original_path,quarantine_path,sha256,metadata_json,quarantined_by,quarantined_at) VALUES(?,?,?,?,?,?,NOW())");
            $st->execute([$findingId,$finding['path'],$target,$hash,json_encode(['finding'=>$finding],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$user]);
            $this->db->prepare("UPDATE ah_security_findings SET status='quarantined',updated_at=NOW() WHERE id=?")->execute([$findingId]);
            $this->db->commit();
        } catch(Throwable $e){$this->db->rollBack();@rename($target,$original);throw $e;}
        $this->logAction($user,'quarantine',$finding['path'],'ok',['sha256'=>$hash]);
        return ['original'=>$finding['path'],'quarantine'=>$name,'sha256'=>$hash];
    }

    public function restoreQuarantine(int $quarantineId,string $user): array
    {
        $st=$this->db->prepare("SELECT * FROM ah_security_quarantine WHERE id=? LIMIT 1");$st->execute([$quarantineId]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Registro de cuarentena no encontrado.');
        if(!empty($row['restored_at']))throw new RuntimeException('El archivo ya fue restaurado.');
        $source=(string)$row['quarantine_path'];$target=$this->absoluteFromRelative((string)$row['original_path']);
        if(!is_file($source))throw new RuntimeException('El archivo de cuarentena no existe.');
        if(file_exists($target))throw new RuntimeException('Ya existe un archivo en la ruta original. No se sobrescribió.');
        $dir=dirname($target);if(!is_dir($dir)&&!@mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('No fue posible recrear la carpeta original.');
        if(!@rename($source,$target)&&(!@copy($source,$target)||!@unlink($source)))throw new RuntimeException('No fue posible restaurar el archivo.');
        $hash=(string)(@hash_file('sha256',$target)?:'');
        if($row['sha256']&&!hash_equals((string)$row['sha256'],$hash)){@rename($target,$source);throw new RuntimeException('El archivo restaurado no superó la verificación SHA-256.');}
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE ah_security_quarantine SET restored_at=NOW(),restored_by=? WHERE id=?")->execute([$user,$quarantineId]);
            $this->db->prepare("UPDATE ah_security_findings SET status='restored',updated_at=NOW() WHERE id=?")->execute([(int)$row['finding_id']]);
            $this->db->commit();
        } catch(Throwable $e){$this->db->rollBack();throw $e;}
        $this->logAction($user,'restore',$row['original_path'],'ok',['sha256'=>$hash]);return ['path'=>$row['original_path'],'sha256'=>$hash];
    }

    public function ignoreFinding(int $id,string $user): void {$this->db->prepare("UPDATE ah_security_findings SET status='ignored',updated_at=NOW() WHERE id=?")->execute([$id]);$this->logAction($user,'ignore_finding',(string)$id,'ok',[]);}
    public function reopenFinding(int $id,string $user): void {$this->db->prepare("UPDATE ah_security_findings SET status='open',updated_at=NOW() WHERE id=?")->execute([$id]);$this->logAction($user,'reopen_finding',(string)$id,'ok',[]);}

    public function createCodeBackup(string $user,string $note=''): array
    {
        if(!class_exists('ZipArchive'))throw new RuntimeException('La extensión ZipArchive no está disponible.');
        $lock=$this->acquireLock('backup');$backupId=0;
        try {
            $filename='public_html_codigo_'.date('Ymd_His').'.zip';$target=$this->storage.'/backups/'.$filename;
            $st=$this->db->prepare("INSERT INTO ah_security_backups(filename,path,status,created_by,created_at,notes) VALUES(?,?,'creating',?,NOW(),?)");
            $st->execute([$filename,$target,$user,$note]);$backupId=(int)$this->db->lastInsertId();
            $zip=new ZipArchive();if($zip->open($target,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('No fue posible crear el ZIP de respaldo.');
            $manifest=[];$count=0;
            foreach($this->fileIterator('backup') as $file){
                $absolute=$file->getPathname();$relative=$this->relativePath($absolute);
                if($relative===''||$this->isExcluded($relative,'backup')||!$this->isBackupFile($relative))continue;
                $zip->addFile($absolute,$relative);
                $manifest[]=['path'=>$relative,'size'=>(int)$file->getSize(),'sha256'=>(string)(@hash_file('sha256',$absolute)?:''),'modified_at'=>date(DATE_ATOM,$file->getMTime())];$count++;
            }
            $zip->addFromString('__ACCION_HONDURAS_MANIFEST.json',json_encode(['created_at'=>date(DATE_ATOM),'created_by'=>$user,'root'=>$this->root,'files'=>$manifest],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $zip->close();$size=(int)filesize($target);$hash=(string)hash_file('sha256',$target);
            $this->db->prepare("UPDATE ah_security_backups SET size_bytes=?,sha256=?,status='ready' WHERE id=?")->execute([$size,$hash,$backupId]);
            $this->pruneBackups();$this->logAction($user,'backup_code',$filename,'ok',['files'=>$count,'size'=>$size,'sha256'=>$hash]);
            return ['id'=>$backupId,'filename'=>$filename,'path'=>$target,'files'=>$count,'size_bytes'=>$size,'sha256'=>$hash];
        } catch(Throwable $e){
            if($backupId>0)$this->db->prepare("UPDATE ah_security_backups SET status='failed',notes=CONCAT(IFNULL(notes,''),?) WHERE id=?")->execute(["\nError: ".$e->getMessage(),$backupId]);
            throw $e;
        } finally {$this->releaseLock($lock);}
    }

    public function backupById(int $id): ?array {$st=$this->db->prepare("SELECT * FROM ah_security_backups WHERE id=? LIMIT 1");$st->execute([$id]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;}
    public function deleteBackup(int $id,string $user): void
    {
        $row=$this->backupById($id);if(!$row)throw new RuntimeException('Respaldo no encontrado.');
        if(is_file($row['path'])&&!@unlink($row['path']))throw new RuntimeException('No fue posible eliminar el archivo de respaldo.');
        $this->db->prepare("UPDATE ah_security_backups SET status='deleted' WHERE id=?")->execute([$id]);$this->logAction($user,'delete_backup',$row['filename'],'ok',[]);
    }

    public function gitStatus(): array
    {
        if(empty($this->config['git']['enabled']))return ['enabled'=>false,'ok'=>false,'message'=>'Git deshabilitado'];
        if(!is_dir($this->root.'/.git'))return ['enabled'=>true,'ok'=>false,'message'=>'public_html no es un repositorio Git','changes_count'=>0];
        $branch=$this->runCommand([$this->gitBinary(),'branch','--show-current'],$this->root,20);
        $status=$this->runCommand([$this->gitBinary(),'status','--porcelain=v1'],$this->root,30);
        $remote=$this->runCommand([$this->gitBinary(),'remote','get-url',(string)($this->config['git']['remote']??'origin')],$this->root,20);
        $last=$this->runCommand([$this->gitBinary(),'log','-1','--pretty=format:%h|%ad|%s','--date=iso'],$this->root,20);
        $changes=trim($status['stdout'])!==''?(preg_split('/\r?\n/',trim($status['stdout']))?:[]):[];
        return ['enabled'=>true,'ok'=>$status['code']===0,'branch'=>trim($branch['stdout']),'remote'=>trim($remote['stdout']),'last_commit'=>trim($last['stdout']),'changes'=>$changes,'changes_count'=>count($changes),'message'=>$status['code']===0?'ok':trim($status['stderr'])];
    }

    public function backupCommitPush(string $user,string $message=''): array
    {
        if(empty($this->config['git']['enabled']))throw new RuntimeException('Git está deshabilitado.');
        if(!is_dir($this->root.'/.git'))throw new RuntimeException('public_html no tiene carpeta .git.');
        if(!empty($this->config['git']['block_push_on_high_findings'])){
            $openHigh=(int)$this->db->query("SELECT COUNT(*) FROM ah_security_findings WHERE status='open' AND severity IN('critical','high')")->fetchColumn();
            if($openHigh>0)throw new RuntimeException("Push bloqueado: existen $openHigh hallazgos críticos o altos sin resolver.");
        }
        $lock=$this->acquireLock('git');
        try {
            $backup=$this->createCodeBackup($user,'Respaldo automático previo a GitHub');$this->ensureGitIgnore();$git=$this->gitBinary();$remote=(string)($this->config['git']['remote']??'origin');
            $branchResult=$this->runCommand([$git,'branch','--show-current'],$this->root,20);$branch=trim($branchResult['stdout']);if($branchResult['code']!==0||$branch==='')throw new RuntimeException('No fue posible determinar la rama actual.');
            $this->runCommand([$git,'config','user.name',(string)$this->config['git']['user_name']],$this->root,20);
            $this->runCommand([$git,'config','user.email',(string)$this->config['git']['user_email']],$this->root,20);
            $add=$this->runCommand([$git,'add','-A'],$this->root,120);if($add['code']!==0)throw new RuntimeException('git add falló: '.$add['stderr']);
            $status=$this->runCommand([$git,'status','--porcelain=v1'],$this->root,30);if($status['code']!==0)throw new RuntimeException('git status falló: '.$status['stderr']);
            $committed=false;
            if(trim($status['stdout'])!==''){
                $message=trim($message)?:'Respaldo automático '.date('Y-m-d H:i:s');
                $commit=$this->runCommand([$git,'commit','-m',$message],$this->root,180);if($commit['code']!==0)throw new RuntimeException('git commit falló: '.trim($commit['stderr']?:$commit['stdout']));$committed=true;
            }
            $push=$this->runCommand([$git,'push',$remote,$branch],$this->root,300,['GIT_TERMINAL_PROMPT'=>'0']);
            if($push['code']!==0)throw new RuntimeException('git push falló: '.trim($push['stderr']?:$push['stdout']).'. Configure una clave SSH o credencial de GitHub en el servidor.');
            $this->logAction($user,'git_push',"$remote/$branch",'ok',['committed'=>$committed,'backup_id'=>$backup['id']]);
            return ['backup'=>$backup,'branch'=>$branch,'remote'=>$remote,'committed'=>$committed,'push_output'=>trim($push['stdout']."\n".$push['stderr'])];
        } finally {$this->releaseLock($lock);}
    }

    public function runClamAv(string $user): array
    {
        if(empty($this->config['scan']['clamav_enabled']))throw new RuntimeException('ClamAV está deshabilitado.');
        $binary=(string)($this->config['scan']['clamav_binary']??'clamscan');$args=[$binary,'-r','--infected','--no-summary'];
        foreach((array)($this->config['scan']['exclude_paths']??[]) as $path)$args[]='--exclude-dir='.preg_quote((string)$path,'#');
        $args[]=$this->root;$result=$this->runCommand($args,$this->root,1800);
        $this->logAction($user,'clamav_scan',$this->root,$result['code']<=1?'ok':'error',['code'=>$result['code'],'output'=>substr($result['stdout'].$result['stderr'],0,20000)]);
        return ['code'=>$result['code'],'infected'=>$result['code']===1,'output'=>trim($result['stdout']."\n".$result['stderr'])];
    }

    private function inspectMetadata(string $absolute,string $relative,int $size,string $ext): array
    {
        $rules=[];
        if($this->suspiciousFilename($relative))$rules[]=['severity'=>'medium','code'=>'SUSPICIOUS_FILENAME','label'=>'Nombre de archivo asociado a puertas traseras','evidence'=>basename($relative)];
        if(preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg|pdf|ico)\.(?:php|phtml|php\d*)$/i',$relative))$rules[]=['severity'=>'high','code'=>'DOUBLE_EXTENSION','label'=>'Doble extensión ejecutable','evidence'=>basename($relative)];
        if($this->looksLikeUploadPath($relative)&&in_array($ext,['php','phtml','phar','php5','php7','php8'],true))$rules[]=['severity'=>'high','code'=>'PHP_IN_UPLOADS','label'=>'Archivo PHP dentro de una carpeta de cargas','evidence'=>$relative];
        $base=basename($relative);if($base!==''&&$base[0]==='.'&&in_array($ext,['php','phtml','phar'],true))$rules[]=['severity'=>'medium','code'=>'HIDDEN_EXECUTABLE','label'=>'Archivo PHP oculto','evidence'=>$base];
        $perms=@fileperms($absolute);if(is_int($perms)&&(($perms&0x0002)===0x0002))$rules[]=['severity'=>'medium','code'=>'WORLD_WRITABLE','label'=>'Archivo modificable por cualquier usuario','evidence'=>substr(sprintf('%o',$perms),-4)];
        if($size===0&&in_array($ext,['php','phtml','js'],true))$rules[]=['severity'=>'low','code'=>'EMPTY_SCRIPT','label'=>'Archivo de código vacío','evidence'=>$relative];
        return $rules;
    }

    private function inspectContent(string $content,string $relative,string $ext): array
    {
        $rules=[];$sample=substr($content,0,8388608);
        $patterns=[
            ['critical','KNOWN_WEBSHELL','Firma conocida de webshell','/(?:FilesMan|WSO Shell|b374k|c99shell|r57shell|ALFA TEaM Shell|IndoXploit)/i'],
            ['critical','ENCODED_EXECUTION','Ejecución de código codificado','/(?:eval|assert)\s*\(\s*(?:base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i'],
            ['critical','REQUEST_COMMAND_EXECUTION','Ejecución de comandos desde una solicitud web','/(?:system|shell_exec|passthru|exec|popen|proc_open)\s*\([^;\n]{0,400}\$_(?:GET|POST|REQUEST|COOKIE)/i'],
            ['high','VARIABLE_FUNCTION_INPUT','Función dinámica controlada por el visitante','/\$[a-zA-Z_][a-zA-Z0-9_]*\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/'],
            ['high','PHP_UPLOAD_WRITE','Carga o escritura de PHP controlada por solicitud','/(?:move_uploaded_file|file_put_contents|fwrite)\s*\([^;\n]{0,500}(?:\.php|php[\'\"])[^;\n]{0,500}\$_(?:GET|POST|REQUEST|FILES)/i'],
            ['high','DYNAMIC_INCLUDE_INPUT','Inclusión dinámica controlada por el visitante','/(?:include|require)(?:_once)?\s*\(?\s*\$_(?:GET|POST|REQUEST|COOKIE)/i'],
            ['high','HTACCESS_PHP_HANDLER','Configuración que ejecuta archivos no PHP como PHP','/(?:AddHandler|SetHandler).*(?:php|application\/x-httpd-php)/i'],
            ['medium','DEPRECATED_PREG_E','Uso peligroso de preg_replace con modificador e','/preg_replace\s*\(\s*[\'\"][^\'\"]*\/e[imsxuADSUXJ]*[\'\"]/i'],
            ['medium','CREATE_FUNCTION','Creación dinámica de funciones','/\bcreate_function\s*\(/i'],
            ['medium','LONG_OBFUSCATED_PAYLOAD','Carga codificada extensa dentro de código','/(?:base64_decode|gzinflate|gzuncompress).{0,200}[A-Za-z0-9+\/]{1200,}={0,2}/is'],
        ];
        foreach($patterns as [$severity,$code,$label,$regex]){
            if(preg_match($regex,$sample,$m))$rules[]=['severity'=>$severity,'code'=>$code,'label'=>$label,'evidence'=>substr(preg_replace('/\s+/',' ',$m[0]),0,350)];
        }
        $head=substr($sample,0,8192);$hasPhp=stripos($head,'<?php')!==false||stripos($head,'<?=')!==false;
        if($hasPhp&&!in_array($ext,['php','phtml','php3','php4','php5','php7','php8','phar','inc'],true))$rules[]=['severity'=>'high','code'=>'PHP_DISGUISED','label'=>'Código PHP dentro de un archivo con otra extensión','evidence'=>$relative];
        return $rules;
    }

    private function storeFinding(int $scanId,string $path,string $sha256,int $size,int $mtime,array $rule): void
    {
        $st=$this->db->prepare("SELECT id,status FROM ah_security_findings WHERE path=? AND rule_code=? AND status IN('open','ignored','quarantined') ORDER BY id DESC LIMIT 1");$st->execute([$path,$rule['code']]);$existing=$st->fetch(PDO::FETCH_ASSOC);
        if($existing&&$existing['status']==='open'){
            $st=$this->db->prepare("UPDATE ah_security_findings SET scan_id=?,sha256=?,size_bytes=?,modified_at=?,severity=?,rule_label=?,evidence=?,updated_at=NOW() WHERE id=?");
            $st->execute([$scanId,$sha256,$size,date('Y-m-d H:i:s',$mtime),$rule['severity'],$rule['label'],$rule['evidence'],$existing['id']]);return;
        }
        if($existing&&in_array($existing['status'],['ignored','quarantined'],true))return;
        $st=$this->db->prepare("INSERT INTO ah_security_findings(scan_id,path,sha256,size_bytes,modified_at,severity,rule_code,rule_label,evidence,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,'open',NOW(),NOW())");
        $st->execute([$scanId,$path,$sha256,$size,date('Y-m-d H:i:s',$mtime),$rule['severity'],$rule['code'],$rule['label'],$rule['evidence']]);
    }

    private function baselineRow(string $path): ?array {$st=$this->db->prepare("SELECT * FROM ah_security_baseline WHERE path=? LIMIT 1");$st->execute([$path]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;}
    private function findingById(int $id): ?array {$st=$this->db->prepare("SELECT * FROM ah_security_findings WHERE id=? LIMIT 1");$st->execute([$id]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;}

    private function fileIterator(string $group): iterable
    {
        $directory=new RecursiveDirectoryIterator($this->root,FilesystemIterator::SKIP_DOTS);
        $filter=new RecursiveCallbackFilterIterator($directory,function(SplFileInfo $current)use($group):bool{
            $relative=$this->relativePath($current->getPathname());
            if($relative!==''&&$this->isExcluded($relative,$group))return false;
            return !$current->isLink();
        });
        $iterator=new RecursiveIteratorIterator($filter,RecursiveIteratorIterator::LEAVES_ONLY,RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach($iterator as $file)if($file instanceof SplFileInfo&&$file->isFile())yield $file;
    }

    private function relativePath(string $absolute): string
    {
        $absolute=str_replace('\\','/',$absolute);$root=str_replace('\\','/',$this->root);
        if(!str_starts_with($absolute,$root))return '';
        return ltrim(substr($absolute,strlen($root)),'/');
    }

    private function absoluteFromRelative(string $relative): string
    {
        $relative=ltrim(str_replace('\\','/',trim($relative)),'/');
        if($relative===''||str_contains($relative,'../')||$relative==='..')throw new RuntimeException('Ruta de archivo inválida.');
        $absolute=$this->root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);$parent=realpath(dirname($absolute));
        if($parent!==false&&!str_starts_with($parent,$this->root))throw new RuntimeException('La ruta sale de public_html.');
        return $absolute;
    }

    private function isExcluded(string $relative,string $group): bool
    {
        $relative=trim(str_replace('\\','/',$relative),'/');$list=(array)($this->config[$group]['exclude_paths']??$this->config['scan']['exclude_paths']??[]);
        foreach($list as $excluded){$excluded=trim(str_replace('\\','/',(string)$excluded),'/');if($excluded!==''&&($relative===$excluded||str_starts_with($relative,$excluded.'/')))return true;}
        return false;
    }

    private function safeTemporaryCandidates(int $minimumAgeHours): array
    {
        $items=[];$path='/tmp';
        if(!is_dir($path)||!is_readable($path))return $items;
        try{$iterator=new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS);}catch(Throwable $e){return $items;}
        $seen=0;
        foreach($iterator as $file){
            if(++$seen>10000)break;
            if(!$file instanceof SplFileInfo||!$file->isFile()||$file->isLink())continue;
            $candidate=$file->getPathname();
            if(!$this->isSafeTemporaryPath($candidate,$minimumAgeHours))continue;
            $items[]=['path'=>$candidate,'name'=>$file->getFilename(),'size'=>(int)$file->getSize(),'modified_at'=>$file->getMTime()];
        }
        return $items;
    }

    private function isSafeTemporaryPath(string $path,int $minimumAgeHours): bool
    {
        $directory=realpath(dirname($path));
        if($directory!=='/tmp'||!is_file($path)||is_link($path))return false;
        $name=basename($path);
        if(!preg_match('/^(?:codex_|accionhonduras_|ah_|php[a-z0-9._-]{4,})/i',$name))return false;
        $mtime=@filemtime($path);if($mtime===false||$mtime>time()-(max(1,$minimumAgeHours)*3600))return false;
        $owner=@fileowner($path);
        $effectiveOwner=function_exists('posix_geteuid')?@posix_geteuid():@fileowner($this->storage);
        return $owner!==false&&$effectiveOwner!==false&&(int)$owner===(int)$effectiveOwner;
    }

    private function looksLikeUploadPath(string $relative): bool
    {
        $relative='/'.mb_strtolower(str_replace('\\','/',$relative),'UTF-8').'/';
        foreach((array)($this->config['scan']['upload_like_paths']??[]) as $part){$part=trim(mb_strtolower((string)$part,'UTF-8'),'/');if($part!==''&&str_contains($relative,'/'.$part.'/'))return true;}
        return false;
    }

    private function suspiciousFilename(string $relative): bool
    {
        $name=mb_strtolower(basename($relative),'UTF-8');
        foreach(['/^(?:wso|c99|r57|b374k|alfa|shell|cmd|mailer|mass|priv8)\w*\.(?:php|phtml)$/i','/^(?:wp-vcd|class\.wp|wp-feed|wp-tmp|wp-cache-old)\.php$/i','/^\.[a-z0-9_-]{1,20}\.(?:php|phtml)$/i'] as $pattern)if(preg_match($pattern,$name))return true;
        return false;
    }

    private function isCodeFile(string $relative): bool {$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));return in_array($ext,$this->codeExtensions,true)||in_array(basename($relative),['.htaccess','.user.ini','.gitignore'],true);}
    private function isBackupFile(string $relative): bool {$name=basename($relative);if(in_array($name,(array)($this->config['backup']['special_files']??[]),true))return true;$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));return in_array($ext,(array)($this->config['backup']['extensions']??[]),true);}
    private function deduplicateRules(array $rules): array {$seen=[];$out=[];foreach($rules as $rule){$key=(string)($rule['code']??'');if($key===''||isset($seen[$key]))continue;$seen[$key]=true;$out[]=$rule;}return $out;}

    private function pruneBackups(): void
    {
        $keep=max(1,(int)($this->config['backup']['keep_last']??12));
        $rows=$this->db->query("SELECT id,path FROM ah_security_backups WHERE status='ready' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach(array_slice($rows,$keep) as $row){if(is_file($row['path']))@unlink($row['path']);$this->db->prepare("UPDATE ah_security_backups SET status='pruned' WHERE id=?")->execute([(int)$row['id']]);}
    }

    private function ensureGitIgnore(): void
    {
        $path=$this->root.'/.gitignore';$required=['/security/config.local.php','/accion_security_storage/','/security/*.log'];$content=is_file($path)?(string)file_get_contents($path):'';$changed=false;
        foreach($required as $line){if(!preg_match('/^'.preg_quote($line,'/').'$/m',$content)){$content.=($content!==''&&!str_ends_with($content,"\n")?"\n":'').$line."\n";$changed=true;}}
        if($changed)file_put_contents($path,$content);
    }

    private function gitBinary(): string {return (string)($this->config['git']['binary']??'git');}
    private function canExecute(): bool
    {
        if(!function_exists('proc_open'))return false;
        $disabled=array_map('trim',explode(',',(string)ini_get('disable_functions')));
        return !in_array('proc_open',$disabled,true);
    }

    private function runCommand(array $command,string $cwd,int $timeout,array $extraEnv=[]): array
    {
        if(!$this->canExecute())return ['code'=>127,'stdout'=>'','stderr'=>'proc_open está deshabilitado'];
        $cmd=implode(' ',array_map('escapeshellarg',array_map('strval',$command)));
        $env=null;if($extraEnv){$env=getenv();if(!is_array($env))$env=[];foreach($extraEnv as $k=>$v)$env[(string)$k]=(string)$v;}
        $process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,$env);
        if(!is_resource($process))return ['code'=>127,'stdout'=>'','stderr'=>'No fue posible iniciar el proceso'];
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $stdout='';$stderr='';$start=microtime(true);$timedOut=false;
        while(true){$status=proc_get_status($process);$stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);if(!$status['running'])break;if((microtime(true)-$start)>$timeout){$timedOut=true;proc_terminate($process,9);break;}usleep(100000);}
        $stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        if($timedOut){$code=124;$stderr.="\nProceso cancelado por superar {$timeout} segundos.";}
        return ['code'=>$code,'stdout'=>$stdout,'stderr'=>$stderr];
    }

    private function acquireLock(string $name)
    {
        $path=$this->storage.'/locks/'.preg_replace('/[^a-z0-9_-]/i','_',$name).'.lock';$handle=fopen($path,'c+');
        if(!$handle||!flock($handle,LOCK_EX|LOCK_NB)){if(is_resource($handle))fclose($handle);throw new RuntimeException("Ya existe un proceso activo: $name.");}
        ftruncate($handle,0);fwrite($handle,(string)getmypid());return $handle;
    }
    private function releaseLock($handle): void {if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);}}

    private function logAction(string $user,string $action,string $target,string $status,array $details): void
    {
        try{$st=$this->db->prepare("INSERT INTO ah_security_actions(user_label,action,target,status,details_json,created_at) VALUES(?,?,?,?,?,NOW())");$st->execute([$user,$action,$target,$status,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}catch(Throwable $e){}
    }

    private function notify(string $subject,string $message): void
    {
        $emails=array_filter(array_map('trim',(array)($this->config['notifications']['emails']??[])));if(!$emails)return;
        $from=trim((string)($this->config['notifications']['from']??''));$headers=$from!==''?"From: $from\r\nContent-Type: text/plain; charset=UTF-8":'';
        foreach($emails as $email)if(filter_var($email,FILTER_VALIDATE_EMAIL))@mail($email,$subject,$message,$headers);
    }
}
