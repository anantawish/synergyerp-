param(
    [string]$BaseUrl = 'http://localhost:888/stock2',
    [string]$Username = '222',
    [string]$Password = '222'
)

$ErrorActionPreference = 'Stop'
$RootUrl = $BaseUrl.TrimEnd('/') + '/'
$ProcessUrl = $RootUrl + 'api/process.php'
$ReportUrl = $RootUrl + 'report.php'

$masterDetailModules = @(
    'creditor_billing',
    'creditor_paid',
    'deptor_billing',
    'deptor_paid',
    'buy_cash',
    'buy_credit',
    'buy_sendback',
    'buy_order',
    'sale_cash',
    'sale_credit',
    'sale_return',
    'quotation',
    'booking',
    'client_receive'
)

Write-Host '[INFO] Report coverage test started'

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest -Uri $RootUrl -UseBasicParsing -WebSession $session
$loginRes = Invoke-WebRequest -Uri $RootUrl -Method Post -Body @{ action='login'; username=$Username; password=$Password } -UseBasicParsing -WebSession $session
if ($loginRes.StatusCode -ne 200 -or $loginRes.Content -notmatch 'Logout') {
    throw 'Login failed'
}

$dash = Invoke-WebRequest -Uri ($RootUrl + '?page=dashboard') -UseBasicParsing -WebSession $session
$matches = [regex]::Matches($dash.Content, 'module=([a-zA-Z0-9_]+)')
$modules = $matches | ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique

$fails = @()

foreach ($module in $modules) {
    try {
        $listReport = Invoke-WebRequest -Uri ($ReportUrl + '?module=' + [uri]::EscapeDataString($module) + '&mode=list') -UseBasicParsing -WebSession $session
        if ($listReport.StatusCode -ne 200 -or $listReport.Content -notmatch 'LEGACY 1:1 REPORT') {
            throw 'list report not valid'
        }

        if ($masterDetailModules -contains $module) {
            $createBody = @{ module_key = $module; use_mock = $true } | ConvertTo-Json
            $create = Invoke-RestMethod -Uri ($ProcessUrl + '?action=create') -Method Post -ContentType 'application/json' -Body $createBody -WebSession $session
            if (-not $create.ok) {
                throw 'mock create failed'
            }

            $mainId = [string]$create.result.main_id
            $sourceValue = [string]$create.result.source_value

            $docReport = Invoke-WebRequest -Uri ($ReportUrl + '?module=' + [uri]::EscapeDataString($module) + '&id=' + [uri]::EscapeDataString($mainId)) -UseBasicParsing -WebSession $session
            if ($docReport.StatusCode -ne 200 -or $docReport.Content -notmatch 'Total Amount') {
                throw 'document report not valid'
            }

            $deleteBody = @{ module_key = $module; main_id = $mainId; source_value = $sourceValue } | ConvertTo-Json
            $delete = Invoke-RestMethod -Uri ($ProcessUrl + '?action=delete') -Method Post -ContentType 'application/json' -Body $deleteBody -WebSession $session
            if (-not $delete.ok) {
                throw 'cleanup failed'
            }
        }

        Write-Host "[PASS] $module"
    } catch {
        $msg = "${module}: $($_.Exception.Message)"
        $fails += $msg
        Write-Host "[FAIL] $msg"
    }
}

if ($fails.Count -gt 0) {
    Write-Host "\n[SUMMARY] failed=$($fails.Count)"
    $fails | ForEach-Object { Write-Host $_ }
    throw 'report coverage test failed'
}

Write-Host "[DONE] report coverage test passed (modules=$($modules.Count))"