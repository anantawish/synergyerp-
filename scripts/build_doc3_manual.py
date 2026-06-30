import html
import json
import re
import time
from collections import Counter
from datetime import datetime
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

BASE_URL = 'http://localhost:888/stock2/'
USERNAME = '222'
PASSWORD = '222'
ROOT = Path(r'C:\xampp\htdocs\stock2\docs')
IMG_DIR = ROOT / 'doc3_images'
JSON_PATH = ROOT / 'doc3_pages.json'
HTML_PATH = ROOT / 'doc3.html'


def slugify(text: str, max_len: int = 72) -> str:
    s = (text or '').strip().lower()
    s = re.sub(r'\s+', '_', s)
    s = re.sub(r'[^a-z0-9_\-]+', '_', s)
    s = re.sub(r'_+', '_', s).strip('_')
    if not s:
        s = 'page'
    return s[:max_len]


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


def page_purpose(item: dict) -> str:
    ptype = item.get('page_type', '')
    label = item.get('menu_label', '')
    url = item.get('url', '')

    if ptype == 'Dashboard':
        return 'ใช้ติดตามภาพรวมจำนวนโมดูล/กลุ่มเมนู และเป็นจุดเริ่มต้นสำหรับเข้าหน้าทำงานต่าง ๆ ของระบบ.'
    if ptype == 'Module CRUD':
        parsed = urlparse(url)
        module_key = parse_qs(parsed.query).get('module', [''])[0]
        if module_key:
            return f"ใช้จัดการข้อมูลของโมดูล `{module_key}` แบบ CRUD (เพิ่ม/แก้ไข/ลบ/ค้นหา/ส่งออกรายงาน)."
        return f"ใช้จัดการข้อมูลของโมดูล `{label}` แบบ CRUD (เพิ่ม/แก้ไข/ลบ/ค้นหา/ส่งออกรายงาน)."
    if ptype == 'Report':
        return 'ใช้ดูรายงานสรุปหรือรายงานเอกสาร เพื่อพิมพ์/ตรวจสอบผลลัพธ์ทางธุรกิจในรูปแบบอ่านง่าย.'
    if ptype == 'ERP Flow':
        return 'ใช้ติดตามลำดับการไหลของข้อมูลธุรกิจแบบ end-to-end และทดสอบกระบวนการข้ามโมดูล.'
    if ptype == 'Screen Capture':
        return 'ใช้เก็บหลักฐานภาพหน้าจอการทำงาน พร้อม metadata เช่น module, stage, department, project และ doc ref.'
    if ptype == 'Admin Access':
        return 'ใช้กำหนดสิทธิ์รายผู้ใช้/รายโมดูล (view/add/edit/delete/report) สำหรับผู้ดูแลระบบ.'
    if ptype == 'Department Access':
        return 'ใช้กำหนดสิทธิ์ตามแผนกงาน เพื่อควบคุมการเข้าถึงหน้าจอให้ตรงนโยบายองค์กร.'
    if ptype == 'Documentation':
        return 'เป็นเอกสารอ้างอิงการใช้งานสำหรับผู้ใช้/แผนก เพื่อช่วย onboarding และการทำงานมาตรฐาน.'
    if ptype == 'Index':
        return 'หน้าเริ่มต้นของระบบ ใช้สำหรับเข้าสู่ Dashboard หรือเข้าหน้าเมนูหลักหลังล็อกอิน.'
    return 'เป็นหน้าจอการทำงานของระบบตามสิทธิ์ผู้ใช้ที่ได้รับ.'


def page_steps(item: dict) -> list[str]:
    ptype = item.get('page_type', '')
    if ptype == 'Dashboard':
        return [
            'ตรวจจำนวน Modules และ Groups เพื่อยืนยันว่าเมนูโหลดครบตามสิทธิ์.',
            'ใช้รายการ Legacy Modules เพื่อเปิดหน้าทำงานที่ต้องการ.',
            'ออกจากระบบด้วยปุ่ม Logout เมื่อใช้งานเสร็จ.'
        ]
    if ptype == 'Module CRUD':
        return [
            'ตรวจ Form ID, Main Table และ Detail Table (ถ้ามี) ที่แถบข้อมูลด้านบน.',
            'กด Add Main เพื่อเพิ่มข้อมูล และใช้ Save เพื่อบันทึก.',
            'ใช้ Refresh/Summary/Report เพื่อตรวจสอบผลลัพธ์และข้อมูลประกอบ.',
            'ถ้าเป็น master-detail ให้เลือก main record ก่อนเพิ่ม detail.'
        ]
    if ptype == 'Report':
        return [
            'กำหนดเงื่อนไขหรือช่วงวันที่ตามรายงาน (ถ้ามีช่องกรอก).',
            'กดเรียกข้อมูลแล้วตรวจยอดรวม/รายการในตารางหรือเอกสาร.',
            'พิมพ์หรือส่งออกข้อมูลเพื่อแนบเอกสารงาน.'
        ]
    if ptype in {'Admin Access', 'Department Access'}:
        return [
            'เลือกผู้ใช้หรือแผนกที่ต้องการปรับสิทธิ์.',
            'กำหนดสิทธิ์ view/add/edit/delete/report ให้สอดคล้องกับบทบาท.',
            'บันทึกผลและทดสอบล็อกอินบัญชีปลายทางเพื่อยืนยันสิทธิ์.'
        ]
    if ptype == 'Screen Capture':
        return [
            'กรอกข้อมูล Module, Screen Name, Stage และข้อมูลอ้างอิงประกอบ.',
            'เลือกไฟล์ภาพหน้าจอและกด Upload Capture.',
            'ตรวจรายการใน Capture History และเปิดรูปตรวจซ้ำได้ทันที.'
        ]
    if ptype == 'ERP Flow':
        return [
            'เลือก flow หรือ action ที่ต้องการรันตามสถานการณ์ทดสอบ.',
            'ตรวจผลลัพธ์แต่ละขั้นว่าผ่านและเชื่อมข้อมูลข้ามโมดูลได้ถูกต้อง.',
            'บันทึกผลทดสอบเพื่อใช้ติดตามปัญหาและการแก้ไข.'
        ]
    if ptype == 'Documentation':
        return [
            'อ่านโครงงานและขั้นตอนมาตรฐานในเอกสารประกอบ.',
            'ใช้เป็นคู่มือ onboarding และตรวจสอบขั้นตอนทำงานที่ถูกต้อง.',
            'อัปเดตเวอร์ชันเอกสารเมื่อมีการเปลี่ยนแปลงกระบวนการ.'
        ]
    return [
        'เข้าเมนูตามสิทธิ์ที่ได้รับ.',
        'ตรวจองค์ประกอบหลักของหน้า (ฟอร์ม ปุ่ม ตาราง).',
        'บันทึกผลการใช้งาน/ทดสอบตาม workflow ของทีม.'
    ]


def extract_meta(driver) -> dict:
    return driver.execute_script(
        """
        const txt = (el) => (el ? ((el.innerText || el.textContent || '').trim()) : '');
        const take = (sel, n=20) => Array.from(document.querySelectorAll(sel))
            .map(x => txt(x)).filter(Boolean).slice(0, n);
        const uniq = (arr) => Array.from(new Set(arr));
        const heading = txt(document.querySelector('header.top-header h2'))
            || txt(document.querySelector('h1'))
            || txt(document.querySelector('h2'))
            || txt(document.querySelector('h3'));
        return {
            document_title: document.title || '',
            heading,
            alerts: take('.alert', 6),
            badges: take('.badge', 12),
            buttons: uniq(take('button, a.btn', 24)),
            labels: uniq(take('label', 24)),
            table_headers: uniq(take('table thead th', 28)),
            has_form: !!document.querySelector('form'),
            has_table: !!document.querySelector('table'),
            ready_state: document.readyState || ''
        };
        """
    )


def wait_ready(driver, timeout=20):
    WebDriverWait(driver, timeout).until(lambda d: d.execute_script('return document.readyState') == 'complete')


def main():
    ROOT.mkdir(parents=True, exist_ok=True)
    IMG_DIR.mkdir(parents=True, exist_ok=True)

    # clear previous screenshots for doc3
    for old in IMG_DIR.glob('*.png'):
        old.unlink(missing_ok=True)

    opts = webdriver.ChromeOptions()
    opts.add_argument('--headless=new')
    opts.add_argument('--window-size=1700,1200')
    opts.add_argument('--disable-gpu')
    opts.add_argument('--hide-scrollbars')
    opts.add_argument('--force-device-scale-factor=1')

    driver = webdriver.Chrome(options=opts)
    wait = WebDriverWait(driver, 25)

    pages: list[dict] = []

    try:
        # login page capture
        driver.get(BASE_URL)
        wait.until(EC.presence_of_element_located((By.NAME, 'username')))
        wait_ready(driver)
        time.sleep(0.8)

        login_img = '001_login.png'
        driver.save_screenshot(str(IMG_DIR / login_img))
        login_meta = extract_meta(driver)

        pages.append({
            'index': 1,
            'menu_order': 0,
            'menu_group': 'Authentication',
            'menu_label': 'Login',
            'url': driver.current_url,
            'page_type': 'Authentication',
            'screenshot': login_img,
            'meta': login_meta,
            'error': ''
        })

        # login
        driver.find_element(By.NAME, 'username').clear()
        driver.find_element(By.NAME, 'username').send_keys(USERNAME)
        pw = driver.find_element(By.NAME, 'password')
        pw.clear()
        pw.send_keys(PASSWORD)
        pw.send_keys(Keys.ENTER)

        wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, '.menu-wrap')))
        wait_ready(driver)
        time.sleep(0.8)

        # collect all menu items from sidebar in displayed order
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

        # unique by abs_url keep order
        seen = set()
        unique_items = []
        for it in items:
            abs_url = it.get('abs_url', '')
            if not abs_url or abs_url in seen:
                continue
            seen.add(abs_url)
            unique_items.append(it)

        seq = 2
        for it in unique_items:
            url = it['abs_url']
            label = it['menu_label'] or 'Menu'
            group = it['menu_group'] or 'Core'
            err = ''

            try:
                driver.get(url)
                wait_ready(driver)
                time.sleep(1.0)
            except Exception as ex:  # noqa: BLE001
                err = str(ex)

            current_url = driver.current_url
            ptype = page_type(current_url)
            shot = f"{seq:03d}_{slugify(label)}.png"

            try:
                driver.save_screenshot(str(IMG_DIR / shot))
            except Exception as ex:  # noqa: BLE001
                if err:
                    err = err + ' | screenshot: ' + str(ex)
                else:
                    err = 'screenshot: ' + str(ex)

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
                    'ready_state': '',
                    'meta_error': str(ex),
                }

            pages.append({
                'index': seq,
                'menu_order': int(it.get('menu_order') or 0),
                'menu_group': group,
                'menu_label': label,
                'url': current_url,
                'page_type': ptype,
                'screenshot': shot,
                'meta': meta,
                'error': err,
            })
            seq += 1

    finally:
        driver.quit()

    # post-process descriptions
    for p in pages:
        p['purpose'] = page_purpose(p)
        p['steps'] = page_steps(p)

    # summary
    types = Counter(p.get('page_type', 'Unknown') for p in pages)

    payload = {
        'generated_at': datetime.now().isoformat(timespec='seconds'),
        'base_url': BASE_URL,
        'username': USERNAME,
        'total_pages': len(pages),
        'types': dict(types),
        'pages': pages,
    }
    JSON_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding='utf-8')

    # build html
    nav_rows = []
    for p in pages:
        sec_id = f"sec-{p['index']}"
        nav_rows.append(
            f"<tr><td>{p['index']}</td><td>{html.escape(p['menu_group'])}</td><td><a href='#{sec_id}'>{html.escape(p['menu_label'])}</a></td><td>{html.escape(p['page_type'])}</td></tr>"
        )

    sections = []
    for p in pages:
        sec_id = f"sec-{p['index']}"
        meta = p.get('meta', {})
        buttons = meta.get('buttons') or []
        labels = meta.get('labels') or []
        headers = meta.get('table_headers') or []
        alerts = meta.get('alerts') or []
        badges = meta.get('badges') or []

        def li_list(items: list[str], empty='-') -> str:
            if not items:
                return f"<li>{empty}</li>"
            return ''.join(f"<li>{html.escape(x)}</li>" for x in items)

        steps_html = ''.join(f"<li>{html.escape(s)}</li>" for s in p.get('steps', []))

        err_html = ''
        if p.get('error'):
            err_html = f"<p class='err'><strong>หมายเหตุการเก็บภาพ:</strong> {html.escape(p['error'])}</p>"

        sections.append(f"""
<section class="card page" id="{sec_id}">
  <h2>{p['index']}) {html.escape(p['menu_label'])}</h2>
  <p><span class="chip">Group: {html.escape(p['menu_group'])}</span> <span class="chip">Type: {html.escape(p['page_type'])}</span></p>
  <p><strong>URL:</strong> <a href="{html.escape(p['url'])}" target="_blank" rel="noopener">{html.escape(p['url'])}</a></p>
  <p><strong>หน้าที่ของหน้านี้:</strong> {html.escape(p.get('purpose', ''))}</p>
  <h3>วิธีใช้งาน (แนะนำ)</h3>
  <ol>{steps_html}</ol>
  <h3>องค์ประกอบสำคัญที่พบ</h3>
  <p><strong>Heading:</strong> {html.escape(meta.get('heading') or '-')}</p>
  <p><strong>Document Title:</strong> {html.escape(meta.get('document_title') or '-')}</p>
  <p><strong>มีฟอร์ม:</strong> {'Yes' if meta.get('has_form') else 'No'} | <strong>มีตาราง:</strong> {'Yes' if meta.get('has_table') else 'No'}</p>
  <div class="grid2">
    <div>
      <h4>Buttons/Actions</h4>
      <ul>{li_list(buttons[:18])}</ul>
    </div>
    <div>
      <h4>Form Labels</h4>
      <ul>{li_list(labels[:18])}</ul>
    </div>
  </div>
  <div class="grid2">
    <div>
      <h4>Table Headers</h4>
      <ul>{li_list(headers[:22])}</ul>
    </div>
    <div>
      <h4>Alerts / Badges</h4>
      <ul>{li_list((alerts + badges)[:22])}</ul>
    </div>
  </div>
  {err_html}
  <figure class="shot">
    <img src="doc3_images/{html.escape(p['screenshot'])}" alt="{html.escape(p['menu_label'])}">
    <figcaption>ภาพหน้าจอ: {html.escape(p['menu_label'])}</figcaption>
  </figure>
</section>
""")

    type_cards = ''.join(
        f"<div class='kpi'><div class='k'>{html.escape(k)}</div><div class='v'>{v}</div></div>"
        for k, v in sorted(types.items(), key=lambda x: x[0])
    )

    doc = f"""<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>doc3 - Stock2 Full Menu Manual</title>
  <style>
    :root {{
      --bg:#f3f7fc;
      --card:#fff;
      --line:#d9e4f2;
      --text:#0f172a;
      --sub:#334155;
      --brand:#0b5fff;
    }}
    *{{box-sizing:border-box;}}
    body{{margin:0;font-family:"Segoe UI",Tahoma,sans-serif;background:var(--bg);color:var(--text);line-height:1.58;}}
    .wrap{{max-width:1320px;margin:20px auto;padding:0 14px 24px;}}
    .hero,.card{{background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:0 4px 16px rgba(15,23,42,.05);}}
    .hero{{padding:18px;margin-bottom:12px;}}
    .card{{padding:14px;margin-bottom:12px;}}
    h1{{margin:0 0 8px;font-size:28px;}}
    h2{{margin:0 0 8px;font-size:22px;border-left:5px solid var(--brand);padding-left:10px;}}
    h3{{margin:10px 0 6px;font-size:17px;}}
    h4{{margin:8px 0 6px;font-size:15px;}}
    p{{margin:6px 0;color:var(--sub);}}
    ul,ol{{margin:6px 0 6px 20px;color:var(--sub);}}
    li{{margin:2px 0;}}
    .chip{{display:inline-block;margin:0 6px 4px 0;padding:3px 9px;border-radius:999px;background:#eaf1ff;border:1px solid #c7d7fb;color:#1e3a8a;font-size:12px;}}
    .chips{{margin-top:6px;}}
    .summary{{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px;margin-top:8px;}}
    .kpi{{border:1px solid #d5e1f4;border-radius:10px;padding:10px;background:#f9fbff;}}
    .k{{font-size:12px;color:#465e7b;}}
    .v{{font-size:24px;font-weight:700;color:#123a6f;}}
    table{{width:100%;border-collapse:collapse;}}
    th,td{{border:1px solid #d8e3f2;padding:6px 8px;font-size:13px;vertical-align:top;}}
    th{{background:#eef4ff;text-align:left;}}
    .page{{scroll-margin-top:20px;}}
    .grid2{{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;}}
    .shot{{margin:10px 0 0;border:1px solid #ccd9ea;border-radius:10px;overflow:hidden;background:#fff;}}
    .shot img{{display:block;width:100%;height:auto;}}
    .shot figcaption{{padding:8px 10px;font-size:12px;color:#4a6482;background:#f7faff;border-top:1px solid #d5e1f1;}}
    .err{{color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;padding:8px;border-radius:8px;}}
    a{{color:#1d4ed8;text-decoration:none;}}
    a:hover{{text-decoration:underline;}}
  </style>
</head>
<body>
  <div class="wrap">
    <section class="hero">
      <h1>doc3: คู่มือใช้งาน Stock2 ครบทุกเมนู (Full Menu Walkthrough)</h1>
      <p>เอกสารนี้สร้างจากการไล่เปิดเมนูจริงทุกตัวที่บัญชี <code>{html.escape(USERNAME)}</code> เข้าถึงได้ในระบบ <code>{html.escape(BASE_URL)}</code> แล้วจับภาพหน้าจอทุกหน้า พร้อมสรุปวิธีใช้งานและองค์ประกอบสำคัญ.</p>
      <div class="chips">
        <span class="chip">Generated at: {html.escape(payload['generated_at'])}</span>
        <span class="chip">Total pages captured: {payload['total_pages']}</span>
        <span class="chip">Doc file: docs/doc3.html</span>
        <span class="chip">Images: docs/doc3_images/</span>
      </div>
      <div class="summary">{type_cards}</div>
    </section>

    <section class="card">
      <h2>ภาพรวมการใช้งานระบบ</h2>
      <ol>
        <li>เริ่มจากหน้า Login และเข้าสู่ระบบด้วยบัญชีที่มีสิทธิ์.</li>
        <li>เลือกเมนูจาก Sidebar ด้านซ้ายตามแผนก/บทบาทงาน.</li>
        <li>ในหน้า Module ให้ทำงาน CRUD + เรียกรายงานตามสิทธิ์ที่กำหนด.</li>
        <li>ใช้หน้า Access Control สำหรับกำหนดสิทธิ์ผู้ใช้/แผนก.</li>
        <li>ใช้หน้า Capture Screen เพื่อเก็บหลักฐานการทดสอบหรือขั้นตอนปฏิบัติงาน.</li>
      </ol>
      <p>หมายเหตุ: เอกสารนี้ครอบคลุมทุกเมนูที่แสดงจริงในบัญชีทดสอบขณะสร้างเอกสาร.</p>
    </section>

    <section class="card">
      <h2>สารบัญทุกหน้า</h2>
      <table>
        <thead><tr><th>#</th><th>Group</th><th>Menu</th><th>Type</th></tr></thead>
        <tbody>{''.join(nav_rows)}</tbody>
      </table>
    </section>

    {''.join(sections)}
  </div>
</body>
</html>
"""

    HTML_PATH.write_text(doc, encoding='utf-8')

    print(f"OK total_pages={len(pages)} html={HTML_PATH}")


if __name__ == '__main__':
    main()
