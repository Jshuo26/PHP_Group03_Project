</main>

<footer class="site-footer">

  <div class="container">

    <h2 class="footer-title">
      aklAAAt!
    </h2>

    <p class="footer-text">
      Accessible. Assorted. Automated.
    </p>

    <nav class="footer-links" aria-label="Footer">
      <a href="/privacy.php">Privacy Policy</a>
    </nav>

    <p class="footer-copyright">
      &copy; <?php echo date("Y"); ?> aklAAAt!
      All rights reserved.
    </p>

  </div>

</footer>

<?php if (!isset($_COOKIE['cookie_consent'])): ?>
<div class="cookie-banner" id="cookieBanner" role="region" aria-label="Cookie consent">

  <div class="cookie-banner-text">
    <p class="cookie-banner-title">We use cookies</p>
    <p>
      We use essential cookies to keep you logged in and your account secure.
      Google reCAPTCHA may also set cookies on some forms. Read our
      <a href="/privacy.php">Privacy Policy</a> to learn more.
    </p>
  </div>

  <div class="cookie-banner-actions">
    <button type="button" class="btn btn-outline btn-sm" id="cookieDecline">
      Decline
    </button>
    <button type="button" class="btn btn-primary btn-sm" id="cookieAccept">
      Accept
    </button>
  </div>

</div>
<?php endif; ?>

<script src="/js/script.js"></script>
<script src="/js/cookie-consent.js"></script>

</body>

</html>