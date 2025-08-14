<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Portfolio</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="{{ asset('portfolio/port.css') }}">
    <style type="text/tailwindcss">
        @theme {
            --color-clifford: #2efa05ff;
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
        }

        .typing-effect {
            overflow: hidden;
            white-space: nowrap;
            text-align: right;
            direction: rtl;
            display: inline-block;
        }

        .typing-cursor {
            color: #2efa05ff;
            font-weight: bold;
            animation: blink-caret 0.75s step-end infinite;
        }

        @keyframes blink-caret {

            from,
            to {
                opacity: 1;
            }

            50% {
                opacity: 0;
            }
        }

        html {
            scroll-behavior: smooth;
        }
    </style>
</head>

<body class="bg-[#181C23] text-white min-h-screen" dir="rtl">
    <header class="flex justify-between items-center px-8 py-6 border-b border-[#23262F]">
        <div class="text-lg font-bold">حسن امگا</div>
        <nav class="space-x-8 hidden md:flex">
            <a href="#home" class="hover:text-[#1dd105]">خانه</a>
            <a href="#about" class="hover:text-[#1dd105]">درباره من</a>
            <a href="#projects" class="hover:text-[#1dd105]">پروژه‌ها</a>
            <a href="#contact" class="hover:text-[#1dd105]">تماس</a>
        </nav>
    </header>
    <section id="home" class="flex flex-col md:flex-row justify-between items-center px-8 py-16 max-w-6xl mx-auto">
        <div class="flex-1 flex justify-center mt-10 md:mt-0">
            <div class="relative">
                <div class="absolute -inset-4 rounded-full border-4 border-[#1dd105] opacity-30 animate-pulse"></div>
                <img src="https://media.istockphoto.com/id/1335941248/photo/shot-of-a-handsome-young-man-standing-against-a-grey-background.jpg?s=612x612&w=0&k=20&c=JSBpwVFm8vz23PZ44Rjn728NwmMtBa_DYL7qxrEWr38="
                    alt="profile" class="w-60 h-60 rounded-full object-cover border-4 border-[#23262F]" />
            </div>
        </div>
        <div class="flex-1 text-right">
            <h2 class="text-3xl md:text-4xl font-bold mb-2">سلام<span class="text-[#1dd105]">.</span></h2>
            <h3 class="text-2xl md:text-3xl font-semibold mb-2">
                <span class="typing-effect"></span>
                <span class="typing-cursor">|</span>
            </h3>
            <h1 class="text-4xl md:text-5xl font-bold mb-6">توسعه‌دهنده نرم‌افزار</h1>
            <div class="flex space-x-4 mb-8">
                <button class="bg-[#1dd105] text-white px-6 py-2 rounded hover:bg-[#ff7a50] transition">پروژه
                    داری؟</button>
                <button
                    class="border border-white px-6 py-2 rounded hover:bg-white hover:text-[#181C23] transition">رزومه
                    من</button>
            </div>
            <div class="flex space-x-6 text-[#7B7F86] text-sm">
                <span>HTML5</span>
                <span>CSS</span>
                <span>Javascript</span>
                <span>Node.js</span>
                <span>React</span>
                <span>Git</span>
                <span>Github</span>
            </div>
        </div>
    </section>
    <section id="about" class="max-w-6xl mx-auto px-8 py-12 grid md:grid-cols-2 gap-12">
        <div class="flex flex-col space-y-8">
            <div class="flex items-center space-x-4">
                <span class="w-8 h-8">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-8 h-8 text-[#1dd105]">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 4h16v16H4V4zm2 2v12h12V6H6zm2 2h8v8H8V8z" />
                    </svg>
                </span>
                <span class="text-lg font-semibold">توسعه وبسایت</span>
            </div>
            <div class="flex items-center space-x-4">
                <span class="w-8 h-8">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-8 h-8 text-[#1dd105]">
                        <rect x="7" y="2" width="10" height="20" rx="2" />
                        <circle cx="12" cy="18" r="1" />
                    </svg>
                </span>
                <span class="text-lg font-semibold">توسعه اپلیکیشن</span>
            </div>
            <div class="flex items-center space-x-4">
                <span class="w-8 h-8">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-8 h-8 text-[#1dd105]">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M2 12h20M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20" />
                    </svg>
                </span>
                <span class="text-lg font-semibold">میزبانی وبسایت</span>
            </div>
        </div>
        <div class="text-right">
            <h2 class="text-2xl font-bold mb-4">درباره من</h2>
            <p class="text-[#7B7F86] mb-8">من مسیرم رو از عکاسی شروع کردم و عاشق خلق کردن شدم. حالا توسعه نرم‌افزار رو
                به عنوان مسیر یادگیری و ساختن انتخاب کردم.</p>
            <div class="flex space-x-12">
                <div>
                    <div class="text-2xl font-bold">۱۲۰<span class="text-[#1dd105]">+</span></div>
                    <div class="text-[#7B7F86] text-sm">پروژه تکمیل‌شده</div>
                </div>
                <div>
                    <div class="text-2xl font-bold">۹۵<span class="text-[#1dd105]">٪</span></div>
                    <div class="text-[#7B7F86] text-sm">رضایت مشتری</div>
                </div>
                <div>
                    <div class="text-2xl font-bold">۱۰<span class="text-[#1dd105]">+</span></div>
                    <div class="text-[#7B7F86] text-sm">سال تجربه</div>
                </div>
            </div>
        </div>
    </section>
    <section id="projects" class="max-w-6xl mx-auto px-8 py-12 text-right">
        <h2 class="text-2xl font-bold mb-8">پروژه‌ها</h2>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div class="bg-[#23262F] p-6 rounded-lg hover:bg-[#2A2E38] transition">
                <div class="w-12 h-12 bg-[#1dd105] rounded-lg mb-4 flex items-center justify-center">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-6 h-6 text-white">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold mb-2">پروژه فروشگاه آنلاین</h3>
                <p class="text-[#7B7F86] text-sm mb-4">فروشگاه آنلاین کامل با React و Node.js</p>
                <div class="flex space-x-2">
                    <span class="bg-[#1dd105] text-white px-2 py-1 rounded text-xs">React</span>
                    <span class="bg-[#1dd105] text-white px-2 py-1 rounded text-xs">Node.js</span>
                </div>
            </div>
            <div class="bg-[#23262F] p-6 rounded-lg hover:bg-[#2A2E38] transition">
                <div class="w-12 h-12 bg-[#1dd105] rounded-lg mb-4 flex items-center justify-center">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-6 h-6 text-white">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold mb-2">اپلیکیشن موبایل</h3>
                <p class="text-[#7B7F86] text-sm mb-4">اپلیکیشن مدیریت کارها</p>
                <div class="flex space-x-2">
                    <span class="bg-[#1dd105] text-white px-2 py-1 rounded text-xs">React Native</span>
                    <span class="bg-[#1dd105] text-white px-2 py-1 rounded text-xs">Firebase</span>
                </div>
            </div>
            <div class="bg-[#23262F] p-6 rounded-lg hover:bg-[#2A2E38] transition">
                <div class="w-12 h-12 bg-[#1dd105] rounded-lg mb-4 flex items-center justify-center">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-6 h-6 text-white">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9v-9m0-9v9" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold mb-2">پورتال خبری</h3>
                <p class="text-[#7B7F86] text-sm mb-4">سایت خبری با سیستم مدیریت محتوا</p>
                <div class="flex space-x-2">
                    <span class="bg-[#1dd105] text-white px-2 py-1 rounded text-xs">Vue.js</span>
                    <span class="bg-[#1dd105] text-white px-2 py-1 rounded text-xs">Laravel</span>
                </div>
            </div>
        </div>
    </section>
    <section id="contact" class="max-w-6xl mx-auto px-8 py-12 text-right">
        <h2 class="text-2xl font-bold mb-8">تماس با من</h2>
        <div class="text-center">
            <h3 class="text-xl font-semibold mb-6">بیایید صحبت کنیم</h3>
            <p class="text-[#7B7F86] mb-12 max-w-2xl mx-auto">اگر پروژه‌ای دارید یا می‌خواهید درباره همکاری صحبت کنیم،
                خوشحال می‌شوم از شما بشنوم.</p>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-[#23262F] p-6 rounded-lg hover:bg-[#2A2E38] transition">
                    <div class="w-12 h-12 bg-[#1dd105] rounded-lg mb-4 flex items-center justify-center mx-auto">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-6 h-6 text-white">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div class="text-center">
                        <div class="font-semibold mb-2">ایمیل</div>
                        <div class="text-[#7B7F86]">hassan@example.com</div>
                    </div>
                </div>
                <div class="bg-[#23262F] p-6 rounded-lg hover:bg-[#2A2E38] transition">
                    <div class="w-12 h-12 bg-[#1dd105] rounded-lg mb-4 flex items-center justify-center mx-auto">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-6 h-6 text-white">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                    <div class="text-center">
                        <div class="font-semibold mb-2">تلفن</div>
                        <div class="text-[#7B7F86]">+۹۸ ۹۱۲ ۱۲۳ ۴۵۶۷</div>
                    </div>
                </div>
                <div class="bg-[#23262F] p-6 rounded-lg hover:bg-[#2A2E38] transition">
                    <div class="w-12 h-12 bg-[#1dd105] rounded-lg mb-4 flex items-center justify-center mx-auto">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-6 h-6 text-white">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <div class="text-center">
                        <div class="font-semibold mb-2">آدرس</div>
                        <div class="text-[#7B7F86]">تهران، ایران</div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <script src="{{ asset('portfolio/port.js') }}"></script>
</body>

</html>
