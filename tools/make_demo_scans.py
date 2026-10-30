"""
Генерує демонстраційні «скани» розворотів інвентарної книги з розпізнаного тексту зразка
(Інвентарна книга музею розпізнаний текст.xlsx), а також seed-файл для Laravel.
Це НЕ справжні фото: справжні JPG додаються у сховище й підтягуються синхронізацією.

python3 -I tools/make_demo_scans.py <sample.json> <admin dir>
"""
import json, os, random, sys, textwrap
from PIL import Image, ImageDraw, ImageFilter, ImageFont

sample = json.load(open(sys.argv[1]))
admin = sys.argv[2]
BOOK_DIR = 'Луганська область/Лисичанський краєзнавчий музей/Документальний фонд (Д)/Інвентарна книга Д-ІІІ (документи)'
out_dir = os.path.join(admin, 'storage/demo-scans', BOOK_DIR)
os.makedirs(out_dir, exist_ok=True)

FONT = '/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf'
FONT_HEAD = '/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf'
W, H = 3000, 2000
# ширини 12 граф у частках розвороту (ліва сторінка — графи 1–4, права — 5–12)
widths = [0.045, 0.06, 0.13, 0.25, 0.045, 0.07, 0.055, 0.075, 0.045, 0.07, 0.07, 0.085]
keys = ['record_no', 'record_date', 'acquisition_source', 'description', 'quantity', 'material', 'dimensions', 'condition', 'price', 'department', 'inventory_number', 'location']
xs = [0.02]
for w in widths:
    xs.append(xs[-1] + w)
scale = 0.96 / xs[-1]
xs = [0.02 + (x - 0.02) * scale for x in xs]

random.seed(7)
pages_out = []
for pi, page in enumerate(sample['pages']):
    fname = page['label'].split('Файл: ')[1].split(' ')[0]
    label = page['label'].split('(', 1)[1].rstrip(')') if '(' in page['label'] else ''
    img = Image.new('RGB', (W, H), (236, 228, 205))
    d = ImageDraw.Draw(img)
    # папір: плями та фон
    for _ in range(40):
        x, y, r = random.randint(0, W), random.randint(0, H), random.randint(20, 160)
        d.ellipse((x - r, y - r, x + r, y + r), fill=(228 + random.randint(-6, 4), 219 + random.randint(-6, 4), 192 + random.randint(-8, 4)))
    img = img.filter(ImageFilter.GaussianBlur(18))
    d = ImageDraw.Draw(img)
    d.line((W // 2, 0, W // 2, H), fill=(170, 160, 135), width=6)
    head_font = ImageFont.truetype(FONT_HEAD, 21)
    hand = ImageFont.truetype(FONT, 27)
    top, head_h = 90, 150
    ink = (40, 50, 110)
    grid = (120, 110, 95)
    for i, x in enumerate(xs):
        d.line((int(x * W), top, int(x * W), H - 60), fill=grid, width=2)
    d.line((int(xs[0] * W), top, int(xs[-1] * W), top), fill=grid, width=2)
    d.line((int(xs[0] * W), top + head_h, int(xs[-1] * W), top + head_h), fill=grid, width=3)
    for i, h in enumerate(sample['headers']):
        cw = int((xs[i + 1] - xs[i]) * W) - 10
        lines = textwrap.wrap(h, max(6, cw // 10))[:6]
        for li, line in enumerate(lines):
            d.text((int(xs[i] * W) + 5, top + 6 + li * 23), line, font=head_font, fill=(60, 55, 50))
    y = top + head_h + 14
    recs = []
    for row in page['rows']:
        heights, cells = [], []
        for i, val in enumerate(row):
            val = '' if val is None else str(val)
            cw = int((xs[i + 1] - xs[i]) * W) - 12
            lines = []
            for para in val.split('\n'):
                lines += textwrap.wrap(para, max(4, int(cw / 12.5))) or ['']
            cells.append(lines)
            heights.append(len(lines) * 31)
        rh = max(heights) + 18
        boxes = {}
        for i, lines in enumerate(cells):
            for li, line in enumerate(lines):
                jitter = random.randint(-2, 2)
                d.text((int(xs[i] * W) + 7, y + li * 31 + jitter), line, font=hand, fill=ink)
            if any(l.strip() for l in lines):
                boxes[keys[i]] = {'x': round(xs[i], 4), 'y': round((y - 4) / H, 4), 'w': round(xs[i + 1] - xs[i], 4), 'h': round((len(lines) * 31 + 8) / H, 4)}
        d.line((int(xs[0] * W), y + rh - 6, int(xs[-1] * W), y + rh - 6), fill=(150, 140, 120), width=1)
        recs.append({'row': row, 'regions': boxes, 'row_box': {'x': round(xs[0], 4), 'y': round((y - 6) / H, 4), 'w': round(xs[-1] - xs[0], 4), 'h': round(rh / H, 4)}})
        y += rh
    d.text((W // 4, H - 50), label, font=head_font, fill=(90, 80, 70))
    img = img.rotate(random.uniform(-0.6, 0.6), resample=Image.BICUBIC, fillcolor=(60, 55, 50))
    img.save(os.path.join(out_dir, fname), 'JPEG', quality=82)
    pages_out.append({'filename': fname, 'label': label, 'records': recs})

seed = {
    'book_dir': BOOK_DIR,
    'headers': sample['headers'],
    'keys': keys,
    'pages': pages_out,
    'rmfu_headers': sample['rmfu_headers'],
    'rmfu_rows': sample['rmfu_rows'],
}
os.makedirs(os.path.join(admin, 'database/seeders/data'), exist_ok=True)
json.dump(seed, open(os.path.join(admin, 'database/seeders/data/demo-book.json'), 'w'), ensure_ascii=False, indent=1)
print('ok', len(pages_out))
