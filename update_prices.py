#!/usr/bin/env python3
"""
اسکریپت به‌روزرسانی قیمت برای GitHub Actions
"""

import pandas as pd
import requests
import sys
import os
import urllib3
from typing import List, Dict

WORDPRESS_URL = "https://moaserhome.ir"
API_ENDPOINT  = f"{WORDPRESS_URL}/update-prices.php"
API_TOKEN     = os.getenv("CPA_API_TOKEN")

REQUIRED_COLUMNS = ["code", "title", "price"]  # هر سه ستون اجباری

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)


def read_csv(file_path: str) -> List[Dict]:
    try:
        df = pd.read_csv(file_path, encoding="utf-8-sig")
    except FileNotFoundError:
        print(f"❌ فایل '{file_path}' پیدا نشد.")
        sys.exit(1)
    except Exception as e:
        print(f"❌ خطا در خواندن فایل: {e}")
        sys.exit(1)

    missing = [c for c in REQUIRED_COLUMNS if c not in df.columns]
    if missing:
        print(f"❌ ستون‌های زیر در فایل وجود ندارند: {missing}")
        print(f"   ستون‌های موجود: {list(df.columns)}")
        sys.exit(1)

    before = len(df)
    df = df.dropna(subset=REQUIRED_COLUMNS)
    dropped = before - len(df)
    if dropped:
        print(f"⚠️  {dropped} ردیف خالی/ناقص نادیده گرفته شد.")

    changes = []
    errors = 0

    for i, row in df.iterrows():
        sku = str(row["code"]).strip()
        title = str(row["title"]).strip()
        try:
            price = float(str(row["price"]).replace(",", "").strip())
            if price <= 0:
                raise ValueError("قیمت باید مثبت باشد")
        except ValueError as e:
            print(f"⚠️  ردیف {i+2}: قیمت نامعتبر ({row['price']}) — {e}")
            errors += 1
            continue

        if not sku or sku.lower() == "nan":
            print(f"⚠️  ردیف {i+2}: کد محصول خالی است.")
            errors += 1
            continue
        if not title or title.lower() == "nan":
            print(f"⚠️  ردیف {i+2}: عنوان محصول خالی است.")
            errors += 1
            continue

        changes.append({
            "sku":   sku,
            "title": title,
            "new_price": price
        })

    if errors:
        print(f"\n⚠️  {errors} ردیف به دلیل خطا نادیده گرفته شد.")

    return changes


def print_summary(changes: List[Dict]) -> None:
    print(f"\n{'='*55}")
    print(f"📊 خلاصه تغییرات ({len(changes)} محصول)")
    print(f"{'='*55}")
    print(f"{'کد':<20} {'عنوان':<30} {'قیمت جدید':>15}")
    print(f"{'-'*55}")
    for c in changes[:10]:
        print(f"{c['sku']:<20} {c['title'][:28]:<30} {c['new_price']:>15,.0f}")
    if len(changes) > 10:
        print(f"  ... و {len(changes)-10} محصول دیگر")
    print(f"{'='*55}")


def send_to_plugin(changes: List[Dict]) -> bool:
    if not API_TOKEN:
        print("❌ CPA_API_TOKEN تنظیم نشده است.")
        sys.exit(1)

    headers = {
        "Content-Type": "application/json",
        "X-API-Token":  API_TOKEN
    }

    try:
        print(f"\n📤 ارسال {len(changes)} تغییر به {WORDPRESS_URL} ...")
        print(f"   آدرس: {API_ENDPOINT}")

        resp = requests.post(API_ENDPOINT, json=changes, headers=headers, timeout=180, verify=False)

        print(f"   پاسخ: {resp.status_code}")

        if resp.status_code == 200:
            result = resp.json()
            received = result.get("count", 0)
            skipped = len(changes) - received
            print(f"✅ داده‌ها با موفقیت دریافت شدند.")
            print(f"   تأیید شده در سایت : {received} محصول")
            if skipped > 0:
                print(f"   پیدا نشد در سایت : {skipped} محصول")
            return True
        else:
            print(f"❌ خطا: {resp.status_code}")
            print(f"   پاسخ: {resp.text[:300]}")
            return False

    except Exception as e:
        print(f"❌ خطا: {e}")
        return False


def main():
    file_path = sys.argv[1] if len(sys.argv) > 1 else "prices.csv"

    print("🔄 شروع به‌روزرسانی قیمت‌ها...")
    print(f"📂 خواندن فایل: {file_path}")

    changes = read_csv(file_path)

    if not changes:
        print("❌ هیچ تغییر معتبری یافت نشد.")
        sys.exit(1)

    print_summary(changes)

    success = send_to_plugin(changes)

    if success:
        print("\n✅ تغییرات با موفقیت ارسال شد.")
        print("   حالا به پیشخوان وردپرس → تأیید قیمت بروید.")
        sys.exit(0)
    else:
        print("\n❌ ارسال تغییرات ناموفق بود.")
        sys.exit(1)


if __name__ == "__main__":
    main()
