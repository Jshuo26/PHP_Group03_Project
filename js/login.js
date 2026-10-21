document.addEventListener('DOMContentLoaded', function () {
  const slideshow = document.getElementById('loginSlideshow');
  if (!slideshow) return;
  const slides = Array.from(slideshow.querySelectorAll('.login-slide'));
  const dots = Array.from(slideshow.querySelectorAll('.login-slide-dot'));
  if (slides.length < 2) return;
  let current = 0;
  window.setInterval(function () {
    slides[current].classList.remove('is-active');
    slides[current].setAttribute('aria-hidden', 'true');
    if (dots[current]) dots[current].classList.remove('is-active');
    current = (current + 1) % slides.length;
    slides[current].classList.add('is-active');
    slides[current].setAttribute('aria-hidden', 'false');
    if (dots[current]) dots[current].classList.add('is-active');
  }, 5000);
});