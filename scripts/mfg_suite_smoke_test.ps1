param(
    [string]$BaseUrl = 'http://localhost:888/stock2',
    [string]$Username = '222',
    [string]$Password = '222'
)

$ErrorActionPreference = 'Stop'
$RootUrl = $BaseUrl.TrimEnd('/') + '/'
$ApiTable = $RootUrl + 'api/table.php'
$ApiMfg = $RootUrl + 'api/mfg.php'

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) {
        throw $Message
    }
}

function Save-Row([string]$Table, [hashtable]$Payload, $Session) {
    $json = $Payload | ConvertTo-Json -Depth 8
    $resp = Invoke-RestMethod -Uri ($ApiTable + '?action=save&table=' + [uri]::EscapeDataString($Table)) -Method Post -ContentType 'application/json' -Body $json -WebSession $Session
    Assert-True ($resp.ok -eq $true) ("save failed: " + $Table)
    return $resp.result
}

function Call-Mfg([string]$Action, [string]$Method, [hashtable]$Params, $Session) {
    if ($Method -eq 'GET') {
        $qs = ($Params.GetEnumerator() | ForEach-Object { [uri]::EscapeDataString($_.Key) + '=' + [uri]::EscapeDataString([string]$_.Value) }) -join '&'
        $uri = $ApiMfg + '?action=' + [uri]::EscapeDataString($Action)
        if ($qs -ne '') { $uri += '&' + $qs }
        $resp = Invoke-RestMethod -Uri $uri -Method Get -WebSession $Session
    } else {
        $json = $Params | ConvertTo-Json -Depth 8
        $uri = $ApiMfg + '?action=' + [uri]::EscapeDataString($Action)
        $resp = Invoke-RestMethod -Uri $uri -Method Post -ContentType 'application/json' -Body $json -WebSession $Session
    }

    Assert-True ($resp.ok -eq $true) ("mfg action failed: " + $Action)
    return $resp.result
}

Write-Host "[INFO] MFG suite smoke test started: $RootUrl"
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

$null = Invoke-WebRequest -Uri $RootUrl -UseBasicParsing -WebSession $session
$login = Invoke-WebRequest -Uri $RootUrl -Method Post -Body @{ action='login'; username=$Username; password=$Password } -UseBasicParsing -WebSession $session
Assert-True ($login.StatusCode -eq 200 -and $login.Content -match 'Logout') 'login failed'
Write-Host '[PASS] Login success'

$stamp = Get-Date -Format 'yyyyMMddHHmmss'
$itemFg = "FG-$stamp"
$itemRm = "RM-$stamp"
$orderNo = "MO-$stamp"
$lotProduced = "LOT-P-$stamp"
$lotComp = "LOT-C-$stamp"
$today = Get-Date -Format 'yyyy-MM-dd'
$from = (Get-Date).AddDays(-3).ToString('yyyy-MM-dd')
$to = (Get-Date).AddDays(7).ToString('yyyy-MM-dd')
$now = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'

# Seed minimal data for all flows
$null = Save-Row 'mfg_item' @{
    item_code = $itemFg
    item_name = "FG $stamp"
    item_type = 'FG'
    base_uom = 'PCS'
    is_active = 1
} $session
$null = Save-Row 'mfg_item' @{
    item_code = $itemRm
    item_name = "RM $stamp"
    item_type = 'RM'
    base_uom = 'PCS'
    is_active = 1
} $session

$bom = Save-Row 'mfg_bom_header' @{
    item_code = $itemFg
    version_no = 'V1'
    blueprint_no = "BP-$stamp"
    effective_from = $today
    effective_to = $null
    status = 'APPROVED'
    is_default = 1
    note = 'smoke test'
} $session

$null = Save-Row 'mfg_bom_line' @{
    bom_id = $bom.id
    parent_item_code = $itemFg
    component_item_code = $itemRm
    qty_per = 2
    scrap_pct = 0
    uom = 'PCS'
    is_optional = 0
    sort_no = 10
} $session

$routing = Save-Row 'mfg_routing_header' @{
    item_code = $itemFg
    version_no = 'R1'
    effective_from = $today
    effective_to = $null
    status = 'APPROVED'
    is_default = 1
    note = 'smoke test'
} $session

$null = Save-Row 'mfg_routing_step' @{
    routing_id = $routing.id
    op_no = 10
    operation_name = 'Cut'
    primary_center_code = 'WC-CUT'
    alt_group = 'CUTTING'
    setup_hours = 0.5
    run_hours_per_unit = 0.1
    queue_hours = 0.1
    move_hours = 0.1
    inspection_required = 1
    instruction_text = 'test step'
} $session

$order = Save-Row 'mfg_production_order' @{
    order_no = $orderNo
    item_code = $itemFg
    order_qty = 10
    uom = 'PCS'
    release_date = $today
    due_date = (Get-Date).AddDays(2).ToString('yyyy-MM-dd')
    priority = 10
    status = 'PLANNED'
    notes = 'smoke test'
    created_by = $Username
} $session

$null = Save-Row 'qms_inspection_result' @{
    order_id = $order.id
    lot_no = $lotProduced
    item_code = $itemFg
    op_no = 10
    characteristic = 'LENGTH'
    measured_value = 10.1
    decision = 'PASS'
    inspector_code = $Username
    inspected_at = $now
} $session

$null = Save-Row 'qms_inspection_result' @{
    order_id = $order.id
    lot_no = $lotProduced
    item_code = $itemFg
    op_no = 10
    characteristic = 'LENGTH'
    measured_value = 10.3
    decision = 'PASS'
    inspector_code = $Username
    inspected_at = (Get-Date).AddMinutes(1).ToString('yyyy-MM-dd HH:mm:ss')
} $session

$null = Save-Row 'mfg_lot' @{
    lot_no = $lotComp
    item_code = $itemRm
    order_id = $null
    warehouse_code = 'MAIN_WH'
    location_code = 'L01'
    shelf_code = 'S01'
    qty = 100
    uom = 'PCS'
    produced_at = $now
    operator_code = $Username
    status = 'OPEN'
} $session

$null = Save-Row 'mfg_lot' @{
    lot_no = $lotProduced
    item_code = $itemFg
    order_id = $order.id
    warehouse_code = 'FG_WH'
    location_code = 'L01'
    shelf_code = 'S01'
    qty = 10
    uom = 'PCS'
    produced_at = $now
    operator_code = $Username
    status = 'OPEN'
} $session

$null = Save-Row 'mfg_lot_consumption' @{
    produced_lot_no = $lotProduced
    component_lot_no = $lotComp
    component_item_code = $itemRm
    qty = 20
    uom = 'PCS'
    recorded_at = $now
} $session

$null = Save-Row 'supply_item_policy' @{
    item_code = $itemRm
    supplier_code = 'SUP-TEST'
    lead_time_days = 3
    safety_stock = 5
    reorder_qty = 20
    min_order_qty = 1
    max_order_qty = 200
    jit_enabled = 1
    reorder_method = 'JIT'
} $session

$null = Save-Row 'demand_forecast' @{
    item_code = $itemRm
    forecast_date = (Get-Date).AddDays(3).ToString('yyyy-MM-dd')
    forecast_qty = 40
    source = 'SMOKE'
} $session

$null = Save-Row 'mfg_inventory_snapshot' @{
    item_code = $itemRm
    on_hand_qty = 5
    reserved_qty = 0
    updated_at = $now
    source = 'SMOKE'
} $session

$null = Save-Row 'mfg_material_reservation' @{
    order_id = $order.id
    item_code = $itemRm
    qty_reserved = 2
    qty_issued = 0
} $session

Write-Host '[PASS] Seed data ready'

# API action checks
$null = Call-Mfg 'bom_explosion' 'GET' @{ item_code = $itemFg; order_qty = 10; as_of_date = $today } $session
Write-Host '[PASS] bom_explosion'

$null = Call-Mfg 'routing_plan' 'GET' @{ item_code = $itemFg; qty = 10; as_of_date = $today } $session
Write-Host '[PASS] routing_plan'

$apsGen = Call-Mfg 'aps_generate' 'GET' @{
    date_from = $from
    date_to = $to
    reschedule = 1
    reason = 'smoke-test'
    advanced = 1
    weight_late = 20
    weight_setup = 3
    weight_load = 1
    split_lot_default = 0
    allow_alternative = 1
    max_batches = 100
} $session
Assert-True (($apsGen.scheduled_rows -as [int]) -ge 1) 'aps_generate scheduled_rows invalid'
$apsBoard = Call-Mfg 'aps_board' 'GET' @{ date_from = $from; date_to = $to } $session
Assert-True (($apsBoard.count -as [int]) -ge 1) 'aps_board no rows'
$apsRows = @($apsBoard.rows)
Assert-True ($apsRows.Count -ge 1) 'aps_board rows empty'
$apsCols = @($apsRows[0].PSObject.Properties.Name)
foreach ($c in @('batch_no','batch_qty','setup_hours_applied','tardiness_hours','penalty_cost','heuristic_tag')) {
    Assert-True (($apsCols -contains $c)) ("aps_board missing column: " + $c)
}
Write-Host '[PASS] aps_generate + aps_board (advanced)'

$null = Call-Mfg 'resource_utilization' 'GET' @{ date_from = $from; date_to = $to } $session
Write-Host '[PASS] resource_utilization'

$null = Call-Mfg 'ingest_sensor' 'POST' @{
    device_code = "DEV-$stamp"
    center_code = 'WC-CUT'
    metric_name = 'runtime_hours'
    metric_value = 1.5
    metric_unit = 'h'
    quality_flag = 'GOOD'
    machine_code = 'MC-CUT-01'
    vibration = 5.2
    temp_c = 65
    current_amp = 12.3
    log_time = $now
} $session
Write-Host '[PASS] ingest_sensor'

$null = Call-Mfg 'save_job_sheet' 'POST' @{
    order_id = $order.id
    op_no = 10
    instruction_text = 'follow WI-001'
    checklist = @('prepare', 'run')
    result = @{ pass = $true; note = 'ok' }
    operator_code = $Username
    status = 'DONE'
    start_at = $now
    end_at = (Get-Date).AddMinutes(20).ToString('yyyy-MM-dd HH:mm:ss')
} $session
Write-Host '[PASS] save_job_sheet'

$null = Call-Mfg 'trace_backward' 'GET' @{ lot_no = $lotProduced } $session
$null = Call-Mfg 'trace_forward' 'GET' @{ lot_no = $lotComp } $session
Write-Host '[PASS] traceability'

$spc = Call-Mfg 'spc' 'GET' @{ item_code = $itemFg; characteristic = 'LENGTH'; date_from = $from; date_to = $to } $session
Assert-True (($spc.stats.sample_size -as [int]) -ge 1) 'spc no samples'
Write-Host '[PASS] spc'

$null = Call-Mfg 'maintenance_due' 'GET' @{} $session
$null = Call-Mfg 'maintenance_risk' 'GET' @{ window_hours = 168 } $session
$null = Call-Mfg 'maintenance_generate_wo' 'GET' @{ window_hours = 168 } $session
Write-Host '[PASS] maintenance endpoints'

$inv = Call-Mfg 'inventory_reorder' 'GET' @{ as_of_date = $today; horizon_days = 30 } $session
Assert-True ($inv.rows.Count -ge 1) 'inventory_reorder empty'
$jit = Call-Mfg 'jit_plan' 'GET' @{ as_of_date = $today; horizon_days = 14 } $session
Assert-True ($jit.rows.Count -ge 1) 'jit_plan empty'
$null = Call-Mfg 'create_requisitions' 'GET' @{ mode = 'JIT'; as_of_date = $today; horizon_days = 14 } $session
Write-Host '[PASS] inventory + jit + requisitions'

$dash = Call-Mfg 'dashboard_snapshot' 'GET' @{ period_hours = 24; util_days = 7 } $session
Assert-True (($dash.summary.open_orders -as [int]) -ge 1) 'dashboard_snapshot open_orders invalid'
Assert-True (@($dash.order_status).Count -ge 1) 'dashboard_snapshot order_status empty'
Assert-True (@($dash.utilization).Count -ge 1) 'dashboard_snapshot utilization empty'
Assert-True (@($dash.sensor_health).Count -ge 1) 'dashboard_snapshot sensor_health empty'
Write-Host '[PASS] dashboard_snapshot'

# Report page checks
$reportCases = @(
    "manufacturing_report.php?report=bom_explosion&item_code=$itemFg&order_qty=10&as_of_date=$today",
    "manufacturing_report.php?report=routing_plan&item_code=$itemFg&qty=10&as_of_date=$today",
    "manufacturing_report.php?report=aps_board&date_from=$from&date_to=$to",
    "manufacturing_report.php?report=resource_utilization&date_from=$from&date_to=$to",
    "manufacturing_report.php?report=trace_backward&lot_no=$lotProduced",
    "manufacturing_report.php?report=trace_forward&lot_no=$lotComp",
    "manufacturing_report.php?report=spc&item_code=$itemFg&characteristic=LENGTH&date_from=$from&date_to=$to",
    "manufacturing_report.php?report=maintenance_due",
    "manufacturing_report.php?report=maintenance_risk&window_hours=168",
    "manufacturing_report.php?report=inventory_reorder&as_of_date=$today&horizon_days=30",
    "manufacturing_report.php?report=jit_plan&as_of_date=$today&horizon_days=14"
)

foreach ($r in $reportCases) {
    $res = Invoke-WebRequest -Uri ($RootUrl + $r) -UseBasicParsing -WebSession $session
    Assert-True ($res.StatusCode -eq 200) ("report failed: " + $r)
    Assert-True ($res.Content -match 'SynergyERP|Stock2') ("report content invalid: " + $r)
}
$dashPage = Invoke-WebRequest -Uri ($RootUrl + 'manufacturing_dashboard.php?period_hours=24&util_days=7') -UseBasicParsing -WebSession $session
Assert-True ($dashPage.StatusCode -eq 200) 'manufacturing_dashboard page failed'
Assert-True ($dashPage.Content -match 'Manufacturing Realtime Dashboard') 'manufacturing_dashboard content invalid'
Write-Host '[PASS] manufacturing_report pages + dashboard'

Write-Host '[DONE] MFG suite smoke test passed'


