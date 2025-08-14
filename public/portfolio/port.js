// فایل جاوااسکریپت برای افکت تایپ متحرک
// 
// این تابع افکت تایپ را ایجاد می‌کند
// که متن را از راست به چپ تایپ می‌کند
// و سپس آن را پاک کرده و دوباره شروع می‌کند
function createTypingEffect(element, texts, speed = 100) {
  let textIndex = 0; // شماره متن فعلی
  let charIndex = 0; // شماره کاراکتر فعلی
  let isDeleting = false; // آیا در حال پاک کردن هستیم؟
  
  // تابع اصلی که افکت تایپ را اجرا می‌کند
  function type() {
    const currentText = texts[textIndex]; // متن فعلی
    
    if (isDeleting) {
      // اگر در حال پاک کردن هستیم
      element.textContent = currentText.substring(0, charIndex - 1);
      charIndex--;
    } else {
      // اگر در حال تایپ کردن هستیم
      element.textContent = currentText.substring(0, charIndex + 1);
      charIndex++;
    }
    
    // تنظیم سرعت تایپ
    let typeSpeed = speed;
    
    if (isDeleting) {
      typeSpeed /= 2; // سرعت پاک کردن سریع‌تر
    }
    
    // اگر تایپ کردن تمام شد
    if (!isDeleting && charIndex === currentText.length) {
      typeSpeed = 2000; // توقف ۲ ثانیه
      isDeleting = true;
    } 
    // اگر پاک کردن تمام شد
    else if (isDeleting && charIndex === 0) {
      isDeleting = false;
      textIndex = (textIndex + 1) % texts.length; // رفتن به متن بعدی
      typeSpeed = 500; // توقف کوتاه
    }
    
    // اجرای مجدد تابع بعد از تاخیر
    setTimeout(type, typeSpeed);
  }
  
  // شروع افکت تایپ
  type();
}

// اجرای افکت تایپ وقتی صفحه بارگذاری شد
document.addEventListener('DOMContentLoaded', function() {
  // پیدا کردن المان با کلاس typing-effect
  const typingElement = document.querySelector('.typing-effect');
  
  if (typingElement) {
    // متن‌های مختلف برای نمایش
    const texts = [
      'من حسن هستم',
      'توسعه‌دهنده وب',
      'برنامه‌نویس خلاق',
      'طراح رابط کاربری'
    ];
    
    // شروع افکت تایپ
    createTypingEffect(typingElement, texts, 150);
  }
});
