"""
Генерує демонстраційну архівну справу: 4 «скани» аркушів (машинопис і рукопис на старому папері)
і seed-файл з результатом розпізнавання для Laravel (admin/database/seeders/data/demo-case.json).
Документи й установи вигадані, зображення згенеровані: це НЕ справжні архівні документи.

python3 -I tools/make_demo_case.py <admin dir>
"""
import json, os, random, sys, textwrap
from PIL import Image, ImageDraw, ImageFilter, ImageFont

admin = sys.argv[1]
CASE_DIR = 'Київська область/Демонстраційний архів/Ф. Р-101 Вишнівська трудова школа (демо)/Опис 1/Справа 7 Листування про ремонт школи'
out_dir = os.path.join(admin, 'storage/demo-scans', CASE_DIR)
os.makedirs(out_dir, exist_ok=True)

TYPE = '/usr/share/fonts/truetype/liberation/LiberationMono-Regular.ttf'
HAND = '/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf'
W, H = 1700, 2400
CODE = 'Ф. Р-101, оп. 1, спр. 7'

# Кожна сторінка — блоки тексту; блок належить документу (doc) і полю (field) для рамок на скані.
DOCS = {
    1: {
        'record_no': '1', 'doc_index': '№ 45', 'doc_date': '12 березня 1927 р.', 'sheets': 'арк. 1–2', 'archive_code': CODE + ', арк. 1–2',
        'doc_type': 'Лист', 'title': 'Лист завідувача Вишнівської трудової школи до Окружної інспектури народної освіти про ремонт даху й печей шкільного будинку',
        'description': 'Завідувач школи повідомляє, що дах шкільного будинку протікає, дві печі зруйновані, заняття у двох класах припинено; просить виділити кошти й матеріали на ремонт до початку навчального року.',
        'author': 'Вишнівська трудова школа, завідувач Т. Гнатюк', 'addressee': 'Окружна інспектура народної освіти', 'place': 'с. Вишнівка',
        'language': 'українська', 'authenticity': 'Оригінал', 'reproduction': 'Машинопис', 'persons': 'Гнатюк Т.', 'places': 'Вишнівка, село',
        'organizations': 'Вишнівська трудова школа; Окружна інспектура народної освіти', 'condition': 'Задовільний',
        'notes': 'Резолюція в лівому верхньому куті: «До кошторису. 15.III.27». Штамп вхідної кореспонденції.',
    },
    2: {
        'record_no': '2', 'doc_index': '№ 112', 'doc_date': '18 березня 1927 р.', 'sheets': 'арк. 2 зв.', 'archive_code': CODE + ', арк. 2 зв.',
        'doc_type': 'Довідка', 'title': 'Довідка Вишнівської сільської ради про кількість учнів трудової школи',
        'description': 'Сільрада засвідчує, що школу відвідують 118 учнів із Вишнівки та двох сусідніх хуторів.',
        'author': 'Вишнівська сільська рада', 'addressee': None, 'place': 'с. Вишнівка', 'language': 'українська', 'authenticity': 'Оригінал',
        'reproduction': 'Рукопис', 'persons': 'Ковальчук П., голова сільради', 'places': 'Вишнівка, село; Зелений Гай, хутір; Липовий, хутір',
        'organizations': 'Вишнівська сільська рада; Вишнівська трудова школа', 'condition': 'Згасання тексту', 'notes': 'Кругла печатка сільради.',
    },
    3: {
        'record_no': '3', 'doc_index': '№ 3', 'doc_date': '2 квітня 1927 р.', 'sheets': 'арк. 3', 'archive_code': CODE + ', арк. 3',
        'doc_type': 'Протокол', 'title': 'Протокол № 3 засідання шкільної ради Вишнівської трудової школи про ремонт шкільного будинку',
        'description': 'Шкільна рада ухвалила провести ремонт силами батьків, заготувати 2 куб. сажні дров і просити сільраду про підводи.',
        'author': 'Шкільна рада Вишнівської трудової школи', 'addressee': None, 'place': 'с. Вишнівка', 'language': 'українська',
        'authenticity': 'Оригінал', 'reproduction': 'Машинопис', 'persons': 'Гнатюк Т.; Савчук О.; Мельник І.', 'places': 'Вишнівка, село',
        'organizations': 'Вишнівська трудова школа; Вишнівська сільська рада', 'condition': 'Добрий', 'notes': 'Підписи голови й секретаря.',
    },
    4: {
        'record_no': '4', 'doc_index': None, 'doc_date': '1928 р.', 'sheets': 'арк. 4', 'archive_code': CODE + ', арк. 4',
        'doc_type': 'Кошторис', 'title': 'Кошторис на ремонт даху й печей Вишнівської трудової школи',
        'description': 'Кошторис витрат на покрівельне залізо, цеглу, глину й роботу пічника; разом 186 крб. 40 коп.',
        'author': 'Вишнівська трудова школа', 'addressee': None, 'place': None, 'language': 'українська', 'authenticity': 'Чернетка',
        'reproduction': 'Рукопис', 'persons': None, 'places': None, 'organizations': 'Вишнівська трудова школа', 'condition': 'Пошкоджений',
        'notes': 'Правий нижній кут відірвано, частину суми не видно.',
    },
}

PAGES = [
    {'file': 'arch_0001.jpg', 'label': 'арк. 1', 'blocks': [
        (1, 'notes', 'hand', 'До кошторису. 15.III.27', 'left'),
        (1, 'author', 'type', 'ВИШНІВСЬКА ТРУДОВА ШКОЛА\nс. Вишнівка', 'left'),
        (1, 'doc_index', 'type', '12 березня 1927 р.   № 45', 'left'),
        (1, 'addressee', 'type', 'До Окружної інспектури\nнародньої освіти', 'right'),
        (1, 'full_text', 'type',
         'Доводжу до відома Інспектури, що дах шкільного будинку за зиму прийшов у '
         'зовсім негодящий стан і протікає над двома класами. Дві печі зруйновані, '
         'через що заняття в 3-ій та 4-ій групі з 1 березня припинено, а діти '
         'перебувають у першій групі по 50 чоловік у одному приміщенні.\n'
         'Шкільна рада та батьки дітей згодні допомогти роботою, але грошей на '
         'покрівельне залізо, цеглу та на оплату пічника не мають.', 'left'),
    ]},
    {'file': 'arch_0002.jpg', 'label': 'арк. 2–2 зв.', 'blocks': [
        (1, 'full_text', 'type',
         'Через це прошу Інспектуру виділити кошти та матеріали на ремонт до '
         'початку навчального року, згідно з кошторисом, що додається.', 'left'),
        (1, 'persons', 'type', 'Завідувач школи          Т. Гнатюк', 'left'),
        (2, 'author', 'hand', 'Вишнівська сільська рада\n18 березня 1927 р. № 112', 'left'),
        (2, 'full_text', 'hand',
         'Довідка. Дана Вишнівській трудшколі в тім, що школу відвідують 118 учнів '
         'з с. Вишнівки та хуторів Зелений Гай і Липовий, що й засвідчується.\n'
         'Голова сільради   П. Ковальчук', 'left'),
    ]},
    {'file': 'arch_0003.jpg', 'label': 'арк. 3', 'blocks': [
        (3, 'title', 'type', 'ПРОТОКОЛ № 3\nзасідання шкільної ради Вишнівської трудшколи\nвід 2 квітня 1927 року', 'center'),
        (3, 'persons', 'type', 'Присутні: Гнатюк Т., Савчук О., Мельник І. та 9 батьків.', 'left'),
        (3, 'full_text', 'type',
         'Слухали: Інформацію т. Гнатюка про стан шкільного будинку.\n'
         'Ухвалили: 1. Ремонт даху провести силами батьків у травні. '
         '2. Заготувати на зиму 2 куб. сажні дров. 3. Просити сільраду про '
         'підводи для перевезення цегли та глини.', 'left'),
        (3, 'notes', 'type', 'Голова  О. Савчук        Секретар  І. Мельник', 'left'),
    ]},
    {'file': 'arch_0004.jpg', 'label': 'арк. 4', 'blocks': [
        (4, 'title', 'hand', 'Кошторис на ремонт шкільного будинку', 'center'),
        (4, 'full_text', 'hand',
         '1. Залізо покрівельне, 40 листів — 92 крб.\n'
         '2. Цегла, 800 шт. — 36 крб.\n'
         '3. Глина та пісок, 4 підводи — 8 крб. 40 коп.\n'
         '4. Робота пічника — 50 крб.\n'
         'Разом: 186 крб. 4[...]', 'left'),
    ]},
]

random.seed(11)
pages_out = []
for page in PAGES:
    img = Image.new('RGB', (W, H), (234, 224, 196))
    d = ImageDraw.Draw(img)
    for _ in range(50):
        x, y, r = random.randint(0, W), random.randint(0, H), random.randint(30, 220)
        d.ellipse((x - r, y - r, x + r, y + r), fill=(226 + random.randint(-8, 4), 214 + random.randint(-8, 4), 182 + random.randint(-10, 4)))
    img = img.filter(ImageFilter.GaussianBlur(22))
    d = ImageDraw.Draw(img)
    # номер аркуша олівцем у правому верхньому куті (архівна нумерація)
    d.text((W - 170, 60), page['label'].replace('арк. ', '').split('–')[0], font=ImageFont.truetype(HAND, 44), fill=(90, 90, 100))
    y = 170
    recs = {}
    for doc, field, kind, text, align in page['blocks']:
        font = ImageFont.truetype(TYPE if kind == 'type' else HAND, 34 if kind == 'type' else 40)
        ink = (35, 35, 45) if kind == 'type' else (30, 45, 115)
        width = 62 if kind == 'type' else 58
        lines = []
        for para in text.split('\n'):
            lines += textwrap.wrap(para, width) or ['']
        lh = 50 if kind == 'type' else 56
        x0 = 150
        top = y
        maxw = 0
        for li, line in enumerate(lines):
            tw = d.textlength(line, font=font)
            x = x0 if align == 'left' else (W - 150 - tw if align == 'right' else (W - tw) / 2)
            jitter = random.randint(-2, 2)
            d.text((x + jitter, y), line, font=font, fill=ink)
            maxw = max(maxw, tw)
            y += lh
        box_x = x0 if align == 'left' else (W - 150 - maxw if align == 'right' else (W - maxw) / 2)
        region = {'x': round(box_x / W - 0.01, 4), 'y': round(top / H - 0.005, 4), 'w': round(maxw / W + 0.02, 4), 'h': round((y - top) / H + 0.01, 4)}
        r = recs.setdefault(doc, {'regions': {}, 'top': top, 'bottom': y})
        r['regions'].setdefault(field, region)
        r['bottom'] = y
        y += 40
    # пошкоджений кут на кошторисі
    if page['file'] == 'arch_0004.jpg':
        d.polygon([(W, H - 700), (W, H), (W - 600, H)], fill=(60, 55, 50))
    # штамп / печатка
    if page['file'] in ('arch_0001.jpg', 'arch_0002.jpg'):
        cx, cy = (1250, 330) if page['file'] == 'arch_0001.jpg' else (1000, 760)
        d.ellipse((cx - 120, cy - 120, cx + 120, cy + 120), outline=(90, 70, 150), width=6)
        d.ellipse((cx - 85, cy - 85, cx + 85, cy + 85), outline=(90, 70, 150), width=3)
    img = img.filter(ImageFilter.GaussianBlur(0.6)).rotate(random.uniform(-0.8, 0.8), fillcolor=(40, 40, 40))
    img.save(os.path.join(out_dir, page['file']), quality=82)
    pages_out.append({'filename': page['file'], 'label': page['label'], 'records': [
        {'doc': doc, 'text': '\n'.join(bl[3] for bl in page['blocks'] if bl[0] == doc), 'regions': r['regions'],
         'row_box': {'x': 0.07, 'y': round(r['top'] / H - 0.01, 4), 'w': 0.86, 'h': round((r['bottom'] - r['top']) / H + 0.02, 4)}}
        for doc, r in recs.items()]})

# повний текст документа = текст усіх його блоків по сторінках
for doc in DOCS:
    parts = []
    for p in PAGES:
        parts += [b[3] for b in p['blocks'] if b[0] == doc]
    DOCS[doc]['full_text'] = '\n'.join(parts)

json.dump({'case_dir': CASE_DIR, 'documents': DOCS, 'pages': pages_out}, open(os.path.join(admin, 'database/seeders/data/demo-case.json'), 'w'),
          ensure_ascii=False, indent=1)
print('ok', out_dir)
