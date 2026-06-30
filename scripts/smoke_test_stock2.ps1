param(
    [string]$BaseUrl = 'http://localhost:888/stock2',
    [string]$Username = '222',
    [string]$Password = '222'
)

$ErrorActionPreference = 'Stop'
$RootUrl = $BaseUrl.TrimEnd('/') + '/'
$ApiUrl = $RootUrl + 'api/table.php'

Write-Host "[INFO] Legacy 1:1 smoke test started for $RootUrl"

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

$homeRes = Invoke-WebRequest -Uri $RootUrl -UseBasicParsing -WebSession $session
if ($homeRes.StatusCode -ne 200 -or $homeRes.Content -notmatch 'Sign In') {
    throw 'Homepage/login page not ready'
}
Write-Host '[PASS] Login page reachable'

$loginBody = @{ action = 'login'; username = $Username; password = $Password }
$loginRes = Invoke-WebRequest -Uri $RootUrl -Method Post -Body $loginBody -UseBasicParsing -WebSession $session
if ($loginRes.StatusCode -ne 200 -or $loginRes.Content -notmatch 'Logout') {
    throw 'Login failed'
}
Write-Host '[PASS] Login success'

$dash = Invoke-WebRequest -Uri ($RootUrl + '?page=dashboard') -UseBasicParsing -WebSession $session
if ($dash.StatusCode -ne 200 -or $dash.Content -notmatch 'Legacy Modules') {
    throw 'Dashboard not ready after login'
}
Write-Host '[PASS] Dashboard loaded'

$module = Invoke-WebRequest -Uri ($RootUrl + '?page=module&module=sale_cash') -UseBasicParsing -WebSession $session
if ($module.StatusCode -ne 200 -or $module.Content -notmatch 'mainGrid' -or $module.Content -notmatch 'detailGrid') {
    throw 'Sale Cash module page not ready'
}
Write-Host '[PASS] Sale Cash module loaded'

$schemaMain = Invoke-RestMethod -Uri ($ApiUrl + '?action=schema&table=bill_salecash') -Method Get -WebSession $session
if (-not $schemaMain.ok) {
    throw 'Schema main failed'
}

$schemaDetail = Invoke-RestMethod -Uri ($ApiUrl + '?action=schema&table=bill_salecash_detail') -Method Get -WebSession $session
if (-not $schemaDetail.ok) {
    throw 'Schema detail failed'
}
Write-Host '[PASS] API schema for main/detail ready'

$stamp = Get-Date -Format 'yyyyMMddHHmmss'
$billCode = "SC-$stamp"

$mainPayload = @{
    bill_id = $billCode
    billdate = (Get-Date -Format 'yyyy-MM-dd')
    cust_id = 'CUST-DEMO'
    cust_name = 'Demo Customer'
    staff_username = $Username
    total = '1500'
    balance = '1500'
    vat = '0'
    final_balance = '1500'
    transdate = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
} | ConvertTo-Json

$mainInsert = Invoke-RestMethod -Uri ($ApiUrl + '?action=save&table=bill_salecash') -Method Post -ContentType 'application/json' -Body $mainPayload -WebSession $session
if (-not $mainInsert.ok) {
    throw 'Main insert failed'
}
$mainId = [string]$mainInsert.result.id
Write-Host "[PASS] Main insert ok (id=$mainId bill_id=$billCode)"

$detailPayload = @{
    bill_salecash_id = $billCode
    product_code = 'P-DEMO'
    product_name = 'Demo Product'
    delivery_items = '2'
    unit_name = 'PCS'
    item_price = '750'
    amount = '1500'
    users = $Username
    transdate = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
} | ConvertTo-Json

$detailInsert = Invoke-RestMethod -Uri ($ApiUrl + '?action=save&table=bill_salecash_detail') -Method Post -ContentType 'application/json' -Body $detailPayload -WebSession $session
if (-not $detailInsert.ok) {
    throw 'Detail insert failed'
}
$detailId = [string]$detailInsert.result.id
Write-Host "[PASS] Detail insert ok (id=$detailId)"

$mainList = Invoke-RestMethod -Uri ($ApiUrl + '?action=list&table=bill_salecash') -Method Post -Body @{ draw=1; start=0; length=5; filter_column='bill_id'; filter_value=$billCode } -WebSession $session
if ($mainList.data.Count -lt 1) {
    throw 'Main list with filter returned 0 rows'
}

$detailList = Invoke-RestMethod -Uri ($ApiUrl + '?action=list&table=bill_salecash_detail') -Method Post -Body @{ draw=1; start=0; length=10; filter_column='bill_salecash_id'; filter_value=$billCode } -WebSession $session
if ($detailList.data.Count -lt 1) {
    throw 'Detail list with filter returned 0 rows'
}
Write-Host '[PASS] Main/detail filtered list works'

$detailDelete = Invoke-RestMethod -Uri ($ApiUrl + '?action=delete&table=bill_salecash_detail') -Method Post -ContentType 'application/json' -Body (@{ id = $detailId } | ConvertTo-Json) -WebSession $session
if (-not $detailDelete.ok) {
    throw 'Detail delete failed'
}

$mainDelete = Invoke-RestMethod -Uri ($ApiUrl + '?action=delete&table=bill_salecash') -Method Post -ContentType 'application/json' -Body (@{ id = $mainId } | ConvertTo-Json) -WebSession $session
if (-not $mainDelete.ok) {
    throw 'Main delete failed'
}

Write-Host '[PASS] Delete main/detail works'
Write-Host '[DONE] Legacy 1:1 smoke test passed'