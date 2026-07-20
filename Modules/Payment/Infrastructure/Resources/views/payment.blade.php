{{--
    Payment result page (backend-rendered, combined success + failure).

    Rendered by PaymentController::zarinpalCallback() after server-side
    verification. The gateway callback stays on the backend domain; the buttons
    below navigate to the configured frontend application.

    View data (all provided by the controller from the persisted Payment state):
        $success          bool     true = captured/verified, false = failed
        $gateway          ?string  درگاه پرداختی
        $date             ?string  تاریخ تراکنش
        $trackId          ?string  شماره پیگیری (transaction reference)
        $frontendHomeUrl  ?string  frontend home URL
        $frontendOrderUrl ?string  frontend order/tracking URL (null when unknown)

    This page is intentionally self-contained (no shared layout dependency) so it
    renders safely during a stateless gateway callback. Page CSS is loaded from
    the public disk at public/modules/payment/app.css.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>نتیجه پرداخت</title>
    <link rel="stylesheet" href="{{ asset('modules/payment/app.css') }}">
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen">
    {{-- Inline icon symbols (kept in-module so no global layout is required). --}}
    <svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
        <symbol id="check-circle" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </symbol>
        <symbol id="x-circle" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </symbol>
    </svg>

    <section class="w-full flex items-center justify-center mt-10">
        <div class="relative w-96 bg-white dark:bg-gray-800 rounded-xl shadow pt-12 pb-4 px-4 flex flex-col justify-between items-center gap-y-4 text-gray-700 dark:text-gray-200">
            <span class="absolute -top-7">
                @if ($success)
                    <svg class="w-14 h-14 text-green-500">
                        <use href="#check-circle" />
                    </svg>
                @else
                    <svg class="w-14 h-14 text-red-500">
                        <use href="#x-circle" />
                    </svg>
                @endif
            </span>

            @if ($success)
                <h2 class="text-green-500 text-xl font-DanaMedium">پرداخت شما موفقیت آمیز بود.</h2>
            @else
                <h2 class="text-red-500 text-xl font-DanaMedium">پرداخت شما ناموفق بود !</h2>
            @endif

            <span>جزئیات تراکنش :</span>
            <span class="w-full border-b-2 border-gray-200 dark:border-gray-600"></span>

            <ul class="w-full flex flex-col gap-y-5 child:flex child:items-center child:justify-between">
                <li>
                    <span>درگاه پرداختی :</span>
                    <span class="text-gray-400">{{ $gateway ?: 'نامشخص' }}</span>
                </li>
                <li>
                    <span>تاریخ تراکنش :</span>
                    <span class="text-gray-400">{{ $date ?: '—' }}</span>
                </li>
                <li>
                    <span>شماره پیگیری :</span>
                    <span class="text-gray-400">{{ $trackId ?: 'نامشخص' }}</span>
                </li>
                <li>
                    <span>وضعیت :</span>
                    @if ($success)
                        <span class="text-green-500">پرداخت موفق</span>
                    @else
                        <span class="text-red-500">پرداخت ناموفق</span>
                    @endif
                </li>
            </ul>

            <div class="w-full mt-4 flex items-center justify-center gap-x-2">
                @if ($frontendHomeUrl)
                    <a href="{{ $frontendHomeUrl }}" class="w-1/3 py-2 flex items-center justify-center rounded-lg bg-blue-500 text-gray-50 hover:bg-blue-600 transition-all">
                        بازگشت به فروشگاه
                    </a>
                @else
                    <span class="w-1/3 py-2 flex items-center justify-center rounded-lg bg-blue-300 text-gray-50 opacity-60 cursor-not-allowed" aria-disabled="true">
                        بازگشت به فروشگاه
                    </span>
                @endif

                @if ($frontendOrderUrl)
                    <a href="{{ $frontendOrderUrl }}" class="w-1/3 py-2 flex items-center justify-center rounded-lg bg-gray-200 text-gray-600 hover:bg-gray-400 dark:bg-gray-700 dark:text-gray-100 transition-all">
                        پیگیری سفارش
                    </a>
                @else
                    <span class="w-1/3 py-2 flex items-center justify-center rounded-lg bg-gray-100 text-gray-400 dark:bg-gray-700 opacity-60 cursor-not-allowed" aria-disabled="true">
                        پیگیری سفارش
                    </span>
                @endif
            </div>
        </div>
    </section>
</body>
</html>
