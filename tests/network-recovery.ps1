param(
    [Parameter(Mandatory)][string]$MysqlBin,
    [string]$ScratchRoot = [IO.Path]::GetTempPath()
)
$ErrorActionPreference = 'Stop'
$MysqlBin = (Resolve-Path -LiteralPath $MysqlBin).Path
$repo = Split-Path -Parent $PSScriptRoot
$run = New-Item -ItemType Directory -Path (Join-Path $ScratchRoot ('erp-recovery-' + [guid]::NewGuid().ToString('N')))
$phpExe = (Get-Command php).Source
$manifest = Join-Path $run 'documents.json'
$backup = Join-Path $run 'dispatch.sql'
$dbName = 'erp_network_recovery_test'
$dbProcess = $null
$httpProcess = $null

function Free-Port {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
    $listener.Start()
    try { $listener.LocalEndpoint.Port } finally { $listener.Stop() }
}
function Initialize-Db($data) {
    New-Item -ItemType Directory -Path $data | Out-Null
    & "$MysqlBin/mysqld.exe" --no-defaults "--datadir=$data" --initialize-insecure "--log-error=$data/init.log"
    if ($LASTEXITCODE) { throw "MySQL initialization failed in $data" }
}
function Start-Db($data,$port) {
    $script:dbProcess = Start-Process -FilePath "$MysqlBin/mysqld.exe" -ArgumentList @('--no-defaults', ('"--datadir=' + $data + '"'), ('"--log-error=' + $data + '/server.log"'), "--port=$port",'--bind-address=127.0.0.1','--mysqlx=0') -WindowStyle Hidden -PassThru
    $script:clientArgs = @('--no-defaults','--protocol=tcp','-h','127.0.0.1',"-P$port",'-u','root')
    $env:ERP_DSN = "mysql:host=127.0.0.1;port=$port;dbname=$dbName;charset=utf8mb4"
    foreach ($attempt in 1..100) {
        if ($dbProcess.HasExited) { throw "MySQL exited. See $data/server.log" }
        & "$MysqlBin/mysql.exe" @clientArgs --connect-timeout=1 -Nse 'SELECT 1' 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { return }
        Start-Sleep -Milliseconds 250
    }
    throw 'MySQL startup timed out'
}
function Start-Web($stage) {
    $webPort = Free-Port
    $env:ERP_HTTP_URL = "http://127.0.0.1:$webPort"
    $script:httpProcess = Start-Process -FilePath $phpExe -ArgumentList @('-d','extension=pdo_mysql','-d','display_errors=0','-S',"127.0.0.1:$webPort",'-t','public') -WorkingDirectory $repo -RedirectStandardError "$run/http-$stage.log" -WindowStyle Hidden -PassThru
    foreach ($attempt in 1..40) {
        if ($httpProcess.HasExited) { throw "PHP exited during $stage" }
        try { if ((Invoke-RestMethod "$env:ERP_HTTP_URL/health.php" -TimeoutSec 1).status -eq 'ready') { return } } catch {}
        Start-Sleep -Milliseconds 250
    }
    throw "HTTP health check failed during $stage"
}
function Stop-Web {
    if ($null -ne $httpProcess -and !$httpProcess.HasExited) {
        Stop-Process -InputObject $httpProcess
        $httpProcess.WaitForExit()
    }
}
function Run-Phase($phase) {
    $response = & $phpExe -d extension=pdo_mysql "$PSScriptRoot/network-recovery.php" $phase $manifest
    if ($LASTEXITCODE) { throw "Recovery check failed during $phase" }
    $response | ConvertFrom-Json
}
$env:ERP_DB_USER = 'root'
$env:ERP_DB_PASSWORD = ''
$env:ERP_TEST_DATABASE = '1'
$env:ERP_SECURE_COOKIES = '0'
Write-Host "Recovery files: $run"
try {
    $source = Join-Path $run 'source'
    Initialize-Db $source
    Start-Db $source (Free-Port)
    & "$MysqlBin/mysql.exe" @clientArgs -e "CREATE DATABASE $dbName CHARACTER SET utf8mb4"
    if ($LASTEXITCODE) { throw 'Source database creation failed' }
    & $phpExe -d extension=pdo_mysql "$repo/bin/erp.php" migrate | Out-Host
    if ($LASTEXITCODE) { throw 'Source migration failed' }
    Start-Web 'source'
    $dispatched = Run-Phase 'dispatch'
    & "$MysqlBin/mysqldump.exe" @clientArgs --single-transaction --no-tablespaces --set-gtid-purged=OFF "--result-file=$backup" $dbName
    if ($LASTEXITCODE) { throw 'Backup failed' }
    Stop-Web
    & "$MysqlBin/mysqladmin.exe" @clientArgs shutdown
    if ($LASTEXITCODE) { throw 'Source shutdown failed' }
    $dbProcess.WaitForExit()

    $target = Join-Path $run 'restored'
    $restoredPort = Free-Port
    Initialize-Db $target
    Start-Db $target $restoredPort
    & "$MysqlBin/mysql.exe" @clientArgs -e "CREATE DATABASE $dbName CHARACTER SET utf8mb4"
    if ($LASTEXITCODE) { throw 'Target database creation failed' }
    $sqlPath = $backup.Replace('\','/')
    & "$MysqlBin/mysql.exe" @clientArgs $dbName -e "SOURCE $sqlPath"
    if ($LASTEXITCODE) { throw 'Target restore failed' }
    Start-Web 'restored'
    $received = Run-Phase 'receive'
    Stop-Process -InputObject $dbProcess -Force
    $dbProcess.WaitForExit()
    Stop-Web
    Start-Db $target $restoredPort
    Start-Web 'restart'
    $replayed = Run-Phase 'replay'
    $report = [ordered]@{
        mysql = (& "$MysqlBin/mysql.exe" @clientArgs -Nse 'SELECT VERSION()')
        backup_sha256 = (Get-FileHash $backup).Hash
        fresh_instance_restored = $true
        database_process_killed_after_commit = $true
        dispatch = $dispatched
        receive = $received
        retry = $replayed
    } | ConvertTo-Json -Depth 12
    $report | Set-Content -LiteralPath "$run/result.json" -Encoding utf8
    Write-Output $report
} finally {
    Stop-Web
    if ($null -ne $dbProcess -and !$dbProcess.HasExited) {
        & "$MysqlBin/mysqladmin.exe" @clientArgs --connect-timeout=2 shutdown
        if (!$dbProcess.WaitForExit(5000)) { Stop-Process -InputObject $dbProcess -Force }
    }
}
