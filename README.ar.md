# فُندُق — جاهز لوكلاء الذكاء الاصطناعي على PrestaShop (UCP، MCP)

🌍 [English](README.md) · [Français](README.fr.md) · **العربية**

**اجعل متجر PrestaShop قابلًا للاكتشاف والاستعلام من وكلاء الذكاء الاصطناعي — كتالوج وأسعار ومخزون في الوقت الفعلي. بنقرة واحدة، بلا إعداد.** يطبّق فُندُق بأمانة معايير مفتوحة منشورة — [UCP](https://ucp.dev) و[MCP](https://modelcontextprotocol.io)؛ ولا يخترع أيّ بروتوكول خاصّ به.

[![CI](https://github.com/fondouk-dev/fondouk/actions/workflows/ci.yml/badge.svg)](https://github.com/fondouk-dev/fondouk/actions/workflows/ci.yml)
![license](https://img.shields.io/badge/license-MIT-blue)
![prestashop](https://img.shields.io/badge/PrestaShop-8.2%20%7C%209.1-e2467d)
![ucp](https://img.shields.io/badge/UCP-2026--04--08-5b8def)
![status](https://img.shields.io/badge/read--only-strict-informational)

## لماذا «فُندُق»

كان *الفُندُق* هو الخان الذي يُؤوي التجّار المسافرين — المكان الذي تستقبل فيه المدينة **الوافدين من الطريق**. اسمٌ يليق بوحدةٍ تفتح واجهة المتجر لوكلاء الذكاء الاصطناعي. الاسم هو الاستعارة: متجرك، مِضيافٌ لكلّ من يأتي للتجارة.

## ماذا يفعل

حين يسأل المتسوّق ChatGPT أو Gemini أو Claude *«أين أشتري هذا؟»*، فإنّ متجرًا لا يتحدّث إلى الوكلاء لا يمكن الاستعلام منه في الوقت الفعلي — لا أسعار حيّة، لا مخزون، لا إجابات مُهيكَلة. فُندُق يعالج ذلك:

- يقدّم **ملفّ اكتشاف UCP** عند `/.well-known/ucp`.
- يكشف قدرة **الكتالوج** (بحث / استعلام / منتج) عبر **REST** و**MCP**.
- كلّ شيء يُولَّد باستبطان المتجر — الكتالوج، التشكيلات، المخزون، الأسعار العامّة، اللغات، العملات. لا شاشة إعدادات.
- **للقراءة فقط وللعامّ فقط**: ما يراه الزائر المجهول في واجهة المتجر هو ما يراه الوكيل. لا أكثر. لا بيانات عملاء، لا أسعار جملة/مجموعات، لا سلّة، لا دفع.

## بداية سريعة — التاجر

1. نزّل ملفّ الوحدة (`fondouk.zip`) من الإصدار (release)، أو ابنِه: `MODULE_NAME=fondouk ./build/package.sh`.
2. في لوحة تحكّم PrestaShop: **الوحدات ← رفع وحدة ←** أفلِت الملفّ.
3. هذا كلّ شيء. الملفّ التعريفي متاح عند `https://your-shop/.well-known/ucp`.

المتطلّبات: PrestaShop **8.2** أو **9.1**، الروابط الودّية (URL مُبسّطة) مُفعّلة، وHTTPS. تعرض شاشة الوحدة تشخيصًا للبيئة (إعادة كتابة الروابط، HTTPS، تعارضات `.well-known`).

## بداية سريعة — الوكيل

اكتشِف ثمّ استعلِم — تُقرأ نقطة الوصول من الملفّ التعريفي، لا تُكتَب يدويًّا أبدًا:

```bash
# 1. الاكتشاف
curl -s https://demo.fondouk.dev/.well-known/ucp | jq '.ucp.services."dev.ucp.shopping"'

# 2. البحث في الكتالوج (نقطة الوصول من الملفّ التعريفي)
curl -s -X POST https://demo.fondouk.dev/fondouk/ucp/catalog/search \
  -H 'Content-Type: application/json' \
  -d '{"query":"t-shirt","pagination":{"limit":2}}' | jq '.products[].title'
```

يكشف MCP‏ (JSON-RPC، Streamable HTTP) الكتالوج نفسه كأدوات `search_catalog` و`get_product` و`lookup_catalog`:

```bash
curl -s -X POST https://demo.fondouk.dev/fondouk/ucp/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq '.result.tools[].name'
```

## البنية

تصميم **نواة + مُحوّلات (adapters)** مفصول. النواة مستقلّة عن أيّ إطار عمل (بلا أيّ اعتماد على PrestaShop) وتنتج *Capability Graph* محايدًا؛ تُسقِطه المُحوّلات في كلّ بروتوكول.

```
┌──────────────────────────────────────────────┐
│  capability-core  (Fondouk\CapabilityCore)     │
│  DTOs · ports (CatalogSource, ShopProfile)     │
│  Capability Graph (versioned JSON Schema)      │
│  Adapter/Ucp: manifest + catalog mapper        │
└───────────────┬──────────────────────────────┘
                │ implements the ports
┌───────────────▼──────────────────────────────┐
│  prestashop-module  (thin binding)             │
│  introspection via Product/Combination/        │
│  StockAvailable/SpecificPrice + Presenter      │
│  controllers: /.well-known/ucp, REST, MCP      │
└──────────────────────────────────────────────┘
```

تُحسَب الأسعار في **سياق زائر مجهول** (`getPriceStatic` بمجموعة الضيف، بلا عميل): تنطبق الأسعار العامّة الخاصّة، ولا تنطبق أسعار الجملة/المجموعات أبدًا. يغذّي الرسمُ نفسُه REST وMCP معًا، فيكشفان البيانات ذاتها للكتالوج، مُتحقَّقًا منها مقابل مخطّطات UCP المنشورة.

التحصين: تحديد معدّل الطلبات بأسلوب token-bucket‏ (60/دقيقة، دفعة 120) مع `Retry-After`، وتخزين مؤقّت للاستجابات يُبطَل عبر خطافات المنتج، وحدود لحجم الطلب والدُّفعات، وقياسات محلّية (telemetry — تُجزَّأ عناوين الـ IP، ولا تُخزَّن أبدًا بشكل صريح).

## الـ Blueprint

فُندُق أيضًا **دليل عمل قابل للنسخ** لجعل *أيّ* منصّة تجارية قابلة للقراءة من الوكلاء — ووحدة PrestaShop هي التنفيذ المرجعي رقم 1. طالِع [`ARCHITECTURE.md`](ARCHITECTURE.md) و[`docs/blueprint/`](docs/blueprint/): مخطّط الـ Capability Graph، وعقد المُحوّل، ونمط الاكتشاف، وأوضاع النشر (مُضمَّن / مكتبة / وسيط). الـ Blueprint هو **دليل لتنفيذ المعايير القائمة (UCP، MCP)** — لا مواصفة منافسة أبدًا.

## خارطة الطريق

- **v1 (الآن) — مجاني، إلى الأبد:** ملفّ UCP + اكتشاف الكتالوج (REST + MCP)، و`llms.txt`، للقراءة فقط، PS 8/9. جعل متجرك *مرئيًّا* للوكلاء هو هويّة الوحدة — ويبقى مجانيًّا.
- **v2 — الطبقة المعامَلاتية (سلّة / دفع، AP2): جزء من Fondouk Pro (مدفوع).** حين ينتقل الوكيل من *القراءة* إلى *الشراء*، تبدأ الطبقة المدفوعة — تدفع حين يتحرّك المال، لا لتُرى. تجمعها Fondouk Pro (الخريف) مع المراقبة (لوحة، إسناد)، والتحكّم لكلّ وكيل، وسجلّات التدقيق / الامتثال.
- **قابلية النقل:** `FondoukBundle` لـ Sylius/Symfony (وضع المكتبة) — تنفيذ مرجعي ثانٍ يُثبت أنّ النواة قابلة للنقل.

## المساهمة

الـ Issues وطلبات الدمج (PR) مُرحَّب بها. تحمل النواة JSON Schema بإصدارات مُرقَّمة (versioned) ومجموعة اختبارات (`composer test` في `capability-core/`)؛ وأيّ مُحوّل بروتوكول يجب أن يحترم عقد المُحوّل (للقراءة فقط، مرشّح عامّ، الإصدارات). طالِع `docs/blueprint/adapter-contract.md`.

## الأمان

عثرت على ثغرة أمنيّة؟ يُرجى الإبلاغ عنها بشكلٍ خاصّ — طالِع [`SECURITY.md`](SECURITY.md) (`security@fondouk.dev`). لا تفتح issue عامّة.

## الترخيص

MIT — طالِع [`LICENSE`](LICENSE). مشروعٌ من Synapsea.
