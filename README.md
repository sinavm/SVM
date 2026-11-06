![SVM](https://img.shields.io/badge/SVM-SiNAVM-0f172a?style=for-the-badge&logo=github)
[![Automation](https://img.shields.io/github/actions/workflow/status/sinavm/SVM/php.yml?style=for-the-badge&label=automation)](https://github.com/sinavm/SVM/actions/workflows/php.yml)
[![Dashboard](https://img.shields.io/github/actions/workflow/status/sinavm/SVM/pages.yml?style=for-the-badge&label=dashboard)](https://github.com/sinavm/SVM/actions/workflows/pages.yml)
[![Last commit](https://img.shields.io/github/last-commit/sinavm/SVM?style=for-the-badge)](https://github.com/sinavm/SVM/commits/main)
[![License](https://img.shields.io/github/license/sinavm/SVM?style=for-the-badge)](LICENSE)

<div align="center">

# SVM · SiNAVM Network

### Smart proxy aggregation, validation and subscription delivery

تجمیع خودکار منابع، اعتبارسنجی ساختاری، تست واقعی اتصال با sing-box و انتشار اشتراک‌های آماده برای کلاینت‌های سازگار.

[داشبورد زنده](https://sinavm.github.io/SVM/) · [GitHub Actions](https://github.com/sinavm/SVM/actions) · [تلگرام](https://t.me/sinavm)

</div>

## شروع سریع

یکی از لینک‌های زیر را در بخش Subscription کلاینت خود اضافه کنید. برای شروع، Lite Fast یا Verified پیشنهاد می‌شود.

| اشتراک | کاربرد | لینک مستقیم |
| --- | --- | --- |
| Lite Fast | فهرست فشرده و سریع | [Normal](https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/normal/alive-fast) · [Base64](https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/base64/alive-fast) |
| Lite Stable | انتخاب پایدارتر با استفاده از تاریخچه کیفیت | [Normal](https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/normal/alive-stable) · [Base64](https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/base64/alive-stable) |
| Verified | موفق در هر سه دور تست | [Normal](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/verified) · [Base64](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/base64/verified) |
| Fast | حداقل دو موفقیت از سه تست و تأخیر حداکثر ۳۵۰ms | [Normal](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/fast) · [Base64](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/base64/fast) |
| Secure | دارای نشانه TLS یا Reality در لایه انتقال | [Normal](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/secure) · [Base64](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/base64/secure) |
| Full Mix | مجموعه کامل خروجی اصلی | [Normal](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/mix) · [Base64](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/base64/mix) |

<div align="center">

[![QR اشتراک Lite Fast](https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=8&data=https%3A%2F%2Fraw.githubusercontent.com%2Fsinavm%2FSVM%2Fmain%2Flite%2Fsubscriptions%2Fxray%2Fnormal%2Falive-fast)](https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/normal/alive-fast)

**اسکن QR برای دریافت اشتراک Lite Fast**

</div>

## انتخاب فرمت و کلاینت

SVM خروجی‌های اصلی زیر را منتشر می‌کند:

| کلاینت | اشتراک |
| --- | --- |
| Xray / V2Ray | [Mix](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/mix) · [Base64](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/base64/mix) |
| sing-box | [Mix JSON](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/singbox/mix.json) |
| Clash | [Mix](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/clash/mix) |
| Clash Meta / Mihomo | [Mix](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/meta/mix) |
| Surfboard | [Mix](https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/surfboard/mix) |

اشتراک‌های جداگانه بر اساس پروتکل در [پوشه پروتکل‌ها](https://github.com/sinavm/SVM/tree/main/subscriptions/xray/normal/protocols) و بر اساس کشور در [پوشه کشورها](https://github.com/sinavm/SVM/tree/main/subscriptions/xray/normal/countries) قرار دارند. لینک‌های هر دو گروه از داشبورد نیز قابل انتخاب هستند.

## قابلیت‌ها

| بخش | قابلیت |
| --- | --- |
| جمع‌آوری | دریافت از کانال‌های تلگرام و اشتراک‌های ورودی |
| استخراج | تشخیص لینک‌ها، جداسازی پروتکل‌ها و Decode ورودی Base64 |
| اعتبارسنجی | بررسی UUID، سرور، پورت، پارامترهای Reality و ساختار پروتکل |
| تست زنده | تست TCP و درخواست واقعی از مسیر sing-box در سه دور مستقل |
| رتبه‌بندی | تاریخچه موفقیت و شکست، امتیازدهی و ساخت فهرست‌های Fast و Stable |
| تفکیک | تولید خروجی بر اساس پروتکل و کشور |
| فرمت‌ها | Xray، Base64، sing-box، Clash، Clash Meta و Surfboard |
| شفافیت | گزارش سلامت منابع، اعتبارسنجی، تست، کیفیت و وضعیت شبکه |
| داشبورد | انتخاب اشتراک، QR، کاوشگر نودها، فیلتر کشور و پروتکل و آینه CDN |

## گزارش‌های زنده

| گزارش | محتوا |
| --- | --- |
| [Network health](https://raw.githubusercontent.com/sinavm/SVM/main/reports/health.json) | سلامت کلی، منابع، نتایج تست و توزیع کشورها |
| [API index](https://raw.githubusercontent.com/sinavm/SVM/main/reports/index.json) | فهرست مرکزی نودها، آمار و اشتراک‌ها |
| [Source health](https://raw.githubusercontent.com/sinavm/SVM/main/reports/sources.json) | وضعیت دریافت هر منبع |
| [Source history](https://raw.githubusercontent.com/sinavm/SVM/main/reports/history.json) | تاریخچه پردازش |
| [Quality history](https://raw.githubusercontent.com/sinavm/SVM/main/reports/quality-history.json) | روند تغییر کیفیت |
| [GeoIP cache](https://github.com/sinavm/SVM/blob/main/reports/geo-cache.json) | کش ۳۰ روزه کشور IP سرورها |
| [Full test report](https://raw.githubusercontent.com/sinavm/SVM/main/reports/test.json) | ورودی، تبدیل، تست TCP و تست واقعی پروتکل |
| [Validation stats](https://raw.githubusercontent.com/sinavm/SVM/main/reports/stats.json) | تعداد ورودی معتبر و نامعتبر و دلایل رد |
| [Lite status](https://raw.githubusercontent.com/sinavm/SVM/main/lite/docs/reports/status.json) | زمان به‌روزرسانی و تعداد فهرست‌های Lite |

## منابع خصوصی و GitHub Secrets

فیدهای خصوصی فقط از طریق GitHub Actions Secrets خوانده می‌شوند. برای تنظیم آن‌ها به مسیر **Settings → Secrets and variables → Actions** بروید.

- فیدهای شخصی: `PRIVATE_LINK_SINAVM_1` تا `PRIVATE_LINK_SINAVM_6`
- فیدهای گروه Curated و Heavy: `PRIVATE_LINK_SINAVM_7` تا `PRIVATE_LINK_SINAVM_18`

هر Secret یک URL اشتراک می‌گیرد. Secret خالی باعث توقف کل پردازش نمی‌شود و فقط همان منبع بدون داده گزارش می‌شود. مقدار Secret نباید در لاگ یا فایل‌های عمومی چاپ شود.

## اتوماسیون

GitHub Actions پردازش اصلی و Lite را اجرا می‌کند. منابع دریافت، استخراج و اعتبارسنجی می‌شوند؛ سپس کانفیگ‌ها با sing-box تست می‌شوند و خروجی‌های کلاینت ساخته و اعتبارسنجی می‌شوند. داشبورد GitHub Pages از پوشه `docs` منتشر می‌شود.

[اجرای پردازش اصلی](https://github.com/sinavm/SVM/actions/workflows/php.yml) · [انتشار داشبورد](https://github.com/sinavm/SVM/actions/workflows/pages.yml)

## محدودیت‌ها و امنیت

- موفقیت تست یعنی کانفیگ از محیط GitHub Actions در زمان تست پاسخ داده است؛ این نتیجه تضمین اتصال از همه کشورها، اپراتورها یا دستگاه‌ها نیست.
- سرورها متعلق به اشخاص ثالث هستند. برای ترافیک حساس از کانفیگ‌های عمومی استفاده نکنید و به گردانندگان آن‌ها اعتماد پیش‌فرض نداشته باشید.
- وضعیت نودها ممکن است بلافاصله پس از انتشار تغییر کند.
- Verified یعنی نود در هر سه دور تست همان اجرای CI موفق بوده است؛ این معیار تضمین پایداری دائمی نیست.
- Fast بر اساس حداقل دو موفقیت از سه دور و تأخیر حداکثر ۳۵۰ میلی‌ثانیه ساخته می‌شود.
- Secure صرفاً وجود نشانه‌های TLS یا Reality را مشخص می‌کند و ممیزی امنیتی سرور یا گرداننده نیست.
- مکان‌یابی کشور با سرویس GeoIP بیرونی انجام می‌شود؛ IP عمومی سرورها برای تعیین کشور پردازش و نتیجه تا ۳۰ روز cache می‌شود.
- استفاده از پروژه باید مطابق قوانین محل استفاده و سیاست سرویس‌های مقصد باشد.

## مشارکت

برای گزارش مشکل، نوع پروتکل، گزارش مرتبط و مراحل بازتولید را در Issue قرار دهید. Pull Request برای بهبود کد، تست و مستندات پذیرفته می‌شود.

<div align="center">

**SiNAVM · SVM**

[GitHub](https://github.com/sinavm/SVM) · [Dashboard](https://sinavm.github.io/SVM/) · [Telegram](https://t.me/sinavm)

</div>
