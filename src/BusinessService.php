<?php

namespace Stock2;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class BusinessService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array<string, mixed> */
    public function glTrialBalance(string $dateFrom = '', string $dateTo = ''): array
    {
        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo);

        $sql = <<<'SQL'
SELECT
  a.account_code,
  a.account_name,
  a.account_type,
  COALESCE(SUM(CASE WHEN j.status = 'POSTED' AND j.journal_date BETWEEN :from_date AND :to_date THEN l.debit ELSE 0 END), 0) AS debit_total,
  COALESCE(SUM(CASE WHEN j.status = 'POSTED' AND j.journal_date BETWEEN :from_date AND :to_date THEN l.credit ELSE 0 END), 0) AS credit_total
FROM gl_account a
LEFT JOIN gl_journal_line l ON l.account_code = a.account_code
LEFT JOIN gl_journal j ON j.id = l.journal_id
WHERE a.is_active = 1
GROUP BY a.account_code, a.account_name, a.account_type
ORDER BY a.account_code ASC
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'from_date' => $from,
            'to_date' => $to,
        ]);

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($stmt->fetchAll() as $row) {
            $debit = (float)$row['debit_total'];
            $credit = (float)$row['credit_total'];
            $natureDebit = in_array((string)$row['account_type'], ['ASSET', 'EXPENSE'], true);
            $balance = $natureDebit ? ($debit - $credit) : ($credit - $debit);

            if (abs($debit) < 0.00001 && abs($credit) < 0.00001 && abs($balance) < 0.00001) {
                continue;
            }

            $rows[] = [
                'account_code' => (string)$row['account_code'],
                'account_name' => (string)$row['account_name'],
                'account_type' => (string)$row['account_type'],
                'debit_total' => round($debit, 2),
                'credit_total' => round($credit, 2),
                'balance' => round($balance, 2),
            ];

            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        return [
            'from_date' => $from,
            'to_date' => $to,
            'rows' => $rows,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'is_balanced' => round($totalDebit, 2) === round($totalCredit, 2),
        ];
    }

    /** @return array<string, mixed> */
    public function glLedger(string $accountCode, string $dateFrom = '', string $dateTo = ''): array
    {
        $accountCode = trim($accountCode);
        if ($accountCode === '') {
            throw new RuntimeException('account_code is required');
        }

        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo);

        $accountStmt = $this->pdo->prepare('SELECT account_code, account_name, account_type FROM gl_account WHERE account_code = :code LIMIT 1');
        $accountStmt->execute(['code' => $accountCode]);
        $account = $accountStmt->fetch();

        if (!is_array($account)) {
            throw new RuntimeException('account not found');
        }

        $sql = <<<'SQL'
SELECT
  j.id AS journal_id,
  j.journal_no,
  j.journal_date,
  j.description AS journal_desc,
  l.id AS line_id,
  l.line_no,
  l.description AS line_desc,
  l.debit,
  l.credit
FROM gl_journal_line l
INNER JOIN gl_journal j ON j.id = l.journal_id
WHERE l.account_code = :account_code
  AND j.status = 'POSTED'
  AND j.journal_date BETWEEN :from_date AND :to_date
ORDER BY j.journal_date ASC, j.id ASC, l.line_no ASC, l.id ASC
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'account_code' => $accountCode,
            'from_date' => $from,
            'to_date' => $to,
        ]);

        $natureDebit = in_array((string)$account['account_type'], ['ASSET', 'EXPENSE'], true);
        $runningBalance = 0.0;
        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $debit = (float)$row['debit'];
            $credit = (float)$row['credit'];
            $delta = $natureDebit ? ($debit - $credit) : ($credit - $debit);
            $runningBalance += $delta;

            $rows[] = [
                'journal_date' => (string)$row['journal_date'],
                'journal_no' => (string)$row['journal_no'],
                'description' => (string)($row['line_desc'] !== '' ? $row['line_desc'] : $row['journal_desc']),
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'running_balance' => round($runningBalance, 2),
            ];
        }

        return [
            'account' => [
                'account_code' => (string)$account['account_code'],
                'account_name' => (string)$account['account_name'],
                'account_type' => (string)$account['account_type'],
            ],
            'from_date' => $from,
            'to_date' => $to,
            'rows' => $rows,
            'ending_balance' => round($runningBalance, 2),
        ];
    }

    /** @return array<string, mixed> */
    public function glTaxSummary(string $month = ''): array
    {
        [$from, $to, $yyyymm] = $this->normalizeMonthRange($month);

        $vatSql = <<<'SQL'
SELECT
  COALESCE(SUM(CASE WHEN trans_type = 'SALE' AND is_cancelled = 0 THEN base_amount ELSE 0 END), 0) AS sale_base,
  COALESCE(SUM(CASE WHEN trans_type = 'SALE' AND is_cancelled = 0 THEN vat_amount ELSE 0 END), 0) AS sale_vat,
  COALESCE(SUM(CASE WHEN trans_type = 'PURCHASE' AND is_cancelled = 0 THEN base_amount ELSE 0 END), 0) AS purchase_base,
  COALESCE(SUM(CASE WHEN trans_type = 'PURCHASE' AND is_cancelled = 0 THEN vat_amount ELSE 0 END), 0) AS purchase_vat
FROM gl_vat_transaction
WHERE doc_date BETWEEN :from_date AND :to_date
SQL;

        $stmt = $this->pdo->prepare($vatSql);
        $stmt->execute([
            'from_date' => $from,
            'to_date' => $to,
        ]);
        $vat = $stmt->fetch();
        if (!is_array($vat)) {
            $vat = [];
        }

        $saleBase = (float)($vat['sale_base'] ?? 0);
        $saleVat = (float)($vat['sale_vat'] ?? 0);
        $purchaseBase = (float)($vat['purchase_base'] ?? 0);
        $purchaseVat = (float)($vat['purchase_vat'] ?? 0);

        $whtSql = <<<'SQL'
SELECT
  pnd_form,
  COUNT(*) AS doc_count,
  COALESCE(SUM(gross_amount), 0) AS gross_total,
  COALESCE(SUM(withholding_amount), 0) AS withholding_total
FROM gl_withholding_transaction
WHERE cert_date BETWEEN :from_date AND :to_date
GROUP BY pnd_form
ORDER BY pnd_form
SQL;

        $whtStmt = $this->pdo->prepare($whtSql);
        $whtStmt->execute([
            'from_date' => $from,
            'to_date' => $to,
        ]);

        $whtRows = [];
        $whtTotal = 0.0;
        foreach ($whtStmt->fetchAll() as $row) {
            $amt = (float)$row['withholding_total'];
            $whtRows[] = [
                'pnd_form' => (string)$row['pnd_form'],
                'doc_count' => (int)$row['doc_count'],
                'gross_total' => round((float)$row['gross_total'], 2),
                'withholding_total' => round($amt, 2),
            ];
            $whtTotal += $amt;
        }

        return [
            'month' => $yyyymm,
            'from_date' => $from,
            'to_date' => $to,
            'vat' => [
                'sale_base' => round($saleBase, 2),
                'sale_vat' => round($saleVat, 2),
                'purchase_base' => round($purchaseBase, 2),
                'purchase_vat' => round($purchaseVat, 2),
                'vat_payable' => round($saleVat - $purchaseVat, 2),
            ],
            'withholding' => [
                'rows' => $whtRows,
                'withholding_total' => round($whtTotal, 2),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function glBalanceSheet(string $asOfDate = ''): array
    {
        $asOf = trim($asOfDate);
        if ($asOf === '') {
            $asOf = date('Y-m-d');
        }

        $sql = <<<'SQL'
SELECT
  a.account_code,
  a.account_name,
  a.account_type,
  COALESCE(SUM(CASE WHEN j.status = 'POSTED' AND j.journal_date <= :as_of_date THEN l.debit ELSE 0 END), 0) AS debit_total,
  COALESCE(SUM(CASE WHEN j.status = 'POSTED' AND j.journal_date <= :as_of_date THEN l.credit ELSE 0 END), 0) AS credit_total
FROM gl_account a
LEFT JOIN gl_journal_line l ON l.account_code = a.account_code
LEFT JOIN gl_journal j ON j.id = l.journal_id
WHERE a.is_active = 1
GROUP BY a.account_code, a.account_name, a.account_type
ORDER BY a.account_code ASC
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['as_of_date' => $asOf]);

        $assets = [];
        $liabilities = [];
        $equity = [];
        $revenueTotal = 0.0;
        $expenseTotal = 0.0;

        foreach ($stmt->fetchAll() as $row) {
            $type = (string)$row['account_type'];
            $debit = (float)$row['debit_total'];
            $credit = (float)$row['credit_total'];

            if ($type === 'ASSET' || $type === 'EXPENSE') {
                $balance = $debit - $credit;
            } else {
                $balance = $credit - $debit;
            }

            if (abs($balance) < 0.00001) {
                continue;
            }

            $entry = [
                'account_code' => (string)$row['account_code'],
                'account_name' => (string)$row['account_name'],
                'balance' => round($balance, 2),
            ];

            if ($type === 'ASSET') {
                $assets[] = $entry;
            } elseif ($type === 'LIABILITY') {
                $liabilities[] = $entry;
            } elseif ($type === 'EQUITY') {
                $equity[] = $entry;
            } elseif ($type === 'REVENUE') {
                $revenueTotal += $balance;
            } elseif ($type === 'EXPENSE') {
                $expenseTotal += $balance;
            }
        }

        $totalAssets = 0.0;
        foreach ($assets as $r) {
            $totalAssets += (float)$r['balance'];
        }

        $totalLiabilities = 0.0;
        foreach ($liabilities as $r) {
            $totalLiabilities += (float)$r['balance'];
        }

        $totalEquity = 0.0;
        foreach ($equity as $r) {
            $totalEquity += (float)$r['balance'];
        }

        $currentProfit = $revenueTotal - $expenseTotal;
        $totalEquityWithProfit = $totalEquity + $currentProfit;
        $totalLiabilitiesEquity = $totalLiabilities + $totalEquityWithProfit;

        return [
            'as_of_date' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_profit' => round($currentProfit, 2),
            'total_assets' => round($totalAssets, 2),
            'total_liabilities' => round($totalLiabilities, 2),
            'total_equity' => round($totalEquityWithProfit, 2),
            'total_liabilities_equity' => round($totalLiabilitiesEquity, 2),
            'is_balanced' => round($totalAssets, 2) === round($totalLiabilitiesEquity, 2),
        ];
    }

    /** @return array<string, mixed> */
    public function hrAttendanceSummary(string $month = ''): array
    {
        [$from, $to, $yyyymm] = $this->normalizeMonthRange($month);

        $sql = <<<'SQL'
SELECT
  e.employee_code,
  e.full_name,
  e.department,
  COALESCE(SUM(CASE WHEN a.status IN ('PRESENT','LATE','HOLIDAY') THEN 1 ELSE 0 END), 0) AS work_days,
  COALESCE(SUM(CASE WHEN a.status = 'ABSENT' THEN 1 ELSE 0 END), 0) AS absent_days,
  COALESCE(SUM(CASE WHEN a.status = 'LATE' THEN 1 ELSE 0 END), 0) AS late_count,
  COALESCE(SUM(a.late_minutes), 0) AS late_minutes,
  COALESCE(SUM(CASE WHEN a.ot_type = 'WORKDAY' THEN a.ot_hours ELSE 0 END), 0) AS ot_workday,
  COALESCE(SUM(CASE WHEN a.ot_type = 'HOLIDAY' THEN a.ot_hours ELSE 0 END), 0) AS ot_holiday,
  COALESCE(SUM(CASE WHEN a.ot_type = 'HOLIDAY_OT' THEN a.ot_hours ELSE 0 END), 0) AS ot_holiday_ot
FROM hr_employee e
LEFT JOIN hr_attendance a
  ON a.employee_code = e.employee_code
 AND a.work_date BETWEEN :from_date AND :to_date
WHERE e.status = 'ACTIVE'
GROUP BY e.employee_code, e.full_name, e.department
ORDER BY e.employee_code
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'from_date' => $from,
            'to_date' => $to,
        ]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'employee_code' => (string)$row['employee_code'],
                'full_name' => (string)$row['full_name'],
                'department' => (string)$row['department'],
                'work_days' => (float)$row['work_days'],
                'absent_days' => (float)$row['absent_days'],
                'late_count' => (int)$row['late_count'],
                'late_minutes' => (int)$row['late_minutes'],
                'ot_workday' => round((float)$row['ot_workday'], 2),
                'ot_holiday' => round((float)$row['ot_holiday'], 2),
                'ot_holiday_ot' => round((float)$row['ot_holiday_ot'], 2),
            ];
        }

        return [
            'month' => $yyyymm,
            'from_date' => $from,
            'to_date' => $to,
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    public function hrLeaveSummary(string $month = ''): array
    {
        [$from, $to, $yyyymm] = $this->normalizeMonthRange($month);

        $sql = <<<'SQL'
SELECT
  r.employee_code,
  e.full_name,
  r.leave_code,
  COALESCE(t.leave_name, r.leave_code) AS leave_name,
  COALESCE(SUM(
    GREATEST(
      0,
      DATEDIFF(LEAST(r.date_to, :to_date), GREATEST(r.date_from, :from_date)) + 1
    )
  ), 0) AS overlap_days,
  COALESCE(SUM(
    GREATEST(
      0,
      DATEDIFF(LEAST(r.date_to, :to_date), GREATEST(r.date_from, :from_date)) + 1
    ) * COALESCE(r.paid_ratio, t.paid_ratio, 1)
  ), 0) AS paid_days,
  COALESCE(SUM(
    GREATEST(
      0,
      DATEDIFF(LEAST(r.date_to, :to_date), GREATEST(r.date_from, :from_date)) + 1
    ) * (1 - COALESCE(r.paid_ratio, t.paid_ratio, 1))
  ), 0) AS unpaid_days
FROM hr_leave_request r
LEFT JOIN hr_leave_type t ON t.leave_code = r.leave_code
LEFT JOIN hr_employee e ON e.employee_code = r.employee_code
WHERE r.approval_status = 'APPROVED'
  AND r.date_to >= :from_date
  AND r.date_from <= :to_date
GROUP BY r.employee_code, e.full_name, r.leave_code, leave_name
ORDER BY r.employee_code, r.leave_code
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'from_date' => $from,
            'to_date' => $to,
        ]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'employee_code' => (string)$row['employee_code'],
                'full_name' => (string)$row['full_name'],
                'leave_code' => (string)$row['leave_code'],
                'leave_name' => (string)$row['leave_name'],
                'total_days' => round((float)$row['overlap_days'], 2),
                'paid_days' => round((float)$row['paid_days'], 2),
                'unpaid_days' => round((float)$row['unpaid_days'], 2),
            ];
        }

        return [
            'month' => $yyyymm,
            'from_date' => $from,
            'to_date' => $to,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function calculatePayroll(?int $periodId = null, ?string $periodCode = null, string $runBy = 'system'): array
    {
        $period = $this->resolvePayrollPeriod($periodId, $periodCode);
        if (!$period) {
            throw new RuntimeException('payroll period not found');
        }

        $periodId = (int)$period['id'];
        $from = (string)$period['date_from'];
        $to = (string)$period['date_to'];

        $employeesStmt = $this->pdo->query("SELECT * FROM hr_employee WHERE status = 'ACTIVE' ORDER BY employee_code");
        $employees = $employeesStmt->fetchAll();

        $otWorkday = $this->policyFloat('ot_multiplier_workday', 1.5);
        $otHoliday = $this->policyFloat('ot_multiplier_holiday', 2.0);
        $otHolidayOt = $this->policyFloat('ot_multiplier_holiday_ot', 3.0);
        $ssRate = $this->policyFloat('social_security_employee_rate', 5.0);
        $ssCap = $this->policyFloat('social_security_employee_cap', 750.0);
        $latePerMinute = $this->policyFloat('lateness_deduct_per_minute', 0.0);
        $absentMultiplier = $this->policyFloat('absent_deduct_day_multiplier', 1.0);

        $attendanceStmt = $this->pdo->prepare(<<<'SQL'
SELECT
  COALESCE(SUM(CASE WHEN status IN ('PRESENT','LATE','HOLIDAY') THEN 1 ELSE 0 END), 0) AS work_days,
  COALESCE(SUM(CASE WHEN status = 'ABSENT' THEN 1 ELSE 0 END), 0) AS absent_days,
  COALESCE(SUM(late_minutes), 0) AS late_minutes,
  COALESCE(SUM(CASE WHEN ot_type = 'WORKDAY' THEN ot_hours ELSE 0 END), 0) AS ot_workday,
  COALESCE(SUM(CASE WHEN ot_type = 'HOLIDAY' THEN ot_hours ELSE 0 END), 0) AS ot_holiday,
  COALESCE(SUM(CASE WHEN ot_type = 'HOLIDAY_OT' THEN ot_hours ELSE 0 END), 0) AS ot_holiday_ot
FROM hr_attendance
WHERE employee_code = :employee_code
  AND work_date BETWEEN :from_date AND :to_date
SQL
        );

        $leaveStmt = $this->pdo->prepare(<<<'SQL'
SELECT
  COALESCE(SUM(
    GREATEST(
      0,
      DATEDIFF(LEAST(r.date_to, :to_date), GREATEST(r.date_from, :from_date)) + 1
    ) * COALESCE(r.paid_ratio, t.paid_ratio, 1)
  ), 0) AS paid_days,
  COALESCE(SUM(
    GREATEST(
      0,
      DATEDIFF(LEAST(r.date_to, :to_date), GREATEST(r.date_from, :from_date)) + 1
    ) * (1 - COALESCE(r.paid_ratio, t.paid_ratio, 1))
  ), 0) AS unpaid_days
FROM hr_leave_request r
LEFT JOIN hr_leave_type t ON t.leave_code = r.leave_code
WHERE r.employee_code = :employee_code
  AND r.approval_status = 'APPROVED'
  AND r.date_to >= :from_date
  AND r.date_from <= :to_date
SQL
        );

        $insertStmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO hr_payroll_line (
  payroll_period_id,
  employee_code,
  work_days,
  absent_days,
  leave_paid_days,
  leave_unpaid_days,
  late_minutes,
  ot_hours_workday,
  ot_hours_holiday,
  ot_hours_holiday_ot,
  gross_salary,
  ot_pay,
  late_deduct,
  absent_deduct,
  leave_deduct,
  social_security,
  taxable_income,
  withholding_tax,
  net_pay,
  calculation_note
) VALUES (
  :payroll_period_id,
  :employee_code,
  :work_days,
  :absent_days,
  :leave_paid_days,
  :leave_unpaid_days,
  :late_minutes,
  :ot_hours_workday,
  :ot_hours_holiday,
  :ot_hours_holiday_ot,
  :gross_salary,
  :ot_pay,
  :late_deduct,
  :absent_deduct,
  :leave_deduct,
  :social_security,
  :taxable_income,
  :withholding_tax,
  :net_pay,
  :calculation_note
)
ON DUPLICATE KEY UPDATE
  work_days = VALUES(work_days),
  absent_days = VALUES(absent_days),
  leave_paid_days = VALUES(leave_paid_days),
  leave_unpaid_days = VALUES(leave_unpaid_days),
  late_minutes = VALUES(late_minutes),
  ot_hours_workday = VALUES(ot_hours_workday),
  ot_hours_holiday = VALUES(ot_hours_holiday),
  ot_hours_holiday_ot = VALUES(ot_hours_holiday_ot),
  gross_salary = VALUES(gross_salary),
  ot_pay = VALUES(ot_pay),
  late_deduct = VALUES(late_deduct),
  absent_deduct = VALUES(absent_deduct),
  leave_deduct = VALUES(leave_deduct),
  social_security = VALUES(social_security),
  taxable_income = VALUES(taxable_income),
  withholding_tax = VALUES(withholding_tax),
  net_pay = VALUES(net_pay),
  calculation_note = VALUES(calculation_note)
SQL
        );

        $this->pdo->beginTransaction();
        try {
            $deleteStmt = $this->pdo->prepare('DELETE FROM hr_payroll_line WHERE payroll_period_id = :period_id');
            $deleteStmt->execute(['period_id' => $periodId]);

            $count = 0;
            $totalGross = 0.0;
            $totalNet = 0.0;
            $notes = [];

            foreach ($employees as $employee) {
                $employeeCode = (string)$employee['employee_code'];
                $baseSalary = (float)$employee['base_salary'];
                $workDaysPerMonth = max(1, (int)$employee['work_days_per_month']);
                $workHoursPerDay = max(1.0, (float)$employee['work_hours_per_day']);
                $hourlyRate = (float)$employee['hourly_rate'];
                if ($hourlyRate <= 0) {
                    $hourlyRate = ($baseSalary / $workDaysPerMonth) / $workHoursPerDay;
                }
                $dailyRate = $hourlyRate * $workHoursPerDay;

                $attendanceStmt->execute([
                    'employee_code' => $employeeCode,
                    'from_date' => $from,
                    'to_date' => $to,
                ]);
                $attendance = $attendanceStmt->fetch();
                if (!is_array($attendance)) {
                    $attendance = [];
                }

                $leaveStmt->execute([
                    'employee_code' => $employeeCode,
                    'from_date' => $from,
                    'to_date' => $to,
                ]);
                $leave = $leaveStmt->fetch();
                if (!is_array($leave)) {
                    $leave = [];
                }

                $workDays = (float)($attendance['work_days'] ?? 0);
                $absentDays = (float)($attendance['absent_days'] ?? 0);
                $lateMinutes = (int)($attendance['late_minutes'] ?? 0);
                $hoursWorkday = (float)($attendance['ot_workday'] ?? 0);
                $hoursHoliday = (float)($attendance['ot_holiday'] ?? 0);
                $hoursHolidayOt = (float)($attendance['ot_holiday_ot'] ?? 0);
                $leavePaidDays = (float)($leave['paid_days'] ?? 0);
                $leaveUnpaidDays = (float)($leave['unpaid_days'] ?? 0);

                $otPay = ($hoursWorkday * $hourlyRate * $otWorkday)
                    + ($hoursHoliday * $hourlyRate * $otHoliday)
                    + ($hoursHolidayOt * $hourlyRate * $otHolidayOt);

                $lateDeduct = $latePerMinute > 0
                    ? ($lateMinutes * $latePerMinute)
                    : ($lateMinutes * ($hourlyRate / 60));

                $absentDeduct = $absentDays * $dailyRate * $absentMultiplier;
                $leaveDeduct = $leaveUnpaidDays * $dailyRate;

                $grossSalary = $baseSalary + $otPay;

                $socialSecurityEnabled = (int)$employee['social_security_enabled'] === 1;
                $socialSecurity = 0.0;
                if ($socialSecurityEnabled) {
                    $socialSecurity = min($grossSalary * ($ssRate / 100), $ssCap);
                }

                $taxableMonthly = max($grossSalary - $lateDeduct - $absentDeduct - $leaveDeduct - $socialSecurity, 0);
                $allowancePerYear = (float)$employee['tax_allowance_per_year'];
                $annualTaxable = max(($taxableMonthly * 12) - $allowancePerYear, 0);
                $annualTax = $this->thaiAnnualTax($annualTaxable);
                $withholdingTax = $annualTax / 12;

                $netPay = $grossSalary - $lateDeduct - $absentDeduct - $leaveDeduct - $socialSecurity - $withholdingTax;

                $insertStmt->execute([
                    'payroll_period_id' => $periodId,
                    'employee_code' => $employeeCode,
                    'work_days' => round($workDays, 2),
                    'absent_days' => round($absentDays, 2),
                    'leave_paid_days' => round($leavePaidDays, 2),
                    'leave_unpaid_days' => round($leaveUnpaidDays, 2),
                    'late_minutes' => $lateMinutes,
                    'ot_hours_workday' => round($hoursWorkday, 2),
                    'ot_hours_holiday' => round($hoursHoliday, 2),
                    'ot_hours_holiday_ot' => round($hoursHolidayOt, 2),
                    'gross_salary' => round($grossSalary, 2),
                    'ot_pay' => round($otPay, 2),
                    'late_deduct' => round($lateDeduct, 2),
                    'absent_deduct' => round($absentDeduct, 2),
                    'leave_deduct' => round($leaveDeduct, 2),
                    'social_security' => round($socialSecurity, 2),
                    'taxable_income' => round($taxableMonthly, 2),
                    'withholding_tax' => round($withholdingTax, 2),
                    'net_pay' => round($netPay, 2),
                    'calculation_note' => 'Calculated by ' . $runBy,
                ]);

                $count++;
                $totalGross += $grossSalary;
                $totalNet += $netPay;
                $notes[] = $employeeCode;
            }

            $updatePeriodStmt = $this->pdo->prepare('UPDATE hr_payroll_period SET status = :status WHERE id = :id');
            $updatePeriodStmt->execute([
                'status' => 'CALCULATED',
                'id' => $periodId,
            ]);

            $this->pdo->commit();

            return [
                'period' => [
                    'id' => $periodId,
                    'period_code' => (string)$period['period_code'],
                    'date_from' => $from,
                    'date_to' => $to,
                    'pay_date' => (string)$period['pay_date'],
                    'status' => 'CALCULATED',
                ],
                'employees' => $count,
                'total_gross' => round($totalGross, 2),
                'total_net' => round($totalNet, 2),
                'employee_codes' => $notes,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    public function hrPayrollSummary(?int $periodId = null, ?string $periodCode = null): array
    {
        $period = $this->resolvePayrollPeriod($periodId, $periodCode);
        if (!$period) {
            throw new RuntimeException('payroll period not found');
        }

        $periodId = (int)$period['id'];

        $sql = <<<'SQL'
SELECT
  l.employee_code,
  e.full_name,
  e.department,
  l.gross_salary,
  l.ot_pay,
  l.late_deduct,
  l.absent_deduct,
  l.leave_deduct,
  l.social_security,
  l.withholding_tax,
  l.net_pay,
  l.work_days,
  l.absent_days,
  l.leave_paid_days,
  l.leave_unpaid_days,
  l.late_minutes,
  l.ot_hours_workday,
  l.ot_hours_holiday,
  l.ot_hours_holiday_ot
FROM hr_payroll_line l
LEFT JOIN hr_employee e ON e.employee_code = l.employee_code
WHERE l.payroll_period_id = :period_id
ORDER BY l.employee_code
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['period_id' => $periodId]);

        $rows = [];
        $totals = [
            'gross_salary' => 0.0,
            'ot_pay' => 0.0,
            'late_deduct' => 0.0,
            'absent_deduct' => 0.0,
            'leave_deduct' => 0.0,
            'social_security' => 0.0,
            'withholding_tax' => 0.0,
            'net_pay' => 0.0,
        ];

        foreach ($stmt->fetchAll() as $row) {
            $record = [
                'employee_code' => (string)$row['employee_code'],
                'full_name' => (string)$row['full_name'],
                'department' => (string)$row['department'],
                'gross_salary' => round((float)$row['gross_salary'], 2),
                'ot_pay' => round((float)$row['ot_pay'], 2),
                'late_deduct' => round((float)$row['late_deduct'], 2),
                'absent_deduct' => round((float)$row['absent_deduct'], 2),
                'leave_deduct' => round((float)$row['leave_deduct'], 2),
                'social_security' => round((float)$row['social_security'], 2),
                'withholding_tax' => round((float)$row['withholding_tax'], 2),
                'net_pay' => round((float)$row['net_pay'], 2),
                'work_days' => round((float)$row['work_days'], 2),
                'absent_days' => round((float)$row['absent_days'], 2),
                'leave_paid_days' => round((float)$row['leave_paid_days'], 2),
                'leave_unpaid_days' => round((float)$row['leave_unpaid_days'], 2),
                'late_minutes' => (int)$row['late_minutes'],
                'ot_hours_workday' => round((float)$row['ot_hours_workday'], 2),
                'ot_hours_holiday' => round((float)$row['ot_hours_holiday'], 2),
                'ot_hours_holiday_ot' => round((float)$row['ot_hours_holiday_ot'], 2),
            ];

            foreach ($totals as $k => $_) {
                $totals[$k] += (float)$record[$k];
            }

            $rows[] = $record;
        }

        foreach ($totals as $k => $v) {
            $totals[$k] = round($v, 2);
        }

        return [
            'period' => [
                'id' => (int)$period['id'],
                'period_code' => (string)$period['period_code'],
                'date_from' => (string)$period['date_from'],
                'date_to' => (string)$period['date_to'],
                'pay_date' => (string)$period['pay_date'],
                'status' => (string)$period['status'],
            ],
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function listPayrollPeriods(): array
    {
        $stmt = $this->pdo->query('SELECT id, period_code, date_from, date_to, pay_date, status FROM hr_payroll_period ORDER BY date_from DESC, id DESC');
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /** @return array{0: string, 1: string} */
    private function normalizeDateRange(string $from, string $to): array
    {
        if ($from === '' || $to === '') {
            $now = new DateTimeImmutable('now');
            $from = $now->format('Y-m-01');
            $to = $now->format('Y-m-t');
        }

        $fromDate = $this->toDate($from, 'from date');
        $toDate = $this->toDate($to, 'to date');
        if ($fromDate > $toDate) {
            throw new RuntimeException('from date must be <= to date');
        }

        return [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function normalizeMonthRange(string $month): array
    {
        $month = trim($month);
        if ($month === '') {
            $month = (new DateTimeImmutable('now'))->format('Y-m');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new RuntimeException('month format must be YYYY-MM');
        }

        $first = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00');
        if (!$first) {
            throw new RuntimeException('invalid month');
        }
        $last = $first->modify('last day of this month');

        return [$first->format('Y-m-d'), $last->format('Y-m-d'), $month];
    }

    private function toDate(string $value, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date) {
            throw new RuntimeException($label . ' format must be YYYY-MM-DD');
        }

        return $date;
    }

    private function policyFloat(string $key, float $default): float
    {
        $stmt = $this->pdo->prepare('SELECT policy_value FROM hr_policy WHERE policy_key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (float)$value;
    }

    /** @return array<string, mixed>|null */
    private function resolvePayrollPeriod(?int $periodId, ?string $periodCode): ?array
    {
        if ($periodId !== null && $periodId > 0) {
            $stmt = $this->pdo->prepare('SELECT * FROM hr_payroll_period WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $periodId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }

        $periodCode = trim((string)$periodCode);
        if ($periodCode !== '') {
            $stmt = $this->pdo->prepare('SELECT * FROM hr_payroll_period WHERE period_code = :code LIMIT 1');
            $stmt->execute(['code' => $periodCode]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }

        $stmt = $this->pdo->query('SELECT * FROM hr_payroll_period ORDER BY date_from DESC, id DESC LIMIT 1');
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function thaiAnnualTax(float $taxableAnnualIncome): float
    {
        $income = max($taxableAnnualIncome, 0);
        $bands = [
            [150000.00, 0.00],
            [300000.00, 0.05],
            [500000.00, 0.10],
            [750000.00, 0.15],
            [1000000.00, 0.20],
            [2000000.00, 0.25],
            [5000000.00, 0.30],
            [INF, 0.35],
        ];

        $tax = 0.0;
        $previous = 0.0;

        foreach ($bands as [$cap, $rate]) {
            if ($income <= $previous) {
                break;
            }

            $sliceUpper = min($income, $cap);
            $slice = max(0, $sliceUpper - $previous);
            $tax += $slice * (float)$rate;
            $previous = (float)$cap;
        }

        return round($tax, 2);
    }
}
