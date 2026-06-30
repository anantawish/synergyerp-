param(
    [string]$BaseUrl = 'http://localhost:888/stock2',
    [string]$Username = '222',
    [string]$Password = '222'
)

$ErrorActionPreference = 'Stop'
$RootUrl = $BaseUrl.TrimEnd('/') + '/'
$ProcessUrl = $RootUrl + 'api/process.php'

$modules = @(
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

Write-Host "[INFO] Master-detail process test started ($($modules.Count) modules)"

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest -Uri $RootUrl -UseBasicParsing -WebSession $session
$loginRes = Invoke-WebRequest -Uri $RootUrl -Method Post -Body @{ action='login'; username=$Username; password=$Password } -UseBasicParsing -WebSession $session
if ($loginRes.StatusCode -ne 200 -or $loginRes.Content -notmatch 'Logout') {
    throw 'Login failed'
}

$fails = @()

foreach ($module in $modules) {
    try {
        $createBody = @{ module_key = $module; use_mock = $true } | ConvertTo-Json
        $create = Invoke-RestMethod -Uri ($ProcessUrl + '?action=create') -Method Post -ContentType 'application/json' -Body $createBody -WebSession $session
        if (-not $create.ok) {
            throw 'create not ok'
        }

        $mainId = [string]$create.result.main_id
        $sourceValue = [string]$create.result.source_value
        if ([string]::IsNullOrWhiteSpace($mainId)) {
            throw 'missing main_id'
        }

        $deleteBody = @{ module_key = $module; main_id = $mainId; source_value = $sourceValue } | ConvertTo-Json
        $delete = Invoke-RestMethod -Uri ($ProcessUrl + '?action=delete') -Method Post -ContentType 'application/json' -Body $deleteBody -WebSession $session
        if (-not $delete.ok) {
            throw 'delete not ok'
        }

        Write-Host "[PASS] $module main_id=$mainId source=$sourceValue"
    } catch {
        $fails += "${module}: $($_.Exception.Message)"
        Write-Host "[FAIL] $module :: $($_.Exception.Message)"
    }
}

if ($fails.Count -gt 0) {
    Write-Host "\n[SUMMARY] failed=$($fails.Count)"
    $fails | ForEach-Object { Write-Host $_ }
    throw 'master-detail process test failed'
}

Write-Host '[DONE] master-detail process test passed'