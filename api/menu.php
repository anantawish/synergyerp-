<?php

require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!$authService->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'unauthorized',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function menuLabelFromKey(string $key): string
{
    $label = trim(str_replace('_', ' ', $key));
    return $label === '' ? '' : ucwords($label);
}

/** @return array{0:string,1:string} */
function splitBilingualLabel(string $label, string $fallbackEnglish): array
{
    $parts = explode('/', $label, 2);
    if (count($parts) === 2) {
        $th = trim($parts[0]);
        $en = trim($parts[1]);
        return [$th, $en !== '' ? $en : $fallbackEnglish];
    }

    $hasThai = preg_match('/\p{Thai}/u', $label) === 1;
    $hasLatin = preg_match('/[A-Za-z]/', $label) === 1;

    if ($hasThai && !$hasLatin) {
        return [trim($label), $fallbackEnglish];
    }

    if (!$hasThai && $hasLatin) {
        return [$fallbackEnglish, trim($label)];
    }

    return [trim($label), $fallbackEnglish];
}

$moduleLabelOverride = [
    'report_log' => 'รายงานบันทึกระบบ / Log Report',
    'setup_server' => 'ตั้งค่าเซิร์ฟเวอร์ / Setup Server',
];

$moduleLabelOverride['warehouse_master'] = 'คลังสินค้า / Warehouse Master';
$moduleLabelOverride['warehouse_location'] = 'ตำแหน่งคลัง / Warehouse Location';
$moduleLabelOverride['warehouse_shelf'] = 'ชั้นวางสินค้า / Warehouse Shelf';
$groupLabelOverride = [
    'erp_project_flow' => 'โครงการและโฟลว์ ERP / ERP Project & Flow',
    'erp_admin_dept' => 'สิทธิ์การเข้าถึงตามแผนก ERP / ERP Department Access',
    'erp_audit_capture' => 'ตรวจสอบและหลักฐาน ERP / ERP Audit & Evidence',
    'mfg_engineering' => 'วิศวกรรมการผลิต / MFG Engineering',
    'mfg_planning' => 'วางแผนการผลิต (APS) / MFG Planning (APS)',
    'mfg_shopfloor' => 'หน้างานผลิตและ IoT / MFG Shop Floor & IoT',
    'mfg_quality' => 'คุณภาพการผลิต (QMS) / MFG Quality (QMS)',
    'mfg_maintenance' => 'ซ่อมบำรุงการผลิต / MFG Maintenance',
    'mfg_reports' => 'รายงานการผลิต / MFG Reports',
];

$groups = $legacyModuleService->groupsForAuth($authService);
$menuGroups = [];

foreach ($groups as $group) {
    $groupKey = (string)($group['group_key'] ?? '');
    $groupTitle = (string)($group['group_title'] ?? '');
    if ($groupKey !== '' && isset($groupLabelOverride[$groupKey])) {
        $groupTitle = $groupLabelOverride[$groupKey];
    }

    [$groupTh, $groupEn] = splitBilingualLabel($groupTitle, menuLabelFromKey($groupKey));

    $items = [];
    foreach (($group['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $moduleKey = (string)($item['key'] ?? '');
        if ($moduleKey === '') {
            continue;
        }

        $title = (string)($item['title'] ?? '');
        if (isset($moduleLabelOverride[$moduleKey])) {
            $title = $moduleLabelOverride[$moduleKey];
        }

        [$titleTh, $titleEn] = splitBilingualLabel($title, menuLabelFromKey($moduleKey));

        $items[] = [
            'key' => $moduleKey,
            'title_th' => $titleTh,
            'title_en' => $titleEn,
            'href' => 'index.php?page=module&module=' . rawurlencode($moduleKey),
        ];
    }

    if (!empty($items)) {
        $menuGroups[] = [
            'key' => $groupKey,
            'title_th' => $groupTh,
            'title_en' => $groupEn,
            'items' => $items,
        ];
    }
}

$quickLinks = [
    [
        'key' => 'dashboard',
        'title_th' => 'แดชบอร์ด',
        'title_en' => 'Dashboard',
        'href' => 'index.php?page=dashboard',
    ],
];

if ($authService->hasModulePermission('erp_flow_console', 22)) {
    $quickLinks[] = [
        'key' => 'erp_flow',
        'title_th' => 'คอนโซลโฟลว์ ERP',
        'title_en' => 'ERP Flow Console',
        'href' => 'erp_flow.php',
    ];
}

if ($authService->hasModulePermission('erp_screen_capture', 22)) {
    $quickLinks[] = [
        'key' => 'screen_capture',
        'title_th' => 'บันทึกภาพหน้าจอ',
        'title_en' => 'Screen Capture Log',
        'href' => 'capture_screen.php',
    ];
}

if ($authService->hasModulePermission('transfer_stock', 5)) {
    $quickLinks[] = [
        'key' => 'barcode_io',
        'title_th' => 'ยิงบาร์โค้ดเข้าออก',
        'title_en' => 'Barcode In-Out',
        'href' => 'barcode_io.php',
    ];
}

if ($authService->hasPermission(26)) {
    $quickLinks[] = [
        'key' => 'admin_access',
        'title_th' => 'สิทธิ์ผู้ดูแลระบบ',
        'title_en' => 'Admin Access',
        'href' => 'admin_access.php',
    ];
    $quickLinks[] = [
        'key' => 'department_access',
        'title_th' => 'สิทธิ์ตามแผนก',
        'title_en' => 'Department Access',
        'href' => 'department_access.php',
    ];
}

$quickLinks[] = [
    'key' => 'manual_dept',
    'title_th' => 'คู่มือแยกตามแผนก',
    'title_en' => 'Manual by Department',
    'href' => 'docs/user_manual_departments.html',
    'target' => '_blank',
];
$quickLinks[] = [
    'key' => 'manual_full',
    'title_th' => 'คู่มือการใช้งาน',
    'title_en' => 'User Manual',
    'href' => 'docs/user_manual_full.html',
    'target' => '_blank',
];

echo json_encode([
    'ok' => true,
    'app_name' => (string)($config['app_name'] ?? 'SynergyERP'),
    'quick_links' => $quickLinks,
    'groups' => $menuGroups,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

