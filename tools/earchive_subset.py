"""
Метадані документів одного розділу каталогу earchive.mfa.gov.ua з дампу сайту (репо emuseum-code/earchive,
backend/database/data/earchive.json.gz) → admin/database/data/earchive/category-<id>.json.

python3 -I tools/earchive_subset.py <earchive.json.gz> <category id> <out.json>
"""
import gzip, json, sys

d = json.load(gzip.open(sys.argv[1]))
cat_id = int(sys.argv[2])
uk = lambda t, k, n='name': {x[k]: x.get(n) for x in d[t] if x['language_id'] == 1}
funds = uk('document_funds_description', 'fund_id')
langs = uk('document_languages_description', 'document_language_id')
countries = uk('document_country_description', 'country_id')
orig = uk('document_originality_description', 'originality_id')
tech = uk('document_technics_description', 'technics_id')
cats = uk('document_category_description', 'category_id')
desc = {x['case_id']: x for x in d['cases_description'] if x['language_id'] == 1}
ids = lambda v: [int(i) for i in json.loads(v)] if v else []
docs = []
for c in sorted((c for c in d['cases'] if c['category_id'] == cat_id and c.get('file')), key=lambda c: c['id']):
    x = desc.get(c['id'], {})
    docs.append({
        'id': c['id'], 'url': 'https://earchive.mfa.gov.ua/catalog/' + (c['seo_url'] or str(c['id'])),
        'file': c['file'], 'archive': funds.get(c['fund_id']), 'fund': c['number_fund'], 'fund_name': x.get('fund_name'),
        'inventory': c['number_fund_description'], 'case': c['number_fund_work'], 'sheets': c['number_fund_page'],
        'date': c['date'], 'title': x.get('name'), 'description': x.get('description'),
        'languages': [langs.get(i) for i in ids(c['document_language_id'])], 'countries': [countries.get(i) for i in ids(c['country_id'])],
        'authenticity': orig.get(c['originality_id']), 'reproduction': [tech.get(i) for i in ids(c['technics_id'])],
    })
json.dump({'category': cats.get(cat_id), 'category_id': cat_id, 'source': 'https://earchive.mfa.gov.ua/catalog?category[]=%d' % cat_id, 'documents': docs},
          open(sys.argv[3], 'w'), ensure_ascii=False, indent=1)
print(len(docs))
