{{--
    Payment result page (backend-rendered, success + failure in one view).

    Converted from the storefront template pages successful-payment.html /
    failed-payment.html, which are identical apart from the icon, colours and
    labels — so they collapse into a single view driven by $success.

    Rendered by PaymentController::zarinpalCallback() after server-side
    verification. The gateway callback stays on the backend domain; the buttons
    and the breadcrumb home link navigate to the configured frontend app.

    View data (all derived from the persisted Payment record):
        $success          bool     true = captured/verified, false = failed
        $gateway          ?string  درگاه پرداختی
        $date             ?string  تاریخ تراکنش
        $trackId          ?string  شماره پیگیری
        $frontendHomeUrl  ?string  frontend home URL (config only)
        $frontendOrderUrl ?string  frontend order URL (null when no order)

    Scope: card + breadcrumb only. The template's header/footer are omitted
    deliberately — they depend on scripts/app.js and swiper.css, which do not
    ship with this backend, and their nav links point at storefront pages that
    do not exist on this domain.
--}}
<!doctype html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>نتیجه پرداخت</title>
    <link rel="stylesheet" href="{{ asset('modules/payment/app.css') }}">
    <!-- ==========================  DARK MODE SCRIPT ============================= -->
    <script type="text/javascript">
        if (
            localStorage.theme === "dark" ||
            (!("theme" in localStorage) &&
                window.matchMedia("(prefers-color-scheme: dark)").matches)
        ) {
            document.documentElement.classList.add("dark");
        } else {
            document.documentElement.classList.remove("dark");
        }
    </script>
</head>

<body>
    <!-- ICONS USED BY THIS PAGE -->
    <svg class="hidden">
        <symbol id="home" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
            stroke="currentColor" class="size-6">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
        </symbol>
        <symbol id="check-circle" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
            class="size-6">
            <path fill-rule="evenodd"
                d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z"
                clip-rule="evenodd" />
        </symbol>
        <symbol id="x-circle" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
            class="size-6">
            <path fill-rule="evenodd"
                d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25Zm-1.72 6.97a.75.75 0 1 0-1.06 1.06L10.94 12l-1.72 1.72a.75.75 0 1 0 1.06 1.06L12 13.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L13.06 12l1.72-1.72a.75.75 0 1 0-1.06-1.06L12 10.94l-1.72-1.72Z"
                clip-rule="evenodd" />
        </symbol>
    </svg>

    <main class="container h-screen lg:h-auto">
        <!-- Breadcrumb -->
        <nav class="flex mt-8" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 md:space-x-2 rtl:space-x-reverse">
                <li class="inline-flex items-center">
                    @if ($frontendHomeUrl)
                        <a href="{{ $frontendHomeUrl }}"
                            class="inline-flex items-center text-sm gap-x-1  text-gray-700 hover:text-blue-600 dark:text-gray-400 dark:hover:text-white">
                            <svg class="w-4 h-4 mb-0.5">
                                <use href="#home" />
                            </svg>
                            صفحه اصلی
                        </a>
                    @else
                        <span class="inline-flex items-center text-sm gap-x-1 text-gray-500 dark:text-gray-400">
                            <svg class="w-4 h-4 mb-0.5">
                                <use href="#home" />
                            </svg>
                            صفحه اصلی
                        </span>
                    @endif
                </li>
                <li aria-current="page">
                    <div class="flex items-center">
                        <svg class="rtl:rotate-180 w-3 h-3 text-gray-400 mx-1" aria-hidden="true"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="m1 9 4-4-4-4" />
                        </svg>
                        <span class="ms-1 text-sm  text-gray-500 md:ms-2 dark:text-gray-400">
                            {{ $success ? 'پرداخت موفق' : 'پرداخت ناموفق' }}
                        </span>
                    </div>
                </li>
            </ol>
        </nav>

        <!-- payment result -->
        <section class="w-full flex items-center justify-center mt-10">
            <div
                class="relative w-96 bg-white dark:bg-gray-800 rounded-xl shadow pt-12 pb-4 px-4 flex flex-col justify-between items-center gap-y-4 text-gray-700 dark:text-gray-200">
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
                        <a href="{{ $frontendHomeUrl }}"
                            class="w-1/3 py-2 flex items-center justify-center rounded-lg bg-blue-500 text-gray-50 hover:bg-blue-600 transition-all">
                            بازگشت
                        </a>
                    @else
                        <span aria-disabled="true"
                            class="w-1/3 py-2 flex items-center justify-center rounded-lg bg-blue-300 text-gray-50 opacity-60 cursor-not-allowed">
                            بازگشت
                        </span>
                    @endif

                    @if ($frontendOrderUrl)
                        <a href="{{ $frontendOrderUrl }}"
                            class="w-1/3 py-2 flex items-center justify-center rounded-lg bg-gray-200 text-gray-600 hover:bg-gray-400 dark:bg-gray-700 dark:text-gray-100 transition-all">
                            پیگیری
                        </a>
                    @else
                        <span aria-disabled="true"
                            class="w-1/3 py-2 flex items-center justify-center rounded-lg bg-gray-100 text-gray-400 dark:bg-gray-700 opacity-60 cursor-not-allowed">
                            پیگیری
                        </span>
                    @endif
                </div>
            </div>
        </section>
    </main>
</body>

</html>
