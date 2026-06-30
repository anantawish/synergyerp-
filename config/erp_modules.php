<?php

return [
    [
        'group_key' => 'erp_project_flow',
        'group_title' => 'โครงการและโฟลว์ ERP / ERP Project & Flow',
        'items' => [
            [
                'key' => 'erp_project',
                'title' => 'โครงการ ERP / ERP Projects',
                'form_id' => 22,
                'mode' => 'single',
                'main_table' => 'erp_project',
            ],
            [
                'key' => 'erp_flow_run',
                'title' => 'รอบการทำงานโฟลว์ ERP / ERP Flow Runs',
                'form_id' => 22,
                'mode' => 'single',
                'main_table' => 'erp_flow_run',
            ],
            [
                'key' => 'erp_flow_step',
                'title' => 'ขั้นตอนโฟลว์ ERP / ERP Flow Steps',
                'form_id' => 22,
                'mode' => 'single',
                'main_table' => 'erp_flow_step',
                'read_only' => true,
            ],
            [
                'key' => 'erp_flow_console',
                'title' => 'คอนโซลโฟลว์ ERP / ERP Flow Console',
                'form_id' => 22,
                'mode' => 'single',
                'main_table' => 'erp_flow_run',
                'read_only' => true,
                'custom_report_url' => 'erp_flow.php',
            ],
        ],
    ],
    [
        'group_key' => 'erp_admin_dept',
        'group_title' => 'สิทธิ์การเข้าถึงตามแผนก ERP / ERP Department Access',
        'items' => [
            [
                'key' => 'erp_department',
                'title' => 'แผนก / Departments',
                'form_id' => 26,
                'mode' => 'single',
                'main_table' => 'erp_department',
            ],
            [
                'key' => 'erp_user_department',
                'title' => 'ผู้ใช้ตามแผนก / User Departments',
                'form_id' => 26,
                'mode' => 'single',
                'main_table' => 'erp_user_department',
            ],
            [
                'key' => 'erp_department_access',
                'title' => 'เทมเพลตสิทธิ์แผนก / Department Permission Templates',
                'form_id' => 26,
                'mode' => 'single',
                'main_table' => 'erp_user_department',
                'read_only' => true,
                'custom_report_url' => 'department_access.php',
            ],
        ],
    ],
    [
        'group_key' => 'erp_audit_capture',
        'group_title' => 'ตรวจสอบและหลักฐาน ERP / ERP Audit & Evidence',
        'items' => [
            [
                'key' => 'erp_screen_capture',
                'title' => 'บันทึกการจับภาพหน้าจอ / Screen Capture Log',
                'form_id' => 22,
                'mode' => 'single',
                'main_table' => 'erp_screen_capture',
                'custom_report_url' => 'capture_screen.php',
            ],
        ],
    ],
];
