import pandas as pd
import requests
import sys
from typing import List, Dict

# ========== تنظیمات ==========
WORDPRESS_URL = "https://moaserhome.ir"
API_ENDPOINT  = f"{WORDPRESS_URL}/wp-json/cpa/v1/pending-changes"
API_TOKEN     = "moaserhome2024"

# ستون‌های مورد انتظار در فایل CSV
REQUIRED_COLUMNS = ["code", "color", "price"]


def read_csv(file_path: str) -> List[Dict]:
    """خواندن فایل CSV و برگرداندن لیست تغییرات معتبر"""
    try:
        df = pd.read_csv(file_path, encoding="utf-8-sig")  # utf-8-sig برای فایل‌های ذخیره‌شده با Excel
    except FileNotFoundError:
        print(f"❌ فایل '{file_path}' پیدا نشد.")
        sys.exit(1)
    except Exception as e:
        print(f"❌ خطا در خواندن فایل: {e}")
        sys.exit(1)

    # بررسی ستون‌های مورد نیاز
    missing = [c for c in REQUIRED_COLUMNS if c not in df.columns]
    if missing:
        print(f"❌ ستون‌های زیر در فایل وجود ندارند: {missing}")
        print(f"   ستون‌های موجود: {list(df.columns)}")
        sys.exit(1)

    # حذف ردیف‌های خالی
    before = len(df)
    df = df.dropna(subset=REQUIRED_COLUMNS)
    dropped = before - len(df)
    if dropped:
        print(f"⚠️  {dropped} ردیف خالی/ناقص نادیده گرفته شد.")

    changes = []
    errors  = 0
    for i, row in df.iterrows():
        sku   = str(row["code"]).strip()
        color = str(row["color"]).strip()

        # اعتبارسنجی قیمت
        try:
            price = float(str(row["price"]).replace(",", "").strip())
            if price <= 0:
                raise ValueError("قیمت باید مثبت باشد")
        except ValueError as e:
            print(f"⚠️  ردیف {i+2}: قیمت نامعتبر ({row['price']}) — {e}")
            errors += 1
            continue

        # اعتبارسنجی SKU و رنگ
        if not sku or sku.lower() == "nan":
            print(f"⚠️  ردیف {i+2}: کد محصول خالی است.")
            errors += 1
            continue
        if not color or color.lower() == "nan":
            print(f"⚠️  ردیف {i+2}: رنگ خالی است (SKU: {sku}).")
            errors += 1
            continue

        changes.append({
            "sku":       sku,
            "color":     color,
            "new_price": price
        })

    if errors:
        print(f"\n⚠️  {errors} ردیف به دلیل خطا نادیده گرفته شد.")

    return changes


def print_preview(changes: List[Dict]) -> None:
    """نمایش خلاصه تغییرات قبل از ارسال"""
    print(f"\n{'='*55}")
    print(f"📊 خلاصه تغییرات ({len(changes)} محصول)")
    print(f"{'='*55}")
    print(f"{'کد محصول':<15} {'رنگ':<20} {'قیمت جدید':>15}")
    print(f"{'-'*55}")
    for c in changes[:10]:  # نمایش ۱۰ تا اول
        print(f"{c['sku']:<15} {c['color']:<20} {c['new_price']:>15,.0f}")
    if len(changes) > 10:
        print(f"  ... و {len(changes)-10} محصول دیگر")
    print(f"{'='*55}")


def send_to_plugin(changes: List[Dict]) -> bool:
    """ارسال تغییرات به افزونه وردپرس"""
    headers = {
        "Content-Type": "application/json",
        "X-API-Token":  API_TOKEN
    }
    # اضافه کردن token در query string به عنوان fallback
    url_with_token = API_ENDPOINT    
    # غیرفعال کردن warnings برای SSL (گواهی نامعتبر)
    import urllib3
    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
    
    try:
        print(f"\n📤 ارسال {len(changes)} تغییر به {WORDPRESS_URL} ...")
        print(f"   URL: {url_with_token}")
        print(f"   Header X-API-Token: {API_TOKEN[:8]}...")
        
        # ارسال با timeout طولانی‌تر و غیرفعال کردن SSL verification
        resp = requests.post(url_with_token, json=changes, headers=headers, timeout=180, verify=False)
        
        print(f"   پاسخ: {resp.status_code}")
        
        if resp.status_code == 200:
            result = resp.json()
            received = result.get("count", 0)
            skipped  = len(changes) - received
            print(f"✅ افزونه داده‌ها را دریافت کرد.")
            print(f"   تأیید شده در سایت : {received} محصول")
            if skipped > 0:
                print(f"   پیدا نشد در سایت : {skipped} محصول (SKU یا رنگ اشتباه)")
            return True

        elif resp.status_code == 401:
            print("❌ توکن API اشتباه است. مقدار API_TOKEN را بررسی کنید.")
        elif resp.status_code == 403:
            print("❌ دسترسی رد شد (403). توکن یا تنظیمات افزونه را بررسی کنید.")
        elif resp.status_code == 404:
            print("❌ آدرس API پیدا نشد (404). آیا افزونه فعال است؟")
        else:
            print(f"❌ خطای سرور: {resp.status_code}")
            print(f"   پاسخ: {resp.text[:300]}")
        return False

    except requests.exceptions.SSLError as e:
        print(f"❌ خطای SSL: {e}")
        print("   اگر از VPN استفاده می‌کنی، خاموشش کن.")
        print("   یا گواهی SSL سایت را بررسی کنید.")
        return False
    except requests.exceptions.ConnectionError:
        print(f"❌ اتصال به {WORDPRESS_URL} برقرار نشد.")
        return False
    except requests.exceptions.Timeout:
        print("❌ سرور پاسخ نداد (timeout پس از 180 ثانیه).")
        print("   ممکن است سرور مشغول باشد یا افزونه کند پاسخ دهد.")
        return False
    except Exception as e:
        print(f"❌ خطای غیرمنتظره: {type(e).__name__}: {e}")
        return False


def main():
    # دریافت مسیر فایل از آرگومان یا استفاده از مقدار پیش‌فرض
    file_path = sys.argv[1] if len(sys.argv) > 1 else "prices.csv"

    print(f"📂 خواندن فایل: {file_path}")
    changes = read_csv(file_path)

    if not changes:
        print("❌ هیچ تغییر معتبری یافت نشد.")
        sys.exit(1)

    print_preview(changes)

    confirm = input("\nارسال به افزونه وردپرس؟ (y/n): ").strip().lower()
    if confirm != "y":
        print("لغو شد.")
        sys.exit(0)

    success = send_to_plugin(changes)
    if success:
        print("\n➡️  حالا به پیشخوان وردپرس → تأیید قیمت بروید و تغییرات را بررسی کنید.")
    else:
        sys.exit(1)


if __name__ == "__main__":
    main()