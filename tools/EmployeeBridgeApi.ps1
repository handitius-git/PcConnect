param(
    [string]$ConfigPath = ".\EmployeeBridgeApi-Config.json"
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"

function Load-BridgeConfig {
    param([string]$Path)
    if (!(Test-Path -LiteralPath $Path)) {
        throw "Config tidak ditemukan: $Path"
    }
    $config = Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
    foreach ($item in @(
        @{ Name = "SqlPort"; Value = 1433 },
        @{ Name = "LimitRows"; Value = 500 },
        @{ Name = "DepartmentField"; Value = "" },
        @{ Name = "ApiToken"; Value = "" },
        @{ Name = "TrustServerCertificate"; Value = $true }
    )) {
        if ($null -eq $config.PSObject.Properties[$item.Name]) {
            $config | Add-Member -NotePropertyName $item.Name -NotePropertyValue $item.Value -Force
        }
    }
    foreach ($name in @("ListenUrl", "SqlServer", "Database", "Username", "Password", "TableName", "NikField", "NameField")) {
        if ([string]::IsNullOrWhiteSpace([string]$config.$name)) {
            throw "Config $name wajib diisi."
        }
    }
    return $config
}

function Quote-SqlIdentifier {
    param([string]$Identifier)
    $Identifier = $Identifier.Trim()
    if ($Identifier -notmatch '^[A-Za-z0-9_ -]+(\.[A-Za-z0-9_ -]+)?$') {
        throw "Nama table/field SQL Server tidak valid: $Identifier"
    }
    $parts = $Identifier -split '\.'
    return (($parts | ForEach-Object { "[" + ($_ -replace ']', ']]') + "]" }) -join ".")
}

function New-SqlConnection {
    param($Config)
    Add-Type -AssemblyName System.Data
    $builder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
    $builder["Data Source"] = ([string]$Config.SqlServer) + "," + ([int]$Config.SqlPort)
    $builder["Initial Catalog"] = [string]$Config.Database
    $builder["User ID"] = [string]$Config.Username
    $builder["Password"] = [string]$Config.Password
    $builder["Encrypt"] = $true
    $builder["TrustServerCertificate"] = [bool]$Config.TrustServerCertificate
    $builder["Connect Timeout"] = 10
    return New-Object System.Data.SqlClient.SqlConnection($builder.ConnectionString)
}

function Invoke-EmployeeQuery {
    param(
        $Config,
        [int]$Limit = 100
    )
    $limitMax = [Math]::Max(1, [Math]::Min([int]$Config.LimitRows, 2000))
    $limit = [Math]::Max(1, [Math]::Min($Limit, $limitMax))
    $table = Quote-SqlIdentifier ([string]$Config.TableName)
    $nikField = Quote-SqlIdentifier ([string]$Config.NikField)
    $nameField = Quote-SqlIdentifier ([string]$Config.NameField)
    $departmentRaw = [string]$Config.DepartmentField
    if ([string]::IsNullOrWhiteSpace($departmentRaw)) {
        $departmentSelect = ", CAST('' AS NVARCHAR(200)) AS department"
    } else {
        $departmentSelect = ", CAST(" + (Quote-SqlIdentifier $departmentRaw) + " AS NVARCHAR(200)) AS department"
    }
    $sql = "SELECT TOP (@Limit) CAST($nikField AS NVARCHAR(100)) AS nik, CAST($nameField AS NVARCHAR(200)) AS name$departmentSelect FROM $table WHERE $nikField IS NOT NULL AND $nameField IS NOT NULL ORDER BY $nameField"
    $conn = New-SqlConnection $Config
    try {
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = $sql
        [void]$cmd.Parameters.Add("@Limit", [System.Data.SqlDbType]::Int)
        $cmd.Parameters["@Limit"].Value = $limit
        $reader = $cmd.ExecuteReader()
        $rows = @()
        while ($reader.Read()) {
            $rows += [pscustomobject]@{
                nik = [string]$reader["nik"]
                name = [string]$reader["name"]
                department = [string]$reader["department"]
            }
        }
        return $rows
    } finally {
        if ($conn.State -ne "Closed") { $conn.Close() }
    }
}

function Invoke-EmployeeByNik {
    param(
        $Config,
        [string]$Nik
    )
    $table = Quote-SqlIdentifier ([string]$Config.TableName)
    $nikField = Quote-SqlIdentifier ([string]$Config.NikField)
    $nameField = Quote-SqlIdentifier ([string]$Config.NameField)
    $departmentRaw = [string]$Config.DepartmentField
    if ([string]::IsNullOrWhiteSpace($departmentRaw)) {
        $departmentSelect = ", CAST('' AS NVARCHAR(200)) AS department"
    } else {
        $departmentSelect = ", CAST(" + (Quote-SqlIdentifier $departmentRaw) + " AS NVARCHAR(200)) AS department"
    }
    $sql = "SELECT TOP (1) CAST($nikField AS NVARCHAR(100)) AS nik, CAST($nameField AS NVARCHAR(200)) AS name$departmentSelect FROM $table WHERE CAST($nikField AS NVARCHAR(100)) = @Nik"
    $conn = New-SqlConnection $Config
    try {
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = $sql
        [void]$cmd.Parameters.Add("@Nik", [System.Data.SqlDbType]::NVarChar, 100)
        $cmd.Parameters["@Nik"].Value = $Nik
        $reader = $cmd.ExecuteReader()
        if ($reader.Read()) {
            return [pscustomobject]@{
                nik = [string]$reader["nik"]
                name = [string]$reader["name"]
                department = [string]$reader["department"]
            }
        }
        return $null
    } finally {
        if ($conn.State -ne "Closed") { $conn.Close() }
    }
}

function Write-Json {
    param($Context, [int]$StatusCode, $Payload)
    $Context.Response.StatusCode = $StatusCode
    $Context.Response.ContentType = "application/json; charset=utf-8"
    $bytes = [System.Text.Encoding]::UTF8.GetBytes(($Payload | ConvertTo-Json -Depth 8))
    $Context.Response.ContentLength64 = $bytes.Length
    $Context.Response.OutputStream.Write($bytes, 0, $bytes.Length)
    $Context.Response.OutputStream.Close()
}

function Test-Token {
    param($Context, $Config)
    $token = [string]$Config.ApiToken
    if ([string]::IsNullOrWhiteSpace($token)) { return $true }
    return ($Context.Request.Headers["X-Employee-Bridge-Token"] -eq $token)
}

$config = Load-BridgeConfig $ConfigPath
$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add([string]$config.ListenUrl)
$listener.Start()
Write-Host "PcConnect Employee Bridge API running at $($config.ListenUrl)"
Write-Host "Endpoints: /health, /employees?limit=5, /employee?nik=NIK"
Write-Host "Tekan Ctrl+C untuk stop."

while ($listener.IsListening) {
    $context = $listener.GetContext()
    try {
        if (!(Test-Token $context $config)) {
            Write-Json $context 401 @{ ok = $false; error = "Token tidak valid." }
            continue
        }
        $path = $context.Request.Url.AbsolutePath.TrimEnd("/")
        if ($path -eq "" -or $path -eq "/health") {
            Write-Json $context 200 @{ ok = $true; service = "PcConnect Employee Bridge"; time = (Get-Date).ToString("s") }
            continue
        }
        if ($path -eq "/employees") {
            $limit = 100
            if ($context.Request.QueryString["limit"]) { $limit = [int]$context.Request.QueryString["limit"] }
            $data = Invoke-EmployeeQuery $config $limit
            Write-Json $context 200 @{ ok = $true; count = $data.Count; data = $data }
            continue
        }
        if ($path -eq "/employee") {
            $nik = [string]$context.Request.QueryString["nik"]
            if ([string]::IsNullOrWhiteSpace($nik)) {
                Write-Json $context 400 @{ ok = $false; error = "Parameter nik wajib diisi." }
                continue
            }
            $row = Invoke-EmployeeByNik $config $nik
            if ($null -eq $row) {
                Write-Json $context 404 @{ ok = $false; error = "Karyawan tidak ditemukan." }
            } else {
                Write-Json $context 200 @{ ok = $true; data = $row }
            }
            continue
        }
        Write-Json $context 404 @{ ok = $false; error = "Endpoint tidak ditemukan." }
    } catch {
        Write-Json $context 500 @{ ok = $false; error = $_.Exception.Message }
    }
}
