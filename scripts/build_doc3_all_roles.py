import html
import json
import re
import time
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

BASE_URL = 'http://localhost:888/stock2/'
DOCS_DIR = Path(r'C:\xampp\htdocs\stock2\docs')
ACCOUNTS_JSON = DOCS_DIR / 'doc3_role_accounts.json'
OUT_JSON = DOCS_DIR / 'doc3_all_roles_pages.json'
OUT_HTML = DOCS_DIR / 'doc3_all_roles.html'
IMG_DIR = DOCS_DIR / 'doc3_all_roles_images'


def slugify(text: str, max_len: int = 72) -> str:
    s = (text or '').strip().lower()
    s = re.sub(r'\s+', '_', s)
    s = re.sub(r'[^a-z0-9_\-]+', '_', s)
    s = re.sub(r'_+', '_', s).strip('_')
    return (s or 'page')[:max_len]


def page_type(url: str) -> str:
    u = url.lower()
    if 'page=module&module=' in u:
        return 'Module CRUD'
    if 'page=dashboard' in u:
        return 'Dashboard'
    if 'report.php' in u or 'business_report.php' in u or 'manufacturing_report.php' in u:
        return 'Report'
    if 'erp_flow.php' in u:
        return 'ERP Flow'
    if 'capture_screen.php' in u:
        return 'Screen Capture'
    if 'admin_access.php' in u:
        return 'Admin Access'
    if 'department_access.php' in u:
        return 'Department Access'
    if '/docs/' in u:
        return 'Documentation'
    if 'index.php' in u:
        return 'Index'
    return 'General'


def page_purpose(page: dict) -> str:
    ptype = page.get('page_type', '')
    label = page.get('menu_label', '')
    url = page.get('url', '')

    if ptype == 'Dashboard':
        return 'ใช้ติดตามภาพรวมเมนูและสถานะระบบสำหรับผู้ใช้ role นี้.'
    if ptype == 'Module CRUD':
        parsed = urlparse(url)
        module_key = parse_qs(parsed.query).get('module', [''])[0]
        if module_key:
            return f"ใช้จัดการข้อมูลของโมดูล `{module_key}` แบบ CRUD พร้อมสรุป/รายงานตามสิทธิ์ของ role นี้."
        return f"ใช้จัดการข้อมูลของโมดูล `{label}` แบบ CRUD พร้อมสรุป/รายงานตามสิทธิ์ของ role นี้."
    if ptype == 'Report':
        return 'ใช้เปิดรายงานเพื่อสรุปผลลัพธ์และตรวจสอบข้อมูลตามหน้าที่ของ role นี้.'
    if ptype == 'ERP Flow':
        return 'ใช้ตรวจ flow การทำงานข้ามโมดูล และติดตามขั้นตอนธุรกิจแบบ end-to-end.'
    if ptype == 'Screen Capture':
        return 'ใช้เก็บหลักฐานภาพหน้าจอการปฏิบัติงานและผลการทดสอบ พร้อม metadata ประกอบ.'
    if ptype == 'Admin Access':
        return 'ใช้กำหนด/ตรวจสอบสิทธิ์รายผู้ใช้ในระดับผู้ดูแลระบบ.'
    if ptype == 'Department Access':
        return 'ใช้บริหาร template สิทธิ์ตามแผนกและผูกผู้ใช้กับบทบาทงาน.'
    if ptype == 'Documentation':
        return 'เอกสารอ้างอิงการใช้งานที่ใช้สำหรับ onboarding และแนวปฏิบัติงาน.'
    if ptype == 'Authentication':
        return 'หน้าล็อกอินเพื่อยืนยันตัวตนก่อนเข้าสู่ระบบ.'
    return 'หน้าการทำงานเฉพาะตามสิทธิ์ role ที่เข้าสู่ระบบอยู่.'


def page_steps(page: dict) -> list[str]:
    ptype = page.get('page_type', '')
    if ptype == 'Authentication':
        return [
            'กรอก Username และ Password ของ role ที่ต้องการทดสอบ.',
            'กด Sign In เพื่อเข้าสู่ระบบ.',
            'ตรวจว่าเมนูที่แสดงตรงกับสิทธิ์ของ role นั้น.'
        ]
    if ptype == 'Dashboard':
        return [
            'ตรวจจำนวนเมนู/กลุ่มเมนูที่ผู้ใช้เห็นได้.',
            'เลือกเมนูจาก Sidebar เพื่อเข้าโมดูลงาน.',
            'ออกจากระบบด้วย Logout เมื่อเสร็จงาน.'
        ]
    if ptype == 'Module CRUD':
        return [
            'ตรวจปุ่มและฟังก์ชันที่ role นี้สามารถใช้ได้ (View/Add/Edit/Delete/Report).',
            'ทำรายการทดสอบขั้นต่ำ เช่นเปิดรายการ ค้นหา หรือเพิ่มข้อมูลตัวอย่าง.',
            'ตรวจผลในตารางและสรุปว่า permission ทำงานตรงที่กำหนด.'
        ]
    if ptype == 'Report':
        return [
            'กำหนดช่วงข้อมูลหรือเงื่อนไขรายงาน (ถ้ามี).',
            'แสดงรายงานและตรวจความสมบูรณ์ของข้อมูล.',
            'ใช้สำหรับ export/print ตามสิทธิ์ role.'
        ]
    if ptype in {'Admin Access', 'Department Access'}:
        return [
            'เลือกผู้ใช้หรือแผนกที่ต้องการจัดการ.',
            'ปรับสิทธิ์ตามนโยบาย role.',
            'บันทึกและทดสอบกับบัญชีปลายทาง.'
        ]
    if ptype == 'Screen Capture':
        return [
            'กรอกข้อมูลประกอบภาพ (module/stage/department/doc ref).',
            'เลือกไฟล์ภาพและกด Upload.',
            'ตรวจประวัติ Capture History ว่าถูกบันทึกแล้ว.'
        ]
    if ptype == 'ERP Flow':
        return [
            'เลือกขั้นตอน/flow ที่ต้องการทดสอบ.',
            'รัน flow และตรวจผลแต่ละสเตจ.',
            'บันทึกผลเพื่อใช้อ้างอิง QA หรือ audit.'
        ]
    if ptype == 'Documentation':
        return [
            'อ่านเอกสารเพื่อเข้าใจกระบวนการใช้งาน.',
            'ใช้อ้างอิงขั้นตอนมาตรฐานสำหรับ role นั้น.',
            'อัปเดตเอกสารเมื่อมีเปลี่ยนแปลง workflow.'
        ]
    return [
        'เข้าหน้าจอจากเมนูที่ได้รับสิทธิ์.',
        'ตรวจองค์ประกอบหลักของหน้า.',
        'ยืนยันผลการทำงานตามบทบาทผู้ใช้.'
    ]


def wait_ready(driver, timeout=25):
    WebDriverWait(driver, timeout).until(lambda d: d.execute_script('return document.readyState') == 'complete')


def extract_meta(driver) -> dict:
    return driver.execute_script(
        """
        const txt = (el) => (el ? ((el.innerText || el.textContent || '').trim()) : '');
        const take = (sel, n=20) => Array.from(document.querySelectorAll(sel)).map(x => txt(x)).filter(Boolean).slice(0,n);
        const uniq = (arr) => Array.from(new Set(arr));
        const heading = txt(document.querySelector('header.top-header h2')) || txt(document.querySelector('h1')) || txt(document.querySelector('h2')) || txt(document.querySelector('h3'));
        return {
            document_title: document.title || '',
            heading,
            alerts: take('.alert', 8),
            badges: take('.badge', 14),
            buttons: uniq(take('button, a.btn', 24)),
            labels: uniq(take('label', 24)),
            table_headers: uniq(take('table thead th', 30)),
            has_form: !!document.querySelector('form'),
            has_table: !!document.querySelector('table')
        };
        """
    )


def collect_menu_items(driver) -> list[dict]:
    items = driver.execute_script(
        """
        const out = [];
        const els = document.querySelectorAll('.menu-wrap .menu-item');
        els.forEach((a, i) => {
            const groupEl = a.closest('.menu-group');
            const group = groupEl ? ((groupEl.querySelector('.menu-group-title')?.textContent || '').trim()) : 'Core';
            out.push({
                menu_order: i + 1,
                menu_label: (a.textContent || '').trim(),
                menu_group: group,
                href: a.getAttribute('href') || '',
                abs_url: a.href || '',
                target: a.target || ''
            });
        });
        return out;
        """
    )

    uniq = []
    seen = set()
    for it in items:
        url = it.get('abs_url', '')
        if not url or url in seen:
            continue
        seen.add(url)
        uniq.append(it)
    return uniq


def login_and_capture_role(role: dict, role_index: int) -> dict:
    role_code = role['department_code']
    username = role['username']
    password = role['password']
    role_slug = slugify(role_code.lower())

    opts = webdriver.ChromeOptions()
    opts.add_argument('--headless=new')
    opts.add_argument('--window-size=1700,1200')
    opts.add_argument('--disable-gpu')
    opts.add_argument('--hide-scrollbars')
    opts.add_argument('--force-device-scale-factor=1')

    driver = webdriver.Chrome(options=opts)
    wait = WebDriverWait(driver, 25)

    role_pages = []
    error = ''

    try:
        driver.get(BASE_URL)
        wait.until(EC.presence_of_element_located((By.NAME, 'username')))
        wait_ready(driver)
        time.sleep(0.7)

        login_img = f"{role_index:02d}_{role_slug}_000_login.png"
        driver.save_screenshot(str(IMG_DIR / login_img))

        login_meta = extract_meta(driver)
        role_pages.append({
            'seq': 0,
            'menu_order': 0,
            'menu_group': 'Authentication',
            'menu_label': 'Login',
            'url': driver.current_url,
            'page_type': 'Authentication',
            'screenshot': login_img,
            'meta': login_meta,
            'error': ''
        })

        user_input = driver.find_element(By.NAME, 'username')
        pw_input = driver.find_element(By.NAME, 'password')
        user_input.clear()
        user_input.send_keys(username)
        pw_input.clear()
        pw_input.send_keys(password)
        pw_input.send_keys(Keys.ENTER)

        wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, '.menu-wrap')))
        wait_ready(driver)
        time.sleep(0.8)

        items = collect_menu_items(driver)

        seq = 1
        for it in items:
            target_url = it['abs_url']
            item_error = ''
            try:
                driver.get(target_url)
                wait_ready(driver)
                time.sleep(0.9)
            except Exception as ex:  # noqa: BLE001
                item_error = str(ex)

            current_url = driver.current_url
            shot = f"{role_index:02d}_{role_slug}_{seq:03d}_{slugify(it['menu_label'])}.png"
            try:
                driver.save_screenshot(str(IMG_DIR / shot))
            except Exception as ex:  # noqa: BLE001
                if item_error:
                    item_error += ' | screenshot: ' + str(ex)
                else:
                    item_error = 'screenshot: ' + str(ex)

            try:
                meta = extract_meta(driver)
            except Exception as ex:  # noqa: BLE001
                meta = {
                    'document_title': '',
                    'heading': '',
                    'alerts': [],
                    'badges': [],
                    'buttons': [],
                    'labels': [],
                    'table_headers': [],
                    'has_form': False,
                    'has_table': False,
                    'meta_error': str(ex),
                }

            role_pages.append({
                'seq': seq,
                'menu_order': int(it.get('menu_order') or 0),
                'menu_group': it.get('menu_group') or 'Core',
                'menu_label': it.get('menu_label') or f"Menu {seq}",
                'url': current_url,
                'page_type': page_type(current_url),
                'screenshot': shot,
                'meta': meta,
                'error': item_error,
            })
            seq += 1

        # logout
        try:
            driver.get(BASE_URL + '?action=logout')
            wait_ready(driver)
            time.sleep(0.2)
        except Exception:
            pass

    except Exception as ex:  # noqa: BLE001
        error = str(ex)

    finally:
        driver.quit()

    for p in role_pages:
        p['purpose'] = page_purpose(p)
        p['steps'] = page_steps(p)

    return {
        'department_code': role_code,
        'department_label': role.get('department_label', role_code),
        'username': username,
        'password': password,
        'user_id': role.get('user_id'),
        'setup_counts': {
            'view': role.get('module_view_count', 0),
            'add': role.get('module_add_count', 0),
            'edit': role.get('module_edit_count', 0),
            'delete': role.get('module_delete_count', 0),
            'report': role.get('module_report_count', 0),
        },
        'error': error,
        'pages': role_pages,
    }


def build_html(payload: dict):
    roles = payload['roles']

    # role summary table rows
    role_summary_rows = []
    for r in roles:
        counts = Counter(p.get('page_type', 'Unknown') for p in r['pages'])
        role_summary_rows.append(
            "<tr>"
            f"<td>{html.escape(r['department_code'])}</td>"
            f"<td>{html.escape(r['username'])}</td>"
            f"<td>{len(r['pages'])}</td>"
            f"<td>{counts.get('Module CRUD', 0)}</td>"
            f"<td>{counts.get('Report', 0)}</td>"
            f"<td>{counts.get('Dashboard', 0)}</td>"
            f"<td>{counts.get('Admin Access', 0)}</td>"
            f"<td>{counts.get('Department Access', 0)}</td>"
            f"<td>{counts.get('Screen Capture', 0)}</td>"
            f"<td>{'OK' if not r.get('error') else html.escape(r['error'])}</td>"
            "</tr>"
        )

    # global coverage by URL
    url_map = {}
    for r in roles:
        role_code = r['department_code']
        for p in r['pages']:
            if p['page_type'] == 'Authentication':
                continue
            url = p['url']
            if url not in url_map:
                url_map[url] = {
                    'label': p['menu_label'],
                    'group': p['menu_group'],
                    'type': p['page_type'],
                    'roles': [],
                }
            if role_code not in url_map[url]['roles']:
                url_map[url]['roles'].append(role_code)

    coverage_rows = []
    for i, (url, info) in enumerate(sorted(url_map.items(), key=lambda x: (x[1]['type'], x[1]['group'], x[1]['label'], x[0])), start=1):
        coverage_rows.append(
            "<tr>"
            f"<td>{i}</td>"
            f"<td>{html.escape(info['type'])}</td>"
            f"<td>{html.escape(info['group'])}</td>"
            f"<td>{html.escape(info['label'])}</td>"
            f"<td><a href='{html.escape(url)}' target='_blank' rel='noopener'>{html.escape(url)}</a></td>"
            f"<td>{', '.join(html.escape(x) for x in info['roles'])}</td>"
            "</tr>"
        )

    # detailed sections per role/page
    role_sections = []
    for r in roles:
        role_code = r['department_code']
        role_label = r['department_label']
        role_anchor = f"role-{slugify(role_code.lower())}"

        pages_table_rows = []
        for p in r['pages']:
            page_anchor = f"{role_anchor}-p{p['seq']}"
            pages_table_rows.append(
                "<tr>"
                f"<td>{p['seq']}</td>"
                f"<td>{html.escape(p['menu_group'])}</td>"
                f"<td><a href='#{page_anchor}'>{html.escape(p['menu_label'])}</a></td>"
                f"<td>{html.escape(p['page_type'])}</td>"
                f"<td><a href='{html.escape(p['url'])}' target='_blank' rel='noopener'>open</a></td>"
                "</tr>"
            )

        page_cards = []
        for p in r['pages']:
            page_anchor = f"{role_anchor}-p{p['seq']}"
            meta = p.get('meta', {})

            def to_list(items, max_items=20):
                if not items:
                    return '<li>-</li>'
                return ''.join(f"<li>{html.escape(str(x))}</li>" for x in items[:max_items])

            steps_html = ''.join(f"<li>{html.escape(s)}</li>" for s in p.get('steps', []))
            err_html = f"<p class='err'><strong>Capture error:</strong> {html.escape(p['error'])}</p>" if p.get('error') else ''

            page_cards.append(f"""
<section class="card page-card" id="{page_anchor}">
  <h3>{html.escape(role_code)} | {p['seq']} - {html.escape(p['menu_label'])}</h3>
  <p><span class="chip">Group: {html.escape(p['menu_group'])}</span> <span class="chip">Type: {html.escape(p['page_type'])}</span></p>
  <p><strong>URL:</strong> <a href="{html.escape(p['url'])}" target="_blank" rel="noopener">{html.escape(p['url'])}</a></p>
  <p><strong>คำอธิบาย:</strong> {html.escape(p.get('purpose', ''))}</p>
  <h4>วิธีใช้งาน</h4>
  <ol>{steps_html}</ol>
  <div class="grid2">
    <div>
      <h4>Buttons/Actions</h4>
      <ul>{to_list(meta.get('buttons', []), 18)}</ul>
    </div>
    <div>
      <h4>Form Labels</h4>
      <ul>{to_list(meta.get('labels', []), 18)}</ul>
    </div>
  </div>
  <div class="grid2">
    <div>
      <h4>Table Headers</h4>
      <ul>{to_list(meta.get('table_headers', []), 24)}</ul>
    </div>
    <div>
      <h4>Alerts/Badges</h4>
      <ul>{to_list((meta.get('alerts', []) + meta.get('badges', [])), 24)}</ul>
    </div>
  </div>
  <p><strong>Heading:</strong> {html.escape(meta.get('heading', '') or '-')} | <strong>Title:</strong> {html.escape(meta.get('document_title', '') or '-')}</p>
  <p><strong>Has Form:</strong> {'Yes' if meta.get('has_form') else 'No'} | <strong>Has Table:</strong> {'Yes' if meta.get('has_table') else 'No'}</p>
  {err_html}
  <figure class="shot">
    <img src="doc3_all_roles_images/{html.escape(p['screenshot'])}" alt="{html.escape(p['menu_label'])}">
    <figcaption>{html.escape(role_code)} - {html.escape(p['menu_label'])}</figcaption>
  </figure>
</section>
""")

        role_sections.append(f"""
<section class="card role-card" id="{role_anchor}">
  <h2>Role: {html.escape(role_code)} ({html.escape(role_label)})</h2>
  <p><span class="chip">User: {html.escape(r['username'])}</span> <span class="chip">Password: {html.escape(r['password'])}</span> <span class="chip">Total Captures: {len(r['pages'])}</span></p>
  <h3>สารบัญของ role นี้</h3>
  <table>
    <thead><tr><th>#</th><th>Group</th><th>Menu</th><th>Type</th><th>URL</th></tr></thead>
    <tbody>{''.join(pages_table_rows)}</tbody>
  </table>
</section>
{''.join(page_cards)}
""")

    role_nav = ''.join(
        f"<li><a href='#role-{slugify(r['department_code'].lower())}'>{html.escape(r['department_code'])} - {html.escape(r['username'])}</a></li>"
        for r in roles
    )

    html_doc = f"""<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>doc3 all roles - stock2</title>
  <style>
    :root {{
      --bg:#f3f7fc;
      --card:#fff;
      --line:#d9e4f2;
      --text:#0f172a;
      --sub:#334155;
      --brand:#0b5fff;
    }}
    * {{ box-sizing:border-box; }}
    body {{ margin:0; font-family:"Segoe UI",Tahoma,sans-serif; background:var(--bg); color:var(--text); line-height:1.58; }}
    .wrap {{ max-width:1360px; margin:18px auto; padding:0 12px 30px; }}
    .hero,.card {{ background:var(--card); border:1px solid var(--line); border-radius:12px; box-shadow:0 4px 16px rgba(15,23,42,.05); }}
    .hero {{ padding:18px; margin-bottom:10px; }}
    .card {{ padding:14px; margin-bottom:10px; }}
    h1 {{ margin:0 0 8px; font-size:28px; }}
    h2 {{ margin:0 0 8px; font-size:22px; border-left:5px solid var(--brand); padding-left:10px; }}
    h3 {{ margin:8px 0 6px; font-size:18px; }}
    h4 {{ margin:8px 0 6px; font-size:15px; }}
    p {{ margin:6px 0; color:var(--sub); }}
    ul,ol {{ margin:6px 0 6px 20px; color:var(--sub); }}
    li {{ margin:2px 0; }}
    a {{ color:#1d4ed8; text-decoration:none; }}
    a:hover {{ text-decoration:underline; }}
    table {{ width:100%; border-collapse:collapse; }}
    th,td {{ border:1px solid #d8e3f2; padding:6px 8px; font-size:13px; vertical-align:top; }}
    th {{ background:#eef4ff; text-align:left; }}
    .chip {{ display:inline-block; margin:0 6px 4px 0; padding:3px 9px; border-radius:999px; background:#eaf1ff; border:1px solid #c7d7fb; color:#1e3a8a; font-size:12px; }}
    .grid2 {{ display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:10px; }}
    .shot {{ margin-top:8px; border:1px solid #ccd9ea; border-radius:10px; overflow:hidden; background:#fff; }}
    .shot img {{ display:block; width:100%; height:auto; }}
    .shot figcaption {{ padding:8px 10px; font-size:12px; color:#4a6482; background:#f7faff; border-top:1px solid #d5e1f1; }}
    .err {{ color:#9a3412; background:#fff7ed; border:1px solid #fed7aa; padding:7px; border-radius:8px; }}
    .page-card {{ scroll-margin-top:10px; }}
  </style>
</head>
<body>
  <div class="wrap">
    <section class="hero">
      <h1>doc3 master: ครบทุก page ตามสิทธิ์ทุก role</h1>
      <p>เอกสารนี้สร้างจากการล็อกอินและไล่เปิดเมนูจริงของทุก role template ในระบบ Stock2 แล้ว capture ทุกหน้า พร้อมคำอธิบายการใช้งานรายหน้า.</p>
      <p><span class="chip">Generated: {html.escape(payload['generated_at'])}</span><span class="chip">Roles: {len(roles)}</span><span class="chip">Total Captures: {payload['total_captures']}</span><span class="chip">Base URL: {html.escape(payload['base_url'])}</span></p>
    </section>

    <section class="card">
      <h2>Role Navigation</h2>
      <ul>{role_nav}</ul>
    </section>

    <section class="card">
      <h2>Role Summary</h2>
      <table>
        <thead>
          <tr><th>Role</th><th>User</th><th>Total Pages</th><th>Module CRUD</th><th>Report</th><th>Dashboard</th><th>Admin</th><th>Dept</th><th>Capture</th><th>Status</th></tr>
        </thead>
        <tbody>{''.join(role_summary_rows)}</tbody>
      </table>
    </section>

    <section class="card">
      <h2>Coverage Matrix (Union Of All Roles)</h2>
      <p>ตารางนี้แสดงว่าหน้า URL ใดเข้าถึงได้โดย role ใดบ้าง เพื่อยืนยันการครอบคลุมทุก page ตามสิทธิ์.</p>
      <table>
        <thead><tr><th>#</th><th>Type</th><th>Group</th><th>Page</th><th>URL</th><th>Accessible By Roles</th></tr></thead>
        <tbody>{''.join(coverage_rows)}</tbody>
      </table>
    </section>

    {''.join(role_sections)}
  </div>
</body>
</html>
"""

    OUT_HTML.write_text(html_doc, encoding='utf-8')


def main():
    if not ACCOUNTS_JSON.exists():
        raise FileNotFoundError(f'Account file not found: {ACCOUNTS_JSON}')

    IMG_DIR.mkdir(parents=True, exist_ok=True)
    for old in IMG_DIR.glob('*.png'):
        old.unlink(missing_ok=True)

    account_payload = json.loads(ACCOUNTS_JSON.read_text(encoding='utf-8'))
    roles = account_payload.get('roles', [])

    all_roles_data = []
    total_captures = 0

    for idx, role in enumerate(roles, start=1):
        role_code = role.get('department_code', f'ROLE{idx}')
        print(f"[ROLE] {role_code} start")
        data = login_and_capture_role(role, idx)
        all_roles_data.append(data)
        total_captures += len(data['pages'])
        print(f"[ROLE] {role_code} done pages={len(data['pages'])} error={'yes' if data.get('error') else 'no'}")

    payload = {
        'generated_at': datetime.now().isoformat(timespec='seconds'),
        'base_url': BASE_URL,
        'total_roles': len(all_roles_data),
        'total_captures': total_captures,
        'roles': all_roles_data,
    }

    OUT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding='utf-8')
    build_html(payload)

    print(f"[DONE] json={OUT_JSON}")
    print(f"[DONE] html={OUT_HTML}")


if __name__ == '__main__':
    main()
