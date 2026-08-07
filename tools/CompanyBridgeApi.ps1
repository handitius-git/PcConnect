param(
    [string]$ConfigPath = "$PSScriptRoot\CompanyBridgeApi-Config.json",
    [switch]$RunOnly,
    [switch]$LoadOnly
)

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

function New-DefaultConfig {
    [ordered]@{
        ListenUrl = "http://+:8788/"
        ApiToken = ""
        SqlServerHost = "192.168.1.107"
        SqlPort = "1433"
        Database = ""
        Username = "sa"
        Password = ""
        TrustServerCertificate = $true
        TableName = "dbo.mst_pt"
        FieldId = "pt_id"
        FieldName = "pt_name"
        LimitRows = 500
    }
}

function Load-BridgeConfig {
    param([string]$Path)
    if (!(Test-Path -LiteralPath $Path)) {
        $cfg = New-DefaultConfig
        Save-BridgeConfig -Path $Path -Config $cfg
        return $cfg
    }
    try {
        $raw = Get-Content -LiteralPath $Path -Raw
        if ([string]::IsNullOrWhiteSpace($raw)) { throw "Config kosong" }
        $json = $raw | ConvertFrom-Json
        $cfg = New-DefaultConfig
        foreach ($p in $json.PSObject.Properties) {
            if ($cfg.Contains($p.Name)) { $cfg[$p.Name] = $p.Value }
        }
        return $cfg
    } catch {
        throw "Gagal membaca config JSON: $($_.Exception.Message). File: $Path"
    }
}

function Save-BridgeConfig {
    param([string]$Path, [hashtable]$Config)
    $Config | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $Path -Encoding UTF8
}

function Is-SafeIdentifier {
    param([string]$Value, [bool]$AllowDot = $false)
    if ($AllowDot) { return $Value -match '^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+){0,2}$' }
    return $Value -match '^[A-Za-z0-9_]+$'
}

function Quote-SqlName {
    param([string]$Value, [bool]$AllowDot = $false)
    if (!(Is-SafeIdentifier -Value $Value -AllowDot $AllowDot)) {
        throw "Nama table/field tidak valid: $Value"
    }
    (($Value -split '\.') | ForEach-Object { '[' + ($_ -replace ']', ']]') + ']' }) -join '.'
}

function New-SqlConnection {
    param([hashtable]$Config)
    $server = $Config.SqlServerHost
    if ($Config.SqlPort -and $Config.SqlPort -ne "0") { $server = "$server,$($Config.SqlPort)" }
    $builder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
    $builder["Data Source"] = $server
    $builder["Initial Catalog"] = [string]$Config.Database
    $builder["User ID"] = [string]$Config.Username
    $builder["Password"] = [string]$Config.Password
    $builder["Integrated Security"] = $false
    $builder["Connect Timeout"] = 15
    if ($Config.TrustServerCertificate) {
        try { $builder["TrustServerCertificate"] = $true } catch {}
    }
    $conn = New-Object System.Data.SqlClient.SqlConnection $builder.ConnectionString
    $conn.Open()
    return $conn
}

function Get-Companies {
    param([hashtable]$Config, [int]$Limit)
    $limit = [Math]::Max(1, [Math]::Min(20000, $Limit))
    $table = Quote-SqlName -Value ([string]$Config.TableName) -AllowDot $true
    $idField = Quote-SqlName -Value ([string]$Config.FieldId)
    $nameField = Quote-SqlName -Value ([string]$Config.FieldName)
    $sql = "SELECT TOP ($limit) CAST($idField AS NVARCHAR(80)) AS external_company_id, CAST($nameField AS NVARCHAR(180)) AS company_name FROM $table WHERE $idField IS NOT NULL AND $nameField IS NOT NULL ORDER BY $nameField"
    $conn = New-SqlConnection -Config $Config
    try {
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = $sql
        $reader = $cmd.ExecuteReader()
        $rows = New-Object System.Collections.Generic.List[object]
        while ($reader.Read()) {
            $rows.Add([ordered]@{
                external_company_id = [string]$reader["external_company_id"]
                company_name = [string]$reader["company_name"]
            })
        }
        $reader.Close()
        return $rows
    } finally {
        $conn.Close()
    }
}

function Send-Json {
    param($Context, [int]$StatusCode, $Object)
    $json = $Object | ConvertTo-Json -Depth 6
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $Context.Response.StatusCode = $StatusCode
    $Context.Response.ContentType = "application/json; charset=utf-8"
    $Context.Response.OutputStream.Write($bytes, 0, $bytes.Length)
    $Context.Response.Close()
}

function Token-Valid {
    param($Request, [hashtable]$Config)
    $expected = [string]$Config.ApiToken
    if ([string]::IsNullOrWhiteSpace($expected)) { return $true }
    $token = [string]$Request.Headers["X-Api-Token"]
    if ([string]::IsNullOrWhiteSpace($token)) { $token = [string]$Request.QueryString["token"] }
    return $token -eq $expected
}

function Start-Bridge {
    param([hashtable]$Config)
    $listener = New-Object System.Net.HttpListener
    $listener.Prefixes.Add([string]$Config.ListenUrl)
    $listener.Start()
    Write-Host "PcConnect Company Bridge running at $($Config.ListenUrl)"
    Write-Host "Health: /health, Companies: /companies?limit=5"
    while ($listener.IsListening) {
        $ctx = $listener.GetContext()
        try {
            if (!(Token-Valid -Request $ctx.Request -Config $Config)) {
                Send-Json $ctx 401 @{ ok = $false; error = "Token tidak valid." }
                continue
            }
            $path = $ctx.Request.Url.AbsolutePath.ToLowerInvariant()
            if ($path -eq "/health") {
                Send-Json $ctx 200 @{ ok = $true; service = "PcConnect Company Bridge"; time = (Get-Date).ToString("s") }
                continue
            }
            if ($path -eq "/companies") {
                $limit = 500
                if ($ctx.Request.QueryString["limit"]) { [void][int]::TryParse($ctx.Request.QueryString["limit"], [ref]$limit) }
                $rows = Get-Companies -Config $Config -Limit $limit
                Send-Json $ctx 200 @{ ok = $true; count = $rows.Count; data = $rows }
                continue
            }
            Send-Json $ctx 404 @{ ok = $false; error = "Endpoint tidak ditemukan." }
        } catch {
            Send-Json $ctx 500 @{ ok = $false; error = $_.Exception.Message }
        }
    }
}

function Open-Firewall {
    param([string]$Port)
    Start-Process powershell.exe -Verb RunAs -ArgumentList "-NoProfile -ExecutionPolicy Bypass -Command `"New-NetFirewallRule -DisplayName 'PcConnect Company Bridge $Port' -Direction Inbound -Action Allow -Protocol TCP -LocalPort $Port -ErrorAction SilentlyContinue`""
}

function Show-SetupForm {
    $cfg = Load-BridgeConfig -Path $ConfigPath
    $form = New-Object System.Windows.Forms.Form
    $form.Text = "PcConnect Company Bridge API - Windows Setup"
    $form.Size = New-Object System.Drawing.Size(760, 680)
    $form.StartPosition = "CenterScreen"

    $labels = @(
        "Listen URL", "API Token", "SQL Server IP / Host", "SQL Port", "Database",
        "Username", "Password", "Table / View", "Field ID", "Field Nama Unit Usaha", "Limit Rows"
    )
    $keys = @(
        "ListenUrl", "ApiToken", "SqlServerHost", "SqlPort", "Database",
        "Username", "Password", "TableName", "FieldId", "FieldName", "LimitRows"
    )
    $textboxes = @{}
    $y = 22
    $title = New-Object System.Windows.Forms.Label
    $title.Text = "PcConnect Company Bridge API"
    $title.Font = New-Object System.Drawing.Font("Segoe UI", 14, [System.Drawing.FontStyle]::Bold)
    $title.Location = New-Object System.Drawing.Point(24, $y)
    $title.Size = New-Object System.Drawing.Size(650, 30)
    $form.Controls.Add($title)
    $y += 48
    for ($i = 0; $i -lt $labels.Count; $i++) {
        $lbl = New-Object System.Windows.Forms.Label
        $lbl.Text = $labels[$i]
        $lbl.Location = New-Object System.Drawing.Point(28, $y)
        $lbl.Size = New-Object System.Drawing.Size(180, 22)
        $form.Controls.Add($lbl)
        $tb = New-Object System.Windows.Forms.TextBox
        $tb.Location = New-Object System.Drawing.Point(220, $y - 3)
        $tb.Size = New-Object System.Drawing.Size(390, 24)
        $tb.Text = [string]$cfg[$keys[$i]]
        if ($keys[$i] -in @("ApiToken", "Password")) { $tb.UseSystemPasswordChar = $true }
        $form.Controls.Add($tb)
        $textboxes[$keys[$i]] = $tb
        $y += 35
    }
    $trust = New-Object System.Windows.Forms.CheckBox
    $trust.Text = "Trust SQL Server Certificate"
    $trust.Location = New-Object System.Drawing.Point(220, $y)
    $trust.Size = New-Object System.Drawing.Size(260, 24)
    $trust.Checked = [bool]$cfg.TrustServerCertificate
    $form.Controls.Add($trust)
    $y += 42
    $status = New-Object System.Windows.Forms.Label
    $status.Text = "Config: $ConfigPath"
    $status.Location = New-Object System.Drawing.Point(28, $y)
    $status.Size = New-Object System.Drawing.Size(650, 24)
    $form.Controls.Add($status)

    function Read-FormConfig {
        $new = New-DefaultConfig
        foreach ($key in $keys) { $new[$key] = $textboxes[$key].Text }
        $new.TrustServerCertificate = $trust.Checked
        return $new
    }

    $btnY = $y + 36
    $buttons = @(
        @{ Text = "Simpan Config"; X = 28; Action = {
            $new = Read-FormConfig
            Save-BridgeConfig -Path $ConfigPath -Config $new
            [System.Windows.Forms.MessageBox]::Show("Config tersimpan.`n$ConfigPath", "PcConnect Company Bridge")
        }},
        @{ Text = "Start Bridge"; X = 170; Action = {
            $new = Read-FormConfig
            Save-BridgeConfig -Path $ConfigPath -Config $new
            Start-Process powershell.exe -WindowStyle Hidden -ArgumentList @("-NoProfile", "-ExecutionPolicy", "Bypass", "-File", $PSCommandPath, "-ConfigPath", $ConfigPath, "-RunOnly")
            [System.Windows.Forms.MessageBox]::Show("Bridge dijalankan. URL PcConnect: http://IP-WINDOWS:8788", "PcConnect Company Bridge")
        }},
        @{ Text = "Test Health"; X = 300; Action = {
            try {
                $new = Read-FormConfig
                $base = ([string]$new.ListenUrl).Replace("+", "127.0.0.1").TrimEnd("/")
                $headers = @{}
                if ($new.ApiToken) { $headers["X-Api-Token"] = $new.ApiToken }
                $r = Invoke-RestMethod -Uri "$base/health" -Headers $headers -TimeoutSec 5
                [System.Windows.Forms.MessageBox]::Show(($r | ConvertTo-Json -Depth 4), "Bridge OK")
            } catch { [System.Windows.Forms.MessageBox]::Show($_.Exception.Message, "Test gagal") }
        }},
        @{ Text = "Test Companies"; X = 430; Action = {
            try {
                $new = Read-FormConfig
                $rows = Get-Companies -Config $new -Limit 5
                [System.Windows.Forms.MessageBox]::Show((@{ ok=$true; count=$rows.Count; data=$rows } | ConvertTo-Json -Depth 5), "SQL Server OK")
            } catch { [System.Windows.Forms.MessageBox]::Show($_.Exception.Message, "SQL Test gagal") }
        }},
        @{ Text = "Buka Firewall"; X = 580; Action = {
            $new = Read-FormConfig
            $uri = [System.Uri](([string]$new.ListenUrl).Replace("+", "127.0.0.1"))
            Open-Firewall -Port ([string]$uri.Port)
        }}
    )
    foreach ($b in $buttons) {
        $btn = New-Object System.Windows.Forms.Button
        $btn.Text = $b.Text
        $btn.Location = New-Object System.Drawing.Point($b.X, $btnY)
        $btn.Size = New-Object System.Drawing.Size(125, 32)
        $action = $b.Action
        $btn.Add_Click($action)
        $form.Controls.Add($btn)
    }
    [void]$form.ShowDialog()
}

if ($RunOnly) {
    $cfg = Load-BridgeConfig -Path $ConfigPath
    Start-Bridge -Config $cfg
    return
}

if ($LoadOnly) {
    Load-BridgeConfig -Path $ConfigPath
    return
}

Show-SetupForm
