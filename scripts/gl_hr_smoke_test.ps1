param(
    [string]$BaseUrl = 'http://localhost:888/stock2',
    [string]$Username = '222',
    [string]$Password = '222'
)

$ErrorActionPreference = 'Stop'
$RootUrl = $BaseUrl.TrimEnd('/') + '/'
$TableApi = $RootUrl + 'api/table.php'
$BusinessApi = $RootUrl + 'api/business.php'

Write-Host "[INFO] GL/HR smoke test started for $RootUrl"

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest -Uri $RootUrl -UseBasicParsing -WebSession $session
$login = Invoke-WebRequest -Uri $RootUrl -Method Post -Body @{ action='login'; username=$Username; password=$Password } -UseBasicParsing -WebSession $session
if ($login.StatusCode -ne 200 -or $login.Content -notmatch 'Logout') {
    throw 'Login failed'
}

$stamp = Get-Date -Format 'yyyyMMddHHmmss'
$today = Get-Date -Format 'yyyy-MM-dd'
$month = Get-Date -Format 'yyyy-MM'

# GL sample journal
$journalNo = "JV-$stamp"
$journalPayload = @{
    journal_no = $journalNo
    journal_date = $today
    description = 'Smoke test journal'
    source_module = 'smoke'
    source_ref = $stamp
    created_by = $Username
    status = 'POSTED'
} | ConvertTo-Json

$journalInsert = Invoke-RestMethod -Uri ($TableApi + '?action=save&table=gl_journal') -Method Post -ContentType 'application/json' -Body $journalPayload -WebSession $session
if (-not $journalInsert.ok) { throw 'Insert gl_journal failed' }
$journalId = [int]$journalInsert.result.id

$line1 = @{
    journal_id = $journalId
    line_no = 1
    account_code = '1000'
    description = 'Cash debit'
    debit = '1000'
    credit = '0'
    tax_type = 'NONE'
} | ConvertTo-Json
$line2 = @{
    journal_id = $journalId
    line_no = 2
    account_code = '4000'
    description = 'Revenue credit'
    debit = '0'
    credit = '1000'
    tax_type = 'NONE'
} | ConvertTo-Json

$line1Insert = Invoke-RestMethod -Uri ($TableApi + '?action=save&table=gl_journal_line') -Method Post -ContentType 'application/json' -Body $line1 -WebSession $session
$line2Insert = Invoke-RestMethod -Uri ($TableApi + '?action=save&table=gl_journal_line') -Method Post -ContentType 'application/json' -Body $line2 -WebSession $session
if (-not $line1Insert.ok -or -not $line2Insert.ok) { throw 'Insert gl_journal_line failed' }
Write-Host "[PASS] GL sample journal inserted (journal_id=$journalId)"

# HR sample setup
$employeeCode = "EMP-$stamp"
$employeePayload = @{
    employee_code = $employeeCode
    full_name = 'Smoke Employee'
    department = 'OPS'
    position_name = 'Staff'
    start_date = $today
    base_salary = '30000'
    work_days_per_month = '26'
    work_hours_per_day = '8'
    social_security_enabled = '1'
    tax_allowance_per_year = '60000'
    status = 'ACTIVE'
} | ConvertTo-Json

$empInsert = Invoke-RestMethod -Uri ($TableApi + '?action=save&table=hr_employee') -Method Post -ContentType 'application/json' -Body $employeePayload -WebSession $session
if (-not $empInsert.ok) { throw 'Insert hr_employee failed' }

$attendancePayload = @{
    employee_code = $employeeCode
    work_date = $today
    check_in = '09:10:00'
    check_out = '18:00:00'
    status = 'LATE'
    late_minutes = '10'
    ot_hours = '2'
    ot_type = 'WORKDAY'
    note = 'smoke'
} | ConvertTo-Json
$attInsert = Invoke-RestMethod -Uri ($TableApi + '?action=save&table=hr_attendance') -Method Post -ContentType 'application/json' -Body $attendancePayload -WebSession $session
if (-not $attInsert.ok) { throw 'Insert hr_attendance failed' }

$periodCode = 'PR' + $stamp.Substring(0, 12)
$periodPayload = @{
    period_code = $periodCode
    date_from = (Get-Date -Day 1 -Format 'yyyy-MM-dd')
    date_to = (Get-Date -Format 'yyyy-MM-dd')
    pay_date = (Get-Date -Format 'yyyy-MM-dd')
    status = 'OPEN'
} | ConvertTo-Json
$periodInsert = Invoke-RestMethod -Uri ($TableApi + '?action=save&table=hr_payroll_period') -Method Post -ContentType 'application/json' -Body $periodPayload -WebSession $session
if (-not $periodInsert.ok) { throw 'Insert hr_payroll_period failed' }
Write-Host "[PASS] HR sample data inserted (employee=$employeeCode period=$periodCode)"

# Business API checks
$tb = Invoke-RestMethod -Uri ($BusinessApi + '?action=gl_trial_balance') -Method Get -WebSession $session
if (-not $tb.ok) { throw 'gl_trial_balance failed' }

$ledger = Invoke-RestMethod -Uri ($BusinessApi + '?action=gl_ledger&account_code=1000') -Method Get -WebSession $session
if (-not $ledger.ok) { throw 'gl_ledger failed' }

$tax = Invoke-RestMethod -Uri ($BusinessApi + '?action=gl_tax_summary&month=' + $month) -Method Get -WebSession $session
if (-not $tax.ok) { throw 'gl_tax_summary failed' }

$attSummary = Invoke-RestMethod -Uri ($BusinessApi + '?action=hr_attendance_summary&month=' + $month) -Method Get -WebSession $session
if (-not $attSummary.ok) { throw 'hr_attendance_summary failed' }

$calcBody = @{ period_code = $periodCode } | ConvertTo-Json
$payrollCalc = Invoke-RestMethod -Uri ($BusinessApi + '?action=hr_calculate_payroll') -Method Post -ContentType 'application/json' -Body $calcBody -WebSession $session
if (-not $payrollCalc.ok) { throw 'hr_calculate_payroll failed' }

$payrollSummary = Invoke-RestMethod -Uri ($BusinessApi + '?action=hr_payroll_summary&period_code=' + [uri]::EscapeDataString($periodCode)) -Method Get -WebSession $session
if (-not $payrollSummary.ok) { throw 'hr_payroll_summary failed' }
if ($payrollSummary.result.rows.Count -lt 1) { throw 'hr_payroll_summary has no rows' }

Write-Host "[PASS] Business API report endpoints ready"
Write-Host "[DONE] GL/HR smoke test passed"

