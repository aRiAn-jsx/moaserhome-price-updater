# 🛒 سیستم به‌روزرسانی خودکار قیمت‌های ووکامرس

این پروژه به‌روزرسانی خودکار قیمت‌های محصولات فروشگاه ووکامرس را از طریق فایل CSV و GitHub Actions انجام می‌دهد.

## 🏗️ ساختار پروژه

```
├── .github/
│   └── workflows/
│       └── update-prices.yml      # workflow GitHub Actions
├── update_prices.py               # اسکریپت اصلی پایتون
├── send_to_plugin.py              # اسکریپت تست محلی
├── requirements.txt               # dependencies
├── prices.csv                     # نمونه فایل داده
└── custom-price-approval-v0.php   # افزونه وردپرس
```

## 🚀 راه‌اندازی سریع

### ۱. آپلود افزونه به وردپرس
فایل `custom-price-approval-v0.php` را آپلود کنید به:
```
/wp-content/plugins/custom-price-approval/
```
و از پیشخوان وردپرس فعال‌سازی کنید.

### ۲. تنظیم مخزن GitHub
1. همه فایل‌ها را به مخزن `aRiAn-jsx/moaserhome-price-updater` پوش کنید
2. برو به **Settings → Secrets and variables → Actions**
3. **New repository secret** بساز با نام `CPA_API_TOKEN` و مقدار `moaserhome2024`

### ۳. تست اولیه
```bash
# تست محلی
python send_to_plugin.py prices.csv

# تست روی GitHub Actions
- برو به Actions tab
- روی "Update Product Prices" کلیک کن
- "Run workflow" رو بزن
```

## 📊 فرمت فایل CSV

فایل باید این ستون‌ها را داشته باشد:
- `code` : کد محصول (SKU)
- `color` : رنگ محصول
- `price` : قیمت جدید (به ریال)

**مثال:**
```csv
code,color,price
DH1419-70,مشکی,152990000
DH1419-70,سفید,156780000
DH1420-70,مشکی,121230000
```

## 🔧 تنظیمات workflow

workflow به سه روش اجرا می‌شود:

1. **اتوماتیک** — وقتی فایل `prices.csv` تغییر کند
2. **دستی** — از تب Actions در GitHub
3. **زمان‌بندی** — هر هفته دوشنبه ساعت 9 صبح UTC

برای تغییر زمان‌بندی، فایل `.github/workflows/update-prices.yml` را ویرایش کن.

## 🛡️ نکات امنیتی

- توکن API در **Secrets GitHub** ذخیره می‌شود
- افزونه فقط درخواست‌های با توکن معتبر را قبول می‌کند
- لاگ تمام تغییرات در دیتابیس ذخیره می‌شود
- SSL verification برای سایت‌های با گواهی خودامضا غیرفعال است

## 🐛 عیب‌یابی

| مشکل | راه‌حل |
|------|--------|
| افزونه 404 می‌دهد | مطمئن شو افزونه فعال است |
| خطای SSL | در اسکریپت `verify=False` فعال است |
| خطای timeout | timeout را در اسکریپت افزایش بده |
| محصول پیدا نمی‌شود | SKU و رنگ را با دیتابیس مطابقت بده |

## 📞 پشتیبانی

برای گزارش مشکل یا درخواست feature جدید:
1. Issue در GitHub ایجاد کن
2. یا مستقیماً با من تماس بگیر

---

**توسعه‌دهنده:** [@aRiAn-jsx](https://github.com/aRiAn-jsx)  
**سایت هدف:** https://moaserhome.ir  
**افزونه:** Custom Price Approval System v2.0