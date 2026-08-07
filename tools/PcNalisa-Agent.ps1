param(
    [Parameter(Mandatory=$false)][string]$PcID = "",
    [Parameter(Mandatory=$false)][string]$Owner = "",
    [Parameter(Mandatory=$false)][string]$ServerUrl = "",
    [Parameter(Mandatory=$false)][string]$Token = "",
    [Parameter(Mandatory=$false)][switch]$StressTest,
    [Parameter(Mandatory=$false)][int]$StressSeconds = 30
)

$ErrorActionPreference = "SilentlyContinue"

$EmbeddedPcID = "__PCCONNECT_PC_ID__"
$EmbeddedOwner = "__PCCONNECT_OWNER__"
$EmbeddedServerUrl = "__PCCONNECT_SERVER_URL__"
$EmbeddedToken = "__PCCONNECT_TOKEN__"
$LogPath = Join-Path $PSScriptRoot ("PcNalisa-" + (Get-Date -Format "yyyyMMdd-HHmmss") + ".log")

function Write-Status {
    param([string]$Message)
    $line = "[" + (Get-Date -Format "yyyy-MM-dd HH:mm:ss") + "] " + $Message
    Add-Content -Path $LogPath -Value $line -Encoding UTF8
    Write-Host $line
}

function Show-Notice {
    param([string]$Message, [string]$Title = "PcNalisa", [string]$Icon = "Information")
    Add-Type -AssemblyName System.Windows.Forms | Out-Null
    [System.Windows.Forms.MessageBox]::Show($Message, $Title, [System.Windows.Forms.MessageBoxButtons]::OK, $Icon) | Out-Null
}

function Use-ValueIfEmpty {
    param([string]$Current, [string]$Fallback)
    if ([string]::IsNullOrWhiteSpace($Current) -and -not [string]::IsNullOrWhiteSpace($Fallback) -and $Fallback -notlike "__PCCONNECT_*__") {
        return $Fallback
    }
    return $Current
}

$configPath = Join-Path $PSScriptRoot "PcNalisa.config.json"
if (Test-Path $configPath) {
    $config = Get-Content $configPath -Raw | ConvertFrom-Json
    $PcID = Use-ValueIfEmpty $PcID $config.pc_id
    $Owner = Use-ValueIfEmpty $Owner $config.owner
    $ServerUrl = Use-ValueIfEmpty $ServerUrl $config.server_url
    $Token = Use-ValueIfEmpty $Token $config.token
}

$PcID = Use-ValueIfEmpty $PcID $EmbeddedPcID
$Owner = Use-ValueIfEmpty $Owner $EmbeddedOwner
$ServerUrl = Use-ValueIfEmpty $ServerUrl $EmbeddedServerUrl
$Token = Use-ValueIfEmpty $Token $EmbeddedToken

if ([string]::IsNullOrWhiteSpace($PcID) -or [string]::IsNullOrWhiteSpace($ServerUrl) -or [string]::IsNullOrWhiteSpace($Token)) {
    Write-Status "ERROR: PcNalisa belum dikonfigurasi."
    Show-Notice "PcNalisa belum dikonfigurasi. Download dari halaman Detail PC di PcConnect atau isi PcNalisa.config.json." "PcNalisa Error" "Error"
    exit 2
}

function Get-OfficeApps {
    $paths = @(
        "HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\*",
        "HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*"
    )
    Get-ItemProperty $paths |
        Where-Object { $_.DisplayName -match "Microsoft 365|Microsoft Office|LibreOffice|WPS Office|OpenOffice" } |
        Select-Object DisplayName, DisplayVersion, Publisher
}

function Get-Antivirus {
    Get-CimInstance -Namespace root/SecurityCenter2 -ClassName AntivirusProduct |
        Select-Object displayName, productState, pathToSignedProductExe
}

function Get-Storage {
    Get-CimInstance Win32_DiskDrive | Select-Object Model, MediaType, InterfaceType, Size, Status
}

function Get-GpuInfo {
    Get-CimInstance Win32_VideoController |
        Select-Object Name, DriverVersion, VideoProcessor, AdapterRAM, CurrentHorizontalResolution, CurrentVerticalResolution, Status
}

function Get-SignatureInfo {
    param([string]$Path)
    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path $Path)) {
        return [ordered]@{ Status = "Unknown"; Publisher = ""; Subject = "" }
    }
    $sig = Get-AuthenticodeSignature -FilePath $Path
    $publisher = ""
    $subject = ""
    if ($sig.SignerCertificate) {
        $publisher = $sig.SignerCertificate.GetNameInfo([System.Security.Cryptography.X509Certificates.X509NameType]::SimpleName, $false)
        $subject = $sig.SignerCertificate.Subject
    }
    [ordered]@{
        Status = [string]$sig.Status
        Publisher = $publisher
        Subject = $subject
    }
}

function Get-FilePublisher {
    param([string]$Path)
    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path $Path)) {
        return ""
    }
    $info = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($Path)
    if ($info.CompanyName) { return $info.CompanyName }
    return ""
}

function Get-ExecutableFromCommand {
    param([string]$Command)
    $cmd = [string]$Command
    if ([string]::IsNullOrWhiteSpace($cmd)) { return "" }
    $cmd = $cmd.Trim()
    if ($cmd.StartsWith('"')) {
        $end = $cmd.IndexOf('"', 1)
        if ($end -gt 1) { return $cmd.Substring(1, $end - 1) }
    }
    $match = [regex]::Match($cmd, '(?i)([a-z]:\\[^"\s]+?\.(exe|ps1|bat|cmd|vbs|js|msi|dll))')
    if ($match.Success) { return $match.Groups[1].Value }
    return ($cmd -split '\s+')[0]
}

function Get-RiskAssessment {
    param([string]$Name, [string]$Command, [string]$Path, [string]$Publisher, [string]$SignatureStatus, [string]$Source)
    $score = 0
    $reasons = @()
    $cmd = [string]$Command
    $pathText = [string]$Path
    $publisherText = [string]$Publisher

    if ($pathText -match "\\AppData\\Local\\Temp\\" -or $pathText -match "\\Windows\\Temp\\") { $score += 35; $reasons += "Berjalan dari folder temp" }
    if ($pathText -match "\\AppData\\Roaming\\" -and $Name -notmatch "OneDrive|Teams|Spotify|Telegram|WhatsApp|Zoom") { $score += 20; $reasons += "Berjalan dari AppData Roaming" }
    if ($pathText -match "\\Users\\Public\\" -or $pathText -match "\\ProgramData\\") { $score += 12; $reasons += "Berjalan dari folder publik/program data" }
    if ($cmd -match "(?i)powershell.+(-enc|-encodedcommand)|frombase64string|downloadstring|invoke-webrequest|iwr\s|bitsadmin|certutil.+-urlcache|regsvr32.+http|mshta.+http|wscript|cscript|rundll32.+javascript") { $score += 45; $reasons += "Command line sering dipakai malware/script downloader" }
    if ($cmd -match "(?i)http://|https://|ftp://") { $score += 15; $reasons += "Command memanggil URL eksternal" }
    if ($SignatureStatus -and $SignatureStatus -ne "Valid" -and $pathText -match "\.(exe|dll|ps1|cmd|bat)$") { $score += 15; $reasons += "Signature tidak valid/unknown" }
    if ([string]::IsNullOrWhiteSpace($publisherText) -and $pathText -match "\.(exe|dll)$") { $score += 12; $reasons += "Publisher tidak terbaca" }
    if ($Name -match "^[a-z0-9]{8,}\.exe$" -and $pathText -notmatch "\\Windows\\|\\Program Files") { $score += 25; $reasons += "Nama executable acak di luar folder sistem" }
    if ($Source -match "ScheduledTask" -and $cmd -match "(?i)powershell|cmd.exe|wscript|cscript|mshta|rundll32") { $score += 15; $reasons += "Scheduled task menjalankan script/shell" }

    $level = "Low"
    if ($score -ge 60) { $level = "High" }
    elseif ($score -ge 30) { $level = "Medium" }

    if ($reasons.Count -eq 0) { $reasons += "Tidak ada indikator mencurigakan kuat dari rule offline" }

    [ordered]@{
        RiskScore = [math]::Min(100, $score)
        RiskLevel = $level
        Reasons = ($reasons -join "; ")
        SuggestedAction = $(if ($level -eq "High") { "Isolasi/disable sementara lalu validasi publisher, path, dan hash." } elseif ($level -eq "Medium") { "Review dengan user/admin sebelum disable." } else { "Monitor saja bila sesuai aplikasi user." })
    }
}

function Get-StartupIntelligence {
    $items = @()
    $startupCommands = Get-CimInstance Win32_StartupCommand | Select-Object Name, Command, Location, User
    foreach ($item in $startupCommands) {
        $path = Get-ExecutableFromCommand $item.Command
        $sig = Get-SignatureInfo $path
        $publisher = Get-FilePublisher $path
        if ([string]::IsNullOrWhiteSpace($publisher)) { $publisher = $sig.Publisher }
        $risk = Get-RiskAssessment $item.Name $item.Command $path $publisher $sig.Status "StartupCommand"
        $items += [ordered]@{
            Source = "StartupCommand"
            Name = $item.Name
            Command = $item.Command
            Path = $path
            Location = $item.Location
            User = $item.User
            Publisher = $publisher
            Signature = $sig.Status
            RiskLevel = $risk.RiskLevel
            RiskScore = $risk.RiskScore
            Reasons = $risk.Reasons
            SuggestedAction = $risk.SuggestedAction
        }
    }

    $runKeys = @(
        "HKLM:\Software\Microsoft\Windows\CurrentVersion\Run",
        "HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Run",
        "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run"
    )
    foreach ($key in $runKeys) {
        if (Test-Path $key) {
            $props = Get-ItemProperty $key
            foreach ($prop in $props.PSObject.Properties) {
                if ($prop.Name -match "^PS") { continue }
                $command = [string]$prop.Value
                $path = Get-ExecutableFromCommand $command
                $sig = Get-SignatureInfo $path
                $publisher = Get-FilePublisher $path
                if ([string]::IsNullOrWhiteSpace($publisher)) { $publisher = $sig.Publisher }
                $risk = Get-RiskAssessment $prop.Name $command $path $publisher $sig.Status "RegistryRun"
                $items += [ordered]@{
                    Source = "RegistryRun"
                    Name = $prop.Name
                    Command = $command
                    Path = $path
                    Location = $key
                    User = ""
                    Publisher = $publisher
                    Signature = $sig.Status
                    RiskLevel = $risk.RiskLevel
                    RiskScore = $risk.RiskScore
                    Reasons = $risk.Reasons
                    SuggestedAction = $risk.SuggestedAction
                }
            }
        }
    }

    $tasks = @()
    if (Get-Command Get-ScheduledTask -ErrorAction SilentlyContinue) {
        $tasks = Get-ScheduledTask | Where-Object { $_.State -ne "Disabled" } | Select-Object -First 80
        foreach ($task in $tasks) {
            foreach ($action in @($task.Actions)) {
                $command = (($action.Execute, $action.Arguments) -join " ").Trim()
                if ([string]::IsNullOrWhiteSpace($command)) { continue }
                $path = Get-ExecutableFromCommand $command
                $sig = Get-SignatureInfo $path
                $publisher = Get-FilePublisher $path
                if ([string]::IsNullOrWhiteSpace($publisher)) { $publisher = $sig.Publisher }
                $risk = Get-RiskAssessment $task.TaskName $command $path $publisher $sig.Status "ScheduledTask"
                if ($risk.RiskLevel -ne "Low" -or $command -match "(?i)powershell|cmd.exe|wscript|cscript|mshta|rundll32|AppData|Temp") {
                    $items += [ordered]@{
                        Source = "ScheduledTask"
                        Name = $task.TaskName
                        Command = $command
                        Path = $path
                        Location = $task.TaskPath
                        User = ""
                        Publisher = $publisher
                        Signature = $sig.Status
                        RiskLevel = $risk.RiskLevel
                        RiskScore = $risk.RiskScore
                        Reasons = $risk.Reasons
                        SuggestedAction = $risk.SuggestedAction
                    }
                }
            }
        }
    }

    $ranked = $items | Sort-Object RiskScore -Descending
    [ordered]@{
        all_items = @($ranked)
        high_risk = @($ranked | Where-Object { $_.RiskLevel -eq "High" })
        medium_risk = @($ranked | Where-Object { $_.RiskLevel -eq "Medium" })
        low_risk_count = @($ranked | Where-Object { $_.RiskLevel -eq "Low" }).Count
    }
}

function Get-DeviceWarnings {
    Get-CimInstance Win32_PnPEntity |
        Where-Object { $_.ConfigManagerErrorCode -ne 0 } |
        Select-Object Name, Manufacturer, PNPDeviceID, ConfigManagerErrorCode, Status, ClassGuid
}

function Get-DriverIntelligence {
    $warnings = Get-DeviceWarnings
    $drivers = Get-CimInstance Win32_PnPSignedDriver |
        Select-Object DeviceName, Manufacturer, DriverProviderName, DriverVersion, DriverDate, IsSigned, Signer, InfName, DeviceClass, DeviceID

    $ranked = foreach ($driver in $drivers) {
        $score = 0
        $reasons = @()
        if ($driver.IsSigned -eq $false) { $score += 45; $reasons += "Driver tidak signed" }
        if ([string]::IsNullOrWhiteSpace([string]$driver.DriverProviderName) -or $driver.DriverProviderName -match "Unknown|Microsoft") {
            if ($driver.DeviceClass -match "Display|Net|MEDIA|USB|HDC|SCSIAdapter|Bluetooth") {
                $score += 10; $reasons += "Provider generik/unknown pada device penting"
            }
        }
        if ($driver.DriverDate) {
            $ageYears = ((Get-Date) - ([datetime]$driver.DriverDate)).TotalDays / 365
            if ($ageYears -gt 7 -and $driver.DeviceClass -match "Display|Net|MEDIA|Bluetooth|USB|HDC|SCSIAdapter") {
                $score += 20; $reasons += "Driver device penting lebih dari 7 tahun"
            } elseif ($ageYears -gt 4 -and $driver.DeviceClass -match "Display|Net|MEDIA|Bluetooth") {
                $score += 10; $reasons += "Driver device penting cukup lama"
            }
        }
        $matchWarning = $warnings | Where-Object { $_.PNPDeviceID -eq $driver.DeviceID } | Select-Object -First 1
        if ($matchWarning) { $score += 50; $reasons += "Device Manager melaporkan error code $($matchWarning.ConfigManagerErrorCode)" }
        if ($score -gt 0) {
            $level = "Medium"
            if ($score -ge 60) { $level = "High" }
            [ordered]@{
                DeviceName = $driver.DeviceName
                DeviceClass = $driver.DeviceClass
                Manufacturer = $driver.Manufacturer
                Provider = $driver.DriverProviderName
                Version = $driver.DriverVersion
                DriverDate = $driver.DriverDate
                IsSigned = $driver.IsSigned
                Signer = $driver.Signer
                InfName = $driver.InfName
                DeviceID = $driver.DeviceID
                RiskLevel = $level
                RiskScore = [math]::Min(100, $score)
                Reasons = ($reasons -join "; ")
                SuggestedAction = $(if ($level -eq "High") { "Prioritas: update/reinstall driver resmi vendor, cek hardware bila error tetap muncul." } else { "Review versi driver dan update jika ada keluhan/performa tidak normal." })
            }
        }
    }

    [ordered]@{
        driver_warnings = @($warnings)
        driver_risk = @($ranked | Sort-Object RiskScore -Descending)
        total_drivers = @($drivers).Count
    }
}

function Measure-QuickBenchmark {
    $cpuIterations = 1600000
    $cpuStart = Get-Date
    $sum = 0
    for ($i = 1; $i -le $cpuIterations; $i++) { $sum += [math]::Sqrt($i) }
    $cpuMs = ((Get-Date) - $cpuStart).TotalMilliseconds

    $ramBytes = 64MB
    $ramSource = New-Object byte[] $ramBytes
    $ramTarget = New-Object byte[] $ramBytes
    (New-Object Random).NextBytes($ramSource)
    $ramStart = Get-Date
    [Array]::Copy($ramSource, $ramTarget, $ramBytes)
    $ramMs = [math]::Max(((Get-Date) - $ramStart).TotalMilliseconds, 1)
    $ramMBps = [math]::Round(($ramBytes / 1MB) / ($ramMs / 1000), 2)

    $temp = Join-Path $env:TEMP ("pcnalisa_" + [guid]::NewGuid() + ".tmp")
    $bytes = New-Object byte[] (32MB)
    (New-Object Random).NextBytes($bytes)
    $writeStart = Get-Date
    [IO.File]::WriteAllBytes($temp, $bytes)
    $writeMs = ((Get-Date) - $writeStart).TotalMilliseconds
    $readStart = Get-Date
    $readBytes = [IO.File]::ReadAllBytes($temp)
    $readMs = ((Get-Date) - $readStart).TotalMilliseconds
    Remove-Item $temp -Force

    [ordered]@{
        cpu_iterations = $cpuIterations
        cpu_quick_ms = [math]::Round($cpuMs, 2)
        cpu_quick_score = [math]::Round(($cpuIterations / [math]::Max($cpuMs, 1)) * 1000, 0)
        ram_copy_64mb_ms = [math]::Round($ramMs, 2)
        ram_copy_mbps = $ramMBps
        storage_write_32mb_ms = [math]::Round($writeMs, 2)
        storage_write_mbps = [math]::Round(32 / ([math]::Max($writeMs, 1) / 1000), 2)
        storage_read_32mb_ms = [math]::Round($readMs, 2)
        storage_read_mbps = [math]::Round(32 / ([math]::Max($readMs, 1) / 1000), 2)
        benchmark_mode = "quick-offline-light"
    }
}

function Get-StressAiRecommendation {
    param($Stress)
    $notes = @()
    if (-not $Stress -or -not $Stress.enabled) {
        return "Stress test tidak dijalankan. Jalankan PcNalisa dengan parameter -StressTest untuk uji CPU/RAM/Storage offline."
    }
    if ($Stress.cpu_workers -lt 2) { $notes += "CPU worker rendah; hasil hanya sebagai baseline ringan." }
    if ($Stress.cpu_ops_per_second -lt 5) { $notes += "CPU stress lambat; cek suhu, power plan, proses background, atau thermal throttling." }
    if ($Stress.ram_test_mb -lt 256) { $notes += "RAM bebas kecil saat test; cek aplikasi background dan kapasitas RAM." }
    if ($Stress.ram_fill_mbps -lt 800) { $notes += "Throughput RAM rendah saat stress; ulang test setelah restart, cek konfigurasi RAM bila tetap rendah." }
    if ($Stress.storage_write_mbps -lt 60) { $notes += "Storage write rendah saat stress; cek health SSD/HDD, ruang kosong, antivirus scan, dan mode controller." }
    if ($Stress.storage_read_mbps -lt 80) { $notes += "Storage read rendah saat stress; cek health disk dan proses backup/sync." }
    if ($notes.Count -eq 0) { $notes += "Stress test offline tidak menemukan bottleneck kuat. Gunakan hasil sebagai baseline pembanding antar PC sejenis." }
    return ($notes -join " ")
}

function Measure-StressTest {
    param([int]$Seconds = 30)
    $Seconds = [Math]::Max(10, [Math]::Min($Seconds, 300))
    Write-Status "Menjalankan stress test offline CPU/RAM/Storage selama sekitar $Seconds detik."

    $logical = [int]((Get-CimInstance Win32_ComputerSystem).NumberOfLogicalProcessors)
    if ($logical -le 0) { $logical = [Environment]::ProcessorCount }
    $workers = [Math]::Max(1, [Math]::Min($logical, 8))
    $jobs = @()
    for ($w = 1; $w -le $workers; $w++) {
        $jobs += Start-Job -ScriptBlock {
            param([int]$RunSeconds)
            $end = (Get-Date).AddSeconds($RunSeconds)
            $ops = 0
            while ((Get-Date) -lt $end) {
                for ($i = 1; $i -le 50000; $i++) { [void][Math]::Sqrt($i) }
                $ops++
            }
            $ops
        } -ArgumentList $Seconds
    }
    Wait-Job -Job $jobs -Timeout ($Seconds + 20) | Out-Null
    $cpuOps = 0
    foreach ($job in $jobs) {
        $value = Receive-Job -Job $job
        if ($value) { $cpuOps += [int]$value }
        Remove-Job -Job $job -Force
    }

    $os = Get-CimInstance Win32_OperatingSystem
    $freeMb = [Math]::Round(($os.FreePhysicalMemory * 1KB) / 1MB, 0)
    $ramMb = [Math]::Max(128, [Math]::Min(512, [int]($freeMb * 0.25)))
    $ramBytes = [int64]($ramMb * 1MB)
    $ramBuffer = New-Object byte[] $ramBytes
    $ramStart = Get-Date
    for ($i = 0; $i -lt $ramBuffer.Length; $i += 4096) { $ramBuffer[$i] = [byte](($i / 4096) % 255) }
    $ramMs = [Math]::Max(((Get-Date) - $ramStart).TotalMilliseconds, 1)
    $ramMbps = [Math]::Round($ramMb / ($ramMs / 1000), 2)
    Remove-Variable ramBuffer -ErrorAction SilentlyContinue
    [GC]::Collect()

    $storageSeconds = [Math]::Min($Seconds, 30)
    $temp = Join-Path $env:TEMP ("pcnalisa_stress_" + [guid]::NewGuid() + ".tmp")
    $block = New-Object byte[] (32MB)
    (New-Object Random).NextBytes($block)
    $written = 0
    $writeStart = Get-Date
    $fs = [IO.File]::Open($temp, [IO.FileMode]::Create, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    while (((Get-Date) - $writeStart).TotalSeconds -lt $storageSeconds -and $written -lt 1024MB) {
        $fs.Write($block, 0, $block.Length)
        $written += $block.Length
    }
    $fs.Flush()
    $writeMs = [Math]::Max(((Get-Date) - $writeStart).TotalMilliseconds, 1)
    $fs.Position = 0
    $readBuffer = New-Object byte[] (32MB)
    $readBytes = 0
    $readStart = Get-Date
    while ($true) {
        $n = $fs.Read($readBuffer, 0, $readBuffer.Length)
        if ($n -le 0) { break }
        $readBytes += $n
    }
    $readMs = [Math]::Max(((Get-Date) - $readStart).TotalMilliseconds, 1)
    $fs.Close()
    Remove-Item $temp -Force

    $result = [ordered]@{
        enabled = $true
        duration_seconds = $Seconds
        cpu_workers = $workers
        cpu_ops = $cpuOps
        cpu_ops_per_second = [Math]::Round($cpuOps / [Math]::Max($Seconds, 1), 2)
        ram_test_mb = $ramMb
        ram_fill_ms = [Math]::Round($ramMs, 2)
        ram_fill_mbps = $ramMbps
        storage_test_seconds = $storageSeconds
        storage_written_mb = [Math]::Round($written / 1MB, 2)
        storage_write_mbps = [Math]::Round(($written / 1MB) / ($writeMs / 1000), 2)
        storage_read_mb = [Math]::Round($readBytes / 1MB, 2)
        storage_read_mbps = [Math]::Round(($readBytes / 1MB) / ($readMs / 1000), 2)
        ai_offline_recommendation = ""
    }
    $result.ai_offline_recommendation = Get-StressAiRecommendation $result
    return $result
}

function Get-BackgroundProcessAnalysis {
    $processes = Get-CimInstance Win32_Process |
        Select-Object ProcessId, Name, ExecutablePath, CommandLine, ParentProcessId, WorkingSetSize

    $topMemory = $processes |
        Sort-Object WorkingSetSize -Descending |
        Select-Object -First 25 ProcessId, Name, ExecutablePath, CommandLine,
            @{Name="MemoryMB";Expression={[math]::Round(($_.WorkingSetSize / 1MB), 2)}}

    $suspicious = foreach ($p in $processes) {
        $path = [string]$p.ExecutablePath
        $cmd = [string]$p.CommandLine
        $reasons = @()
        $sig = Get-SignatureInfo $path
        $publisher = Get-FilePublisher $path
        if ([string]::IsNullOrWhiteSpace($publisher)) { $publisher = $sig.Publisher }

        if ($path -match "\\AppData\\Local\\Temp\\" -or $path -match "\\Windows\\Temp\\") { $reasons += "Berjalan dari folder temp" }
        if ($path -match "\\AppData\\Roaming\\" -and $p.Name -notmatch "OneDrive|Teams|Spotify|Telegram|WhatsApp|Zoom") { $reasons += "Berjalan dari AppData Roaming" }
        if ($cmd -match "(?i)powershell.+(-enc|-encodedcommand)|frombase64string|downloadstring|invoke-webrequest|iwr\s|bitsadmin|certutil.+-urlcache|mshta|wscript|cscript|rundll32.+javascript") { $reasons += "Command line mencurigakan" }
        if ($p.Name -match "^[a-z0-9]{8,}\.exe$" -and $path -notmatch "\\Windows\\|\\Program Files") { $reasons += "Nama proses acak di luar folder sistem" }
        if ([string]::IsNullOrWhiteSpace($path)) { $reasons += "Executable path tidak terbaca" }
        if ($sig.Status -and $sig.Status -ne "Valid" -and $path -match "\.(exe|dll)$") { $reasons += "Signature tidak valid/unknown" }
        if ([string]::IsNullOrWhiteSpace($publisher) -and $path -match "\.(exe|dll)$" -and $path -notmatch "\\Windows\\") { $reasons += "Publisher tidak terbaca" }

        if ($reasons.Count -gt 0) {
            $risk = Get-RiskAssessment $p.Name $cmd $path $publisher $sig.Status "RunningProcess"
            [ordered]@{
                ProcessId = $p.ProcessId
                Name = $p.Name
                Path = $path
                CommandLine = $cmd
                MemoryMB = [math]::Round(($p.WorkingSetSize / 1MB), 2)
                Publisher = $publisher
                Signature = $sig.Status
                RiskLevel = $risk.RiskLevel
                RiskScore = $risk.RiskScore
                Reasons = (($reasons + $risk.Reasons) | Select-Object -Unique) -join "; "
                SuggestedAction = $risk.SuggestedAction
            }
        }
    }

    [ordered]@{
        top_memory_processes = $topMemory
        suspicious_processes = @($suspicious)
    }
}

Write-Status "Mulai analisa untuk $PcID pada komputer $env:COMPUTERNAME."
Show-Notice "PcNalisa mulai menganalisa $PcID.`nProses bisa berjalan beberapa menit. Klik OK, lalu tunggu popup selesai." "PcNalisa Mulai" "Information"

Write-Status "Mengambil data OS, hardware, BIOS, RAM, network, startup, dan services."
$os = Get-CimInstance Win32_OperatingSystem
$cs = Get-CimInstance Win32_ComputerSystem
$cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
$bios = Get-CimInstance Win32_BIOS
$memory = Get-CimInstance Win32_PhysicalMemory
$net = Get-CimInstance Win32_NetworkAdapter | Where-Object { $_.PhysicalAdapter -eq $true } |
    Select-Object Name, AdapterType, NetConnectionStatus, Speed, MACAddress
$startup = Get-CimInstance Win32_StartupCommand | Select-Object Name, Command, Location, User
$services = Get-CimInstance Win32_Service |
    Where-Object { $_.StartMode -eq "Auto" -and $_.State -ne "Running" } |
    Select-Object Name, DisplayName, State, StartMode
$background = Get-BackgroundProcessAnalysis
$startupIntel = Get-StartupIntelligence
$driverIntel = Get-DriverIntelligence
$benchmark = Measure-QuickBenchmark
if ($StressTest) {
    $benchmark["stress_test"] = Measure-StressTest -Seconds $StressSeconds
} else {
    $benchmark["stress_test"] = [ordered]@{
        enabled = $false
        ai_offline_recommendation = "Stress test tidak dijalankan. Jalankan PcNalisa dengan parameter -StressTest untuk uji CPU/RAM/Storage offline."
    }
}

Write-Status "Menyusun payload analisa."
$payload = [ordered]@{
    pc_id = $PcID
    owner = $Owner
    computer_name = $env:COMPUTERNAME
    collected_at = (Get-Date).ToString("s")
    hardware = [ordered]@{
        processor = $cpu.Name
        processor_cores = $cpu.NumberOfCores
        processor_logical = $cpu.NumberOfLogicalProcessors
        ram_gb = [math]::Round((($memory | Measure-Object Capacity -Sum).Sum / 1GB), 2)
        storage = Get-Storage
        gpu = Get-GpuInfo
        manufacturer = $cs.Manufacturer
        model = $cs.Model
        bios_version = $bios.SMBIOSBIOSVersion
    }
    software = [ordered]@{
        os_caption = $os.Caption
        os_version = $os.Version
        os_build = $os.BuildNumber
        architecture = $os.OSArchitecture
        office_apps = Get-OfficeApps
        antivirus = Get-Antivirus
    }
    devices = [ordered]@{
        network = $net
        driver_warnings = $driverIntel.driver_warnings
        driver_risk = $driverIntel.driver_risk
        total_drivers = $driverIntel.total_drivers
        storage_controller_hint = (Get-CimInstance Win32_IDEController | Select-Object Name, Status)
    }
    benchmark = $benchmark
    startup = [ordered]@{
        startup_items = $startup
        startup_intelligence = $startupIntel.all_items
        high_risk_startup = $startupIntel.high_risk
        medium_risk_startup = $startupIntel.medium_risk
        low_risk_startup_count = $startupIntel.low_risk_count
        auto_services_not_running = $services
        top_memory_processes = $background.top_memory_processes
        suspicious_background_processes = $background.suspicious_processes
        high_impact_hint = "Review High/Medium startup first. Risk score is offline heuristic, not a malware verdict."
    }
}

$json = $payload | ConvertTo-Json -Depth 8
$bodyBytes = [System.Text.Encoding]::UTF8.GetBytes($json)

function Send-PcConnectPayload {
    param([bool]$AllowUntrustedCertificate = $false)
    $oldCallback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
    if ($AllowUntrustedCertificate) {
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
    }
    try {
        $request = [System.Net.HttpWebRequest]::Create($ServerUrl)
        $request.Method = "POST"
        $request.ContentType = "application/json; charset=utf-8"
        $request.ContentLength = $bodyBytes.Length
        $request.Headers.Add("X-PcConnect-Token", $Token)
        $request.Timeout = 120000
        $requestStream = $request.GetRequestStream()
        $requestStream.Write($bodyBytes, 0, $bodyBytes.Length)
        $requestStream.Close()
        $httpResponse = $request.GetResponse()
        $reader = New-Object System.IO.StreamReader($httpResponse.GetResponseStream())
        $responseText = $reader.ReadToEnd()
        $reader.Close()
        $httpResponse.Close()
        return $responseText
    } finally {
        if ($AllowUntrustedCertificate) {
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $oldCallback
        }
    }
}

try {
    Write-Status "Mengirim hasil analisa ke PcConnect: $ServerUrl"
    Write-Status "Ukuran payload JSON: $($bodyBytes.Length) bytes."
    try {
        $responseText = Send-PcConnectPayload -AllowUntrustedCertificate:$false
    } catch {
        $sslMessage = $_.Exception.Message
        if ($ServerUrl -match "^https://(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|localhost|127\.0\.0\.1)" -and $sslMessage -match "trust relationship|SSL|certificate|TLS") {
            Write-Status "HTTPS memakai sertifikat lokal/self-signed. Mencoba ulang dengan bypass validasi certificate untuk upload lokal."
            $responseText = Send-PcConnectPayload -AllowUntrustedCertificate:$true
        } else {
            throw
        }
    }
    Write-Status "Upload berhasil untuk $PcID."
    Write-Status "Response PcConnect: $responseText"
    Show-Notice "Analisa selesai dan berhasil dikirim ke PcConnect.`nPcID: $PcID`nLog: $LogPath" "PcNalisa Selesai" "Information"
} catch {
    $detail = $_.Exception.Message
    if ($_.Exception.Response) {
        try {
            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            $body = $reader.ReadToEnd()
            if (-not [string]::IsNullOrWhiteSpace($body)) {
                $detail = $detail + " | Response: " + $body
            }
        } catch {}
    }
    Write-Status "ERROR: Upload gagal. $detail"
    $fallback = Join-Path $PSScriptRoot ("PcNalisa_" + $PcID + "_" + (Get-Date -Format "yyyyMMddHHmmss") + ".json")
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($fallback, $json, $utf8NoBom)
    Write-Status "Fallback JSON disimpan: $fallback"
    Show-Notice "Analisa selesai, tetapi upload ke PcConnect gagal.`n`nPenyebab:`n$detail`n`nFile fallback:`n$fallback`n`nLog:`n$LogPath" "PcNalisa Gagal Upload" "Warning"
    exit 1
}
