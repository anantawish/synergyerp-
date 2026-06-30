param(
    [string]$BaseUrl = 'http://localhost:888/stock2',
    [string]$Username = '222',
    [string]$Password = '222'
)

$ErrorActionPreference = 'Stop'
$RootUrl = $BaseUrl.TrimEnd('/') + '/'
$ApiTable = $RootUrl + 'api/table.php'
$ApiErp = $RootUrl + 'api/erp.php'

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) {
        throw $Message
    }
}

function Save-Row([string]$Table, [hashtable]$Payload, $Session) {
    $json = $Payload | ConvertTo-Json -Depth 8
    $uri = $ApiTable + '?action=save&table=' + [uri]::EscapeDataString($Table)
    $resp = Invoke-RestMethod -Uri $uri -Method Post -ContentType 'application/json' -Body $json -WebSession $Session
    Assert-True ($resp.ok -eq $true) ("save failed: " + $Table)
    return $resp.result
}

function Call-Erp([string]$Action, [string]$Method, [hashtable]$Payload, $Session) {
    $uri = $ApiErp + '?action=' + [uri]::EscapeDataString($Action)
    if ($Method -eq 'GET') {
        if ($Payload.Count -gt 0) {
            $qs = ($Payload.GetEnumerator() | ForEach-Object {
                [uri]::EscapeDataString($_.Key) + '=' + [uri]::EscapeDataString([string]$_.Value)
            }) -join '&'
            if ($qs -ne '') { $uri += '&' + $qs }
        }
        $resp = Invoke-RestMethod -Uri $uri -Method Get -WebSession $Session
    } else {
        $json = $Payload | ConvertTo-Json -Depth 8
        $resp = Invoke-RestMethod -Uri $uri -Method Post -ContentType 'application/json' -Body $json -WebSession $Session
    }

    Assert-True ($resp.ok -eq $true) ("erp action failed: " + $Action)
    return $resp.result
}

Write-Host "[INFO] ERP flow smoke test started: $RootUrl"

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest -Uri $RootUrl -UseBasicParsing -WebSession $session
$login = Invoke-WebRequest -Uri $RootUrl -Method Post -Body @{ action='login'; username=$Username; password=$Password } -UseBasicParsing -WebSession $session
Assert-True ($login.StatusCode -eq 200 -and $login.Content -match 'Logout') 'login failed'
Write-Host '[PASS] Login success'

$stamp = Get-Date -Format 'yyyyMMddHHmmss'
$itemCode = "ERPFG-$stamp"
$projectCode = "ERPPRJ-$stamp"
$today = Get-Date -Format 'yyyy-MM-dd'
$due = (Get-Date).AddDays(7).ToString('yyyy-MM-dd')

$null = Save-Row 'mfg_item' @{
    item_code = $itemCode
    item_name = "ERP FLOW ITEM $stamp"
    item_type = 'FG'
    base_uom = 'PCS'
    is_active = 1
} $session
Write-Host "[PASS] Seed item: $itemCode"

$createResult = Call-Erp 'create_project' 'POST' @{
    project_code = $projectCode
    project_name = "ERP Demo $stamp"
    customer_name = 'ERP Smoke Test Customer'
    product_code = $itemCode
    product_name = "ERP FLOW ITEM $stamp"
    plan_qty = 10
    uom = 'PCS'
    start_date = $today
    due_date = $due
    status = 'PLANNED'
} $session

$projectId = [int]$createResult.project_id
Assert-True ($projectId -gt 0) 'project_id invalid'
Write-Host "[PASS] Create project id=$projectId code=$projectCode"

$runResult = Call-Erp 'run_project_flow' 'POST' @{ project_id = $projectId } $session
$run = $runResult.run
$steps = @($runResult.steps)

$runId = [int]$run.id
Assert-True ($runId -gt 0) 'run_id invalid'
Assert-True (([string]$run.status) -eq 'DONE') 'flow status is not DONE'
Assert-True ($steps.Count -ge 10) 'flow has too few steps'

$requiredStages = @(
    'PROC_PO',
    'PROC_RECEIVE',
    'WH_MAIN_TO_PROD',
    'AP_BILL',
    'AP_PAY',
    'MFG_ORDER',
    'MFG_APS',
    'MFG_EXEC',
    'WH_STOCK_IN',
    'WH_FG_TO_PACK',
    'WH_SHIP_OUT',
    'SALE_INVOICE',
    'GL_POST',
    'GL_BALANCE_SHEET'
)

$stageSet = @{}
foreach ($s in $steps) {
    $stageSet[[string]$s.stage_code] = $true
}
foreach ($code in $requiredStages) {
    Assert-True ($stageSet.ContainsKey($code)) ("missing stage: " + $code)
}
Write-Host "[PASS] Run flow id=$runId status=$($run.status) steps=$($steps.Count)"

$timeline = Call-Erp 'flow_timeline' 'GET' @{ run_id = $runId } $session
Assert-True (([int]$timeline.run.id) -eq $runId) 'timeline run id mismatch'
Assert-True (@($timeline.steps).Count -ge $steps.Count) 'timeline steps invalid'
Write-Host '[PASS] flow_timeline'

$dashboard = Call-Erp 'dashboard' 'GET' @{ project_limit = 10; run_limit = 10 } $session
Assert-True (([int]$dashboard.summary.runs_done) -ge 1) 'dashboard runs_done invalid'
Assert-True (@($dashboard.projects).Count -ge 1) 'dashboard projects empty'
Assert-True (@($dashboard.runs).Count -ge 1) 'dashboard runs empty'
Write-Host '[PASS] dashboard'

$erpPage = Invoke-WebRequest -Uri ($RootUrl + 'erp_flow.php') -UseBasicParsing -WebSession $session
Assert-True ($erpPage.StatusCode -eq 200) 'erp_flow.php not reachable'
Assert-True ($erpPage.Content -match 'ERP Full Flow Console') 'erp_flow.php content invalid'
Write-Host '[PASS] erp_flow.php page'

$balanceSheetPage = Invoke-WebRequest -Uri ($RootUrl + 'business_report.php?report=gl_balance_sheet&as_of_date=' + $today) -UseBasicParsing -WebSession $session
Assert-True ($balanceSheetPage.StatusCode -eq 200) 'balance sheet page not reachable'
Assert-True ($balanceSheetPage.Content -match 'GL Balance Sheet') 'balance sheet page content invalid'
Write-Host '[PASS] GL Balance Sheet page'

Write-Host '[DONE] ERP flow smoke test passed'
