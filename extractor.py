#!/usr/bin/env python3
"""
LISUN Manual — Word .docx 结构化提取器

输入: .docx 文件路径
输出: JSON（stdout）

输出结构:
{
  "ok": true,
  "title": "正文第一段非空文字（截断到100字）",
  "paragraphs": [
    {
      "type": "heading|paragraph|list_item|table_cell",
      "level": 1-6,        // 仅 heading 有
      "text": "请【b】布置【/b】设备",
      "source_hash": "md5"
    }
  ],
  "images": [
    {
      "internal_path": "word/media/image3.png",
      "name": "image3.png",
      "md5": "abc123...",
      "binary_base64": "...",
      "ext": "png"
    }
  ],
  "tables": [
    {
      "rows": [[{"text": "...", "colspan": 2, "rowspan": 1, "is_header": false}, ...], ...]
    }
  ]
}

内联格式占位符规则:
  【b】...【/b】          → <strong>
  【i】...【/i】          → <em>
  【u】...【/u】          → <u>
  【sub】...【/sub】      → <sub>
  【sup】...【/sup】      → <sup>
  【link:url】...【/link】→ <a href="url">
  【color:rrggbb】...【/color】→ <span style="color:#rrggbb">
"""

import sys, json, re, hashlib, base64, zipfile, os
from xml.etree import ElementTree as ET
from collections import defaultdict

# Windows PHP 通常继承 GBK 控制台编码；文档中的 ①…⑳ 等字符会让
# json.dumps(ensure_ascii=False) 在 stdout 编码阶段直接失败，进而触发
# PHPWord 备用解析器。提取器对外输出必须固定为 UTF-8。
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(encoding='utf-8')

W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
WPML = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing'
A = 'http://schemas.openxmlformats.org/drawingml/2006/main'
PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture'
R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'


# ── numbering.xml 解析 ──────────────────────────────────────────────

def parse_numbering(zf):
    """返回 (num_map, abstract_num_map)"""
    num_map = {}
    abstract_num_map = {}
    try:
        xml_bytes = zf.read('word/numbering.xml')
    except Exception:
        return num_map, abstract_num_map
    try:
        root = ET.fromstring(xml_bytes)
    except Exception:
        return num_map, abstract_num_map

    for an_elem in root.findall('{%s}abstractNum' % W):
        an_id = an_elem.get('{%s}abstractNumId' % W)
        if an_id is None:
            continue
        levels = {}
        for lvl_elem in an_elem.findall('{%s}lvl' % W):
            ilvl = lvl_elem.get('{%s}ilvl' % W)
            if ilvl is None:
                continue
            ilvl = int(ilvl)
            start_val = 0
            start_elem = lvl_elem.find('{%s}start' % W)
            if start_elem is not None:
                start_val = int(start_elem.get('{%s}val' % W, '0'))
            num_fmt = 'decimal'
            fmt_elem = lvl_elem.find('{%s}numFmt' % W)
            if fmt_elem is not None:
                num_fmt = fmt_elem.get('{%s}val' % W, 'decimal')
            lvl_text = ''
            lt_elem = lvl_elem.find('{%s}lvlText' % W)
            if lt_elem is not None:
                lvl_text = lt_elem.get('{%s}val' % W, '')
            levels[ilvl] = {'fmt': num_fmt, 'start': start_val, 'lvlText': lvl_text}
        abstract_num_map[an_id] = levels

    for num_elem in root.findall('{%s}num' % W):
        num_id = num_elem.get('{%s}numId' % W)
        ref_elem = num_elem.find('{%s}abstractNumId' % W)
        if num_id is not None and ref_elem is not None:
            num_map[num_id] = ref_elem.get('{%s}val' % W)
    return num_map, abstract_num_map


# ── 编号格式化 ──────────────────────────────────────────────────────

def format_number(fmt, n):
    if fmt == 'decimal':
        return str(n)
    elif fmt == 'upperLetter':
        return chr(64 + n) if 1 <= n <= 26 else str(n)
    elif fmt == 'lowerLetter':
        return chr(96 + n) if 1 <= n <= 26 else str(n)
    elif fmt == 'upperRoman':
        return to_roman(n).upper()
    elif fmt == 'lowerRoman':
        return to_roman(n).lower()
    elif fmt in ('chineseCountingThousand', 'chineseCounting'):
        return to_chinese_number(n)
    elif fmt == 'chineseLegalSimplified':
        return to_chinese_legal(n)
    elif fmt == 'bullet':
        return '•'
    elif fmt == 'decimalZero':
        return '%02d' % n
    elif fmt == 'decimalEnclosedCircle':
        # ① ② ③ ... ⑳ (U+2460–U+2473), 1-indexed
        if 1 <= n <= 20:
            return chr(0x245F + n)
        return '(%d)' % n
    elif fmt == 'decimalEnclosedParen':
        # ⑴ ⑵ ⑶ ... ⒇ (U+2474–U+2487), 1-indexed
        if 1 <= n <= 20:
            return chr(0x2473 + n)
        return '(%d)' % n
    elif fmt == 'decimalEnclosedFullstop':
        # ⒈ ⒉ ⒊ ... ⒛ (U+2488–U+249B), 1-indexed
        if 1 <= n <= 20:
            return chr(0x2487 + n)
        return '%d.' % n
    elif fmt == 'ideographEnclosedCircle':
        # ㊀ ㊁ ㊂ ... ㊉ (U+3280–U+3289), 1-indexed, only 1-10
        if 1 <= n <= 10:
            return chr(0x327F + n)
        return str(n)
    else:
        return str(n)


def to_roman(n):
    val = [1000, 900, 500, 400, 100, 90, 50, 40, 10, 9, 5, 4, 1]
    sym = ['m', 'cm', 'd', 'cd', 'c', 'xc', 'l', 'xl', 'x', 'ix', 'v', 'iv', 'i']
    result = ''
    for i in range(len(val)):
        while n >= val[i]:
            result += sym[i]
            n -= val[i]
    return result


def to_chinese_number(n):
    digits = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九']
    if n < 10:
        return digits[n]
    elif n < 20:
        return '十' + (digits[n % 10] if n % 10 > 0 else '')
    elif n < 100:
        tens = digits[n // 10] + '十'
        ones = digits[n % 10] if n % 10 > 0 else ''
        return tens + ones
    return str(n)


def to_chinese_legal(n):
    """数字 → 中文大写数字（壹贰叁...）"""
    digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖']
    if n < 10:
        return digits[n]
    elif n < 20:
        return '拾' + (digits[n % 10] if n % 10 > 0 else '')
    elif n < 100:
        tens = digits[n // 10] + '拾'
        ones = digits[n % 10] if n % 10 > 0 else ''
        return tens + ones
    return str(n)


# ── 内联格式 → 占位符 ────────────────────────────────────────────────

def runs_to_text(runs_elem, rels_map=None):
    """合并段落内所有 w:r 元素，内联格式转占位符

    返回 (plain_text, has_any_inline_format)
    """
    parts = []
    open_tags = []  # 栈: ['b', 'i', ...]

    # Word 可能把 run 包在 hyperlink、w:ins、w:sdt 等节点中。
    # 只读取直接子节点会导致表格单元格的说明文字被静默丢弃。
    for r_elem in runs_elem.findall('.//{%s}r' % W):
        rpr = r_elem.find('{%s}rPr' % W)

        # 收集当前 run 的格式标记
        current_tags = []
        is_link = False
        link_url = ''

        if rpr is not None:
            if rpr.find('{%s}b' % W) is not None:
                current_tags.append('b')
            if rpr.find('{%s}i' % W) is not None:
                current_tags.append('i')
            if rpr.find('{%s}u' % W) is not None:
                current_tags.append('u')
            if rpr.find('{%s}vertAlign' % W) is not None:
                va = rpr.find('{%s}vertAlign' % W).get('{%s}val' % W, '')
                if va == 'subscript':
                    current_tags.append('sub')
                elif va == 'superscript':
                    current_tags.append('sup')
            # 颜色
            color_elem = rpr.find('{%s}color' % W)
            if color_elem is not None:
                cval = color_elem.get('{%s}val' % W, '')
                if cval and cval != 'auto' and cval != '000000':
                    current_tags.append('color:' + cval)

        # 超链接
        link_elem = r_elem.find('{%s}instrText' % W)
        if link_elem is not None and link_elem.text:
            instr = link_elem.text.strip()
            m = re.search(r'HYPERLINK\s+"([^"]*)"', instr)
            if m:
                is_link = True
                link_url = m.group(1)

        # 提取文字
        # 除 w:t 外，DrawingML 文本框也可能使用 a:t；按本地名读取，
        # 避免特殊 Word 容器中的可见文字被漏掉。
        text = ''.join(
            node.text or ''
            for node in r_elem.iter()
            if node.tag.split('}')[-1] in ('t', 'delText')
        )

        # 也检查 tab/br （制表符和换行）
        if r_elem.find('{%s}tab' % W) is not None:
            text = '\t'
        if r_elem.find('{%s}br' % W) is not None:
            text += '\n'

        if not text:
            continue

        # 关闭不再匹配的标签
        to_close = []
        for i, tag in enumerate(open_tags):
            if tag.startswith('link'):
                # link 特殊处理：链接结束或被非链接 run 打断
                if not is_link:
                    to_close.append(i)
            elif tag not in current_tags:
                to_close.append(i)

        for i in reversed(to_close):
            tag = open_tags.pop(i)
            if tag.startswith('link:'):
                parts.append('【/link】')
            elif tag.startswith('color:'):
                # 颜色的开标签携带色值，闭标签统一为 /color；
                # 避免生成 【/color:333333】，下游转换器无法匹配。
                parts.append('【/color】')
            else:
                parts.append('【/%s】' % tag)

        # 开启新的格式标签
        if is_link and not any(t.startswith('link') for t in open_tags):
            open_tags.append('link:' + (link_url or ''))
            parts.append('【link:%s】' % (link_url or ''))

        for tag in current_tags:
            if tag not in open_tags:
                open_tags.append(tag)
                parts.append('【%s】' % tag)

        parts.append(text)

    # 关闭所有剩余标签
    for tag in reversed(open_tags):
        if tag.startswith('link:'):
            parts.append('【/link】')
        elif tag.startswith('color:'):
            parts.append('【/color】')
        else:
            parts.append('【/%s】' % tag)

    full_text = ''.join(parts)
    has_inline = bool(re.search(r'【[^/\]]', full_text))
    return full_text, has_inline


def iter_cell_paragraphs(cell_elem):
    """递归取得单元格中的段落，但不进入嵌套表格。"""
    for child in list(cell_elem):
        local_name = child.tag.split('}')[-1] if '}' in child.tag else child.tag
        if local_name == 'p':
            yield child
        elif local_name not in ('tcPr', 'tbl'):
            yield from iter_cell_paragraphs(child)


# ── 图片提取 ─────────────────────────────────────────────────────────

def extract_images(zf):
    """从 word/media/ 提取所有图片，返回列表"""
    images = []
    for name in zf.namelist():
        if not name.lower().startswith('word/media/'):
            continue
        ext = os.path.splitext(name)[1].lower().lstrip('.')
        if ext not in ('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'tif'):
            continue
        try:
            binary = zf.read(name)
            if not binary:
                continue
        except Exception:
            continue

        md5 = hashlib.md5(binary).hexdigest()
        images.append({
            'internal_path': name,
            'name': os.path.basename(name),
            'md5': md5,
            'binary_base64': base64.b64encode(binary).decode('ascii'),
            'ext': ext if ext != 'jpeg' else 'jpg',
        })
    return images


# ── 段落类型检测 ─────────────────────────────────────────────────────

def detect_paragraph_type(p_elem):
    """返回 (type, level)"""
    ppr = p_elem.find('{%s}pPr' % W)
    if ppr is None:
        return 'paragraph', 0

    # 检测 heading 样式
    pstyle = ppr.find('{%s}pStyle' % W)
    if pstyle is not None:
        style_val = pstyle.get('{%s}val' % W, '')
        m = re.match(r'^(\d+)$', style_val)
        if m:
            # Heading1-6 也可能直接用数字样式名
            level = int(m.group(1))
            if 1 <= level <= 6:
                return 'heading', level
        m = re.match(r'^(?:heading|Heading|标题)\s*(\d+)', style_val)
        if m:
            level = int(m.group(1))
            if 1 <= level <= 6:
                return 'heading', level

    # 检测 outline level (某些模板用这个标记标题层级)
    ol = ppr.find('{%s}outlineLvl' % W)
    if ol is not None:
        level = int(ol.get('{%s}val' % W, '0')) + 1
        if 1 <= level <= 6:
            return 'heading', level

    # 检测编号
    num_pr = ppr.find('{%s}numPr' % W)
    if num_pr is not None:
        return 'list_item', 0

    return 'paragraph', 0


# ── 域代码处理 (LISTNUM/AUTONUM) ─────────────────────────────────────

def resolve_fields(p_elem):
    """将域代码转为纯文本，返回是否修改"""
    runs = list(p_elem.findall('{%s}r' % W))
    fld_runs = [r for r in runs if r.find('{%s}fldChar' % W) is not None]
    if not fld_runs:
        return False

    modified = False
    new_runs = []
    i = 0

    while i < len(runs):
        r = runs[i]
        fld = r.find('{%s}fldChar' % W)
        if fld is not None and fld.get('{%s}fldCharType' % W) == 'begin':
            sep_idx = None
            end_idx = None
            instr_text = ''

            for j in range(i + 1, len(runs)):
                fj = runs[j].find('{%s}fldChar' % W)
                if fj is not None:
                    ft = fj.get('{%s}fldCharType' % W)
                    if ft == 'begin':
                        break
                    elif ft == 'separate' and sep_idx is None:
                        sep_idx = j
                    elif ft == 'end':
                        end_idx = j
                        break
                # 收集指令文字 (instrText)
                if sep_idx is None:
                    it = runs[j].find('{%s}instrText' % W)
                    if it is not None and it.text:
                        instr_text += it.text

            if sep_idx is not None and end_idx is not None:
                # 提取 field result text。结果通常是 w:t，但 Word 也可能
                # 把结果放进 w:ins/w:sdt 等容器；递归读取可见文字。
                result_text = ''
                for j in range(sep_idx + 1, end_idx):
                    for t in runs[j].iter():
                        if t.tag.split('}')[-1] in ('t', 'delText') and t.text:
                            result_text += t.text

                # 有些文档保存域时没有缓存结果。当前文档使用的是
                # “= 1 \\* GB3” 这类域指令，直接补出带圈数字，避免
                # 为了编号而重写整份 DOCX。
                if not result_text:
                    m = re.search(r'=\s*(\d+)\s+\\\*\s*GB3', instr_text, re.I)
                    if m:
                        number = int(m.group(1))
                        if 1 <= number <= 20:
                            result_text = chr(0x245F + number)

                if result_text:
                    nr = ET.SubElement(p_elem, '{%s}r' % W) if False else ET.Element('{%s}r' % W)
                    # 简化：直接创建带文本的 run
                    nr = ET.Element('{%s}r' % W)
                    t_el = ET.SubElement(nr, '{%s}t' % W)
                    t_el.set('{http://www.w3.org/XML/1998/namespace}space', 'preserve')
                    t_el.text = result_text
                    new_runs.append(nr)
                    modified = True
                    i = end_idx + 1
                    continue

            new_runs.append(r)
            i += 1
        else:
            new_runs.append(r)
            i += 1

    if modified:
        for r in runs:
            p_elem.remove(r)
        for r in new_runs:
            p_elem.append(r)

    return modified


# ── 编号解析 ─────────────────────────────────────────────────────────

def resolve_numbering(p_elem, num_map, abstract_num_map, counters):
    """解析段落的编号，返回编号文字或 None"""
    ppr = p_elem.find('{%s}pPr' % W)
    if ppr is None:
        return None
    num_pr = ppr.find('{%s}numPr' % W)
    if num_pr is None:
        return None

    num_id_elem = num_pr.find('{%s}numId' % W)
    ilvl_elem = num_pr.find('{%s}ilvl' % W)
    if num_id_elem is None:
        return None

    num_id = num_id_elem.get('{%s}val' % W)
    ilvl = int(ilvl_elem.get('{%s}val' % W, '0')) if ilvl_elem is not None else 0

    an_id = num_map.get(num_id)
    if an_id is None or an_id not in abstract_num_map:
        return None
    levels = abstract_num_map[an_id]
    lvl_info = levels.get(ilvl, levels.get(0))
    if lvl_info is None:
        return None

    # 计数器递增
    key = (num_id, ilvl)
    if key not in counters:
        counters[key] = lvl_info['start']
    counters[key] += 1

    num_str = format_number(lvl_info['fmt'], counters[key])
    lvl_text = lvl_info.get('lvlText', '%1.')
    display = lvl_text.replace('%1', num_str).replace('%2', '').replace('%3', '')
    display = re.sub(r'%\d+', '', display)

    # 移除 numPr（避免 PhpWord 二次处理）
    ppr.remove(num_pr)

    return display


# ── 主处理 ───────────────────────────────────────────────────────────

def process_docx(filepath):
    with zipfile.ZipFile(filepath, 'r') as zf:
        # 1. 提取图片
        images = extract_images(zf)

        # 2. 读取 document.xml
        doc_xml = zf.read('word/document.xml')

        # 3. 解析编号定义
        num_map, abstract_num_map = parse_numbering(zf)

    doc_root = ET.fromstring(doc_xml)
    body = doc_root.find('{%s}body' % W)
    if body is None:
        return {'ok': False, 'error': 'No body in document.xml'}

    paragraphs = []
    tables_out = []
    numbered_count = 0
    field_count = 0
    counters = {}
    image_index = 0  # 文档内图片出现顺序
    order = 0        # 段落/表格在文档中的先后顺序（供 PHP 侧还原交叉顺序）

    # 遍历 body 的直接子元素（按文档顺序：段落和表格交替）
    for elem in body:
        tag = elem.tag.split('}')[-1] if '}' in elem.tag else elem.tag
        order += 1

        if tag == 'p':
            # 处理域
            if resolve_fields(elem):
                field_count += 1

            # 检测段落类型
            ptype, level = detect_paragraph_type(elem)

            # 解析编号
            num_text = resolve_numbering(elem, num_map, abstract_num_map, counters)
            if num_text:
                numbered_count += 1

            # 提取文字（含内联格式占位符）
            text, has_inline = runs_to_text(elem)

            if num_text:
                text = num_text + ' ' + text

            # 也检查段落内是否包含图片（drawing）
            drawings = elem.findall('.//{%s}drawing' % W) if False else []
            for d in elem.findall('.//{%s}drawing' % W):
                # 从 drawing 中提取图片引用
                blip = d.find('.//{%s}blip' % A)
                if blip is not None:
                    embed = blip.get('{%s}embed' % R_NS, '')
                    if embed:
                        text += ' [IMAGE:%d] ' % image_index
                        image_index += 1

            # 有效内容才加入
            clean = text.strip()
            if clean or has_inline:
                entry = {
                    'type': ptype,
                    'text': text.strip(),
                    'source_hash': hashlib.md5(text.strip().encode('utf-8')).hexdigest(),
                }
                if ptype == 'heading':
                    entry['level'] = level
                entry['order'] = order
                paragraphs.append(entry)

        elif tag == 'tbl':
            # 表格处理
            table_data = []
            rows = elem.findall('{%s}tr' % W)
            for row_elem in rows:
                row_cells = []
                cells = row_elem.findall('{%s}tc' % W)
                for cell_elem in cells:
                    cell_text_parts = []
                    for p in iter_cell_paragraphs(cell_elem):
                        # 表格单元格里的 LISTNUM/AUTONUM 域也必须先展开。
                        # 原来这里只调用 runs_to_text()，导致域编号被跳过，
                        # 例如 ①、② 会变成空白单元格。
                        if resolve_fields(p):
                            field_count += 1

                        num_text = resolve_numbering(
                            p, num_map, abstract_num_map, counters
                        )
                        if num_text:
                            numbered_count += 1

                        t, _ = runs_to_text(p)
                        if num_text:
                            t = num_text + ' ' + t
                        cell_text_parts.append(t)
                    cell_text = '\n'.join(cell_text_parts).strip()
                    if not cell_text:
                        # 兜底处理少数 Word XML：文字可能位于非标准容器中，
                        # 没有可遍历的 w:r，但仍然存在 w:t。不能因此把整格写成空白。
                        cell_text = ''.join(
                            node.text or ''
                            for node in cell_elem.iter()
                            if node.tag.split('}')[-1] in ('t', 'delText')
                        ).strip()

                    # colspan / rowspan
                    tc_pr = cell_elem.find('{%s}tcPr' % W)
                    colspan = 1
                    rowspan = 1
                    is_header = False
                    if tc_pr is not None:
                        gs = tc_pr.find('{%s}gridSpan' % W)
                        if gs is not None:
                            colspan = int(gs.get('{%s}val' % W, '1'))
                        vm = tc_pr.find('{%s}vMerge' % W)
                        if vm is not None:
                            val = vm.get('{%s}val' % W, 'continue')
                            if val == 'restart':
                                # rowspan 需要后续合并逻辑；这里只标记
                                pass
                        # 检测表头样式
                        tbl_header = cell_elem.find('.//{%s}tblHeader' % W)
                        if tbl_header is not None:
                            is_header = True

                    row_cells.append({
                        'text': cell_text,
                        'colspan': colspan,
                        'rowspan': rowspan,
                        'is_header': is_header,
                        'source_hash': hashlib.md5(cell_text.encode('utf-8')).hexdigest(),
                    })
                table_data.append(row_cells)
            if table_data:
                tables_out.append({'rows': table_data, 'order': order})

    # 提取标题：正文第一个非空段落
    title = ''
    for p in paragraphs:
        t = p['text'].strip()
        # 去掉占位符取纯文字
        clean_title = re.sub(r'【[^】]*】', '', t).strip()
        if clean_title:
            title = clean_title[:100]
            break

    return {
        'ok': True,
        'title': title,
        'paragraphs': paragraphs,
        'images': images,
        'tables': tables_out,
        'stats': {
            'extractor_version': 'table-fields-v5-direct-xml-runs-utf8',
            'paragraphs': len(paragraphs),
            'images': len(images),
            'tables': len(tables_out),
            'numbered': numbered_count,
            'fields_resolved': field_count,
        },
    }


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'ok': False, 'error': 'Usage: extractor.py <docx_path>'}, ensure_ascii=False))
        sys.exit(1)

    filepath = sys.argv[1]
    if not os.path.exists(filepath):
        print(json.dumps({'ok': False, 'error': 'File not found: ' + filepath}, ensure_ascii=False))
        sys.exit(1)

    try:
        result = process_docx(filepath)
        print(json.dumps(result, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({'ok': False, 'error': str(e)}, ensure_ascii=False), file=sys.stderr)
        sys.exit(1)
