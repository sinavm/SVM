![SVM](https://img.shields.io/badge/SVM-SiNAVM-0f172a?style=for-the-badge&logo=github)
[![Automation](https://img.shields.io/github/actions/workflow/status/sinavm/SVM/php.yml?style=for-the-badge&label=automation)](https://github.com/sinavm/SVM/actions/workflows/php.yml) [![Pages](https://img.shields.io/github/actions/workflow/status/sinavm/SVM/pages.yml?style=for-the-badge&label=dashboard)](https://github.com/sinavm/SVM/actions/workflows/pages.yml) [![Last commit](https://img.shields.io/github/last-commit/sinavm/SVM?style=for-the-badge)](https://github.com/sinavm/SVM/commits/main) [![License](https://img.shields.io/github/license/sinavm/SVM?style=for-the-badge)](LICENSE)

<div align="center">

# SVM · SiNAVM Network

### Smart proxy aggregation, validation and subscription delivery

تجمیع خودکار منابع عمومی، اعتبارسنجی ساختاری، تست واقعی اتصال با sing-box و انتشار اشتراک‌های آماده برای کلاینت‌های سازگار.

[داشبورد زنده](https://sinavm.github.io/SVM/) · [GitHub Actions](https://github.com/sinavm/SVM/actions) · [تلگرام](https://t.me/sinavm)

</div>

## شروع سریع

یکی از لینک‌ها را در کلاینت سازگار خود به‌عنوان Subscription اضافه کنید.

| اشتراک | کاربرد | لینک مستقیم |
| --- | --- | --- |
| Full Mix | مجموعه کامل خروجی اصلی | [Xray](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/mix) |
| Lite Fast | فهرست کوچک و سریع | [دریافت](https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/normal/alive-fast) |
| Lite Stable | فهرست فشرده با انتخاب پایدارتر | [دریافت](https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/normal/alive-stable) |
| Lite Fast Base64 | نسخه کدگذاری‌شده | [دریافت](https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/base64/alive-fast) |
| Lite Stable Base64 | نسخه کدگذاری‌شده | [دریافت](https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/base64/alive-stable) |
| Verified | موفق در هر سه دور تست | [دریافت](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/verified) |
| Fast | حداقل دو موفقیت از سه تست و تأخیر حداکثر ۳۵۰ms | [دریافت](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/fast) |
| Secure | دارای TLS یا Reality در لایه انتقال | [دریافت](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/secure) |

<div align="center">

[![QR اشتراک سریع](https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=8&data=https%3A%2F%2Fraw.githubusercontent.com%2Fsinavm%2FSVM%2Fmain%2Flite%2Fsubscriptions%2Fxray%2Fnormal%2Falive-fast)](https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/normal/alive-fast)

**اسکن QR برای دریافت اشتراک Lite Fast**

</div>

## معماری پردازش

| مرحله | وظیفه |
| --- | --- |
| جمع‌آوری | دریافت کانفیگ از کانال‌های عمومی تلگرام و منابع اشتراک تعریف‌شده |
| استخراج | تشخیص لینک‌ها، جداسازی پروتکل‌ها و Decode کردن ورودی Base64 |
| اعتبارسنجی | بررسی UUID، آدرس سرور، پورت، پارامترهای Reality و ساختار پروتکل |
| یکسان‌سازی | استانداردسازی نام نودها و آماده‌سازی خروجی |
| تست زنده | تبدیل به outboundهای sing-box، بررسی ساختار و تست درخواست واقعی در سه دور مستقل |
| رتبه‌بندی | نگهداری تاریخچه موفقیت و شکست، امتیازدهی و ساخت فهرست Lite و کیفیت |
| انتشار | تولید اشتراک‌های معمولی و Base64، سطح‌های Verified/Fast/Secure و گزارش‌های JSON |

## فرمت‌ها و کلاینت‌ها

مخزن خروجی‌هایی برای Xray، sing-box، Clash، Clash Meta و Surfboard تولید می‌کند؛ قابلیت هر پروتکل به فیلدهای کانفیگ و محدودیت کلاینت مقصد وابسته است.

| کلاینت | خروجی |
| --- | --- |
| Sing-box | [Mix JSON](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/singbox/mix.json) |
| Xray | [Mix](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/mix) |
| Xray Base64 | [Mix](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/base64/mix) |
| Clash | [Mix](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/clash/mix) |
| Clash Meta | [Mix](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/meta/mix) |
| Surfboard | [Mix](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/surfboard/mix) |

## گزارش‌های زنده

| گزارش | محتوا |
| --- | --- |
| [Network health](https://raw.githubusercontent.com/sinavm/SVM/main/reports/health.json) | سلامت منابع، نتیجه تست، آمار اعتبارسنجی و تعداد دسته‌های کیفیت |
| [API index](https://raw.githubusercontent.com/sinavm/SVM/main/reports/index.json) | فهرست مرکزی گزارش‌ها و اشتراک‌ها برای مصرف خودکار |
| [Source health](https://raw.githubusercontent.com/sinavm/SVM/main/reports/sources.json) | وضعیت دریافت هر منبع و تعداد کانفیگ‌های استخراج‌شده |
| [Full test report](https://raw.githubusercontent.com/sinavm/SVM/main/reports/test.json) | تعداد ورودی، تبدیل، تست TCP، سه دور تست پروتکل و سریع‌ترین نودها |
| [Lite status](https://raw.githubusercontent.com/sinavm/SVM/main/lite/docs/reports/status.json) | زمان به‌روزرسانی و تعداد اشتراک‌های Fast و Stable |
| [Validation stats](https://raw.githubusercontent.com/sinavm/SVM/main/reports/stats.json) | تعداد ورودی معتبر و نامعتبر و دلیل رد |
| [Fetch errors](https://raw.githubusercontent.com/sinavm/SVM/main/reports/fetch_errors.txt) | خطاهای دریافت منابع، در صورت وجود |

## اتوماسیون

GitHub Actions پردازش را به‌صورت زمان‌بندی‌شده اجرا می‌کند و امکان اجرای دستی نیز وجود دارد. مرحله اصلی منابع را دریافت و اعتبارسنجی می‌کند، تست‌های اتصال را اجرا می‌کند و خروجی‌ها را می‌سازد. مرحله Lite فهرست‌های فشرده را از نتایج تست و تاریخچه رتبه‌بندی تولید می‌کند. GitHub Pages داشبورد را از پوشه docs منتشر می‌کند.

[اجرای پردازش اصلی](https://github.com/sinavm/SVM/actions/workflows/php.yml) · [انتشار داشبورد](https://github.com/sinavm/SVM/actions/workflows/pages.yml)

## محدودیت‌ها و امنیت

- موفقیت تست یعنی کانفیگ از محیط GitHub Actions در زمان تست پاسخ داده است؛ این نتیجه تضمین اتصال از همه کشورها، اپراتورها یا دستگاه‌ها نیست.
- سرورها متعلق به اشخاص ثالث هستند. برای ترافیک حساس از کانفیگ‌های عمومی استفاده نکنید و به گردانندگان آن‌ها اعتماد پیش‌فرض نداشته باشید.
- وضعیت نودها ممکن است بلافاصله پس از انتشار تغییر کند.
- Verified یعنی نود در هر سه دور تست همان اجرای CI موفق بوده است؛ تضمین پایداری دائمی یا کیفیت مسیر از شبکه کاربر نیست.
- Fast بر اساس حداقل دو موفقیت از سه دور و تأخیر حداکثر ۳۵۰ میلی‌ثانیه ساخته می‌شود.
- Secure صرفاً وجود نشانه‌های TLS یا Reality در پیکربندی را مشخص می‌کند و ممیزی رمزنگاری، امنیت سرور یا قابل اعتماد بودن گرداننده نیست.
- فهرست‌های Lite انتخاب‌های کاربردی هستند و تضمین پایداری دائمی محسوب نمی‌شوند.
- استفاده از پروژه باید مطابق قوانین محل استفاده و سیاست سرویس‌های مقصد باشد.

## مشارکت

برای گزارش مشکل، نام منبع، نوع پروتکل، گزارش مرتبط و مراحل بازتولید را در Issue قرار دهید. Pull Request برای بهبود کد، تست و مستندات پذیرفته می‌شود.

<div align="center">
**SiNAVM · SVM**
[GitHub](https://github.com/sinavm/SVM) · [داشبورد](https://sinavm.github.io/SVM/) · [Telegram](https://t.me/sinavm)
</div>