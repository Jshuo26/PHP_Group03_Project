<?php include 'includes/header.php'; ?>

<section class="hero-slider">

    <div class="hero-slide active">
        <div class="hero-overlay"></div>

        <div class="hero-content">
            <h1 class="hero-title">
                Accessible. Assorted. Automated.
            </h1>

            <p class="hero-description">
                Discover Filipino authors and independent publishers,
                all in one shelf.
            </p>

            <a href="/books.php" class="hero-button">
                Browse the Catalog
            </a>
        </div>
    </div>

    <div class="hero-slide">
        <div class="hero-overlay"></div>

        <div class="hero-content">
            <h1 class="hero-title">
                Discover Filipino Stories
            </h1>

            <p class="hero-description">
                Explore stories, ideas, and voices from Filipino writers.
            </p>

            <a href="/books.php" class="hero-button">
                Browse the Catalog
            </a>
        </div>
    </div>

    <div class="hero-slide">
        <div class="hero-overlay"></div>

        <div class="hero-content">
            <h1 class="hero-title">
                Find Your Next Favorite Book
            </h1>

            <p class="hero-description">
                Browse our growing collection of local literature.
            </p>

            <a href="/books.php" class="hero-button">
                Browse the Catalog
            </a>
        </div>
    </div>

    <button
        class="hero-arrow hero-prev"
        type="button"
        aria-label="Previous slide"
    >
        &#10094;
    </button>

    <button
        class="hero-arrow hero-next"
        type="button"
        aria-label="Next slide"
    >
        &#10095;
    </button>

    <div class="hero-dots">
        <button class="hero-dot active" type="button" aria-label="Slide 1"></button>
        <button class="hero-dot" type="button" aria-label="Slide 2"></button>
        <button class="hero-dot" type="button" aria-label="Slide 3"></button>
    </div>

</section>

<section class="home-section">

    <div class="section-container">

        <h2 class="section-title">
            Today's Spotlight
        </h2>

        <div class="spotlight-grid">

            <article class="spotlight-card author-spotlight">

                <div class="spotlight-content">

                    <span class="spotlight-label">
                        Author of the Day
                    </span>

                    <div class="author-image-placeholder">
                        Author Image
                    </div>

                    <div class="spotlight-info">

                        <h3>
                            [Author Name]
                        </h3>

                        <p>
                            [Short author description]
                        </p>

                        <a href="/authors.php" class="text-link">
                            View Author Profile →
                        </a>

                    </div>

                </div>

            </article>

            <article class="spotlight-card book-spotlight">

                <div class="spotlight-content">

                    <span class="spotlight-label">
                        Book of the Day
                    </span>

                    <div class="book-image-placeholder">
                        Book Image
                    </div>

                    <div class="spotlight-info">

                        <h3>
                            [Book Name]
                        </h3>

                        <p>
                            [Author Name]
                        </p>

                        <p>
                            [Genre]
                        </p>

                        <p class="spotlight-synopsis">
                            [Short synopsis of the featured book.]
                        </p>

                        <a href="/books.php" class="text-link">
                            View Book →
                        </a>

                    </div>

                </div>

            </article>

        </div>

    </div>

</section>

<section class="home-section featured-books-section">

    <div class="section-container">

        <h2 class="section-title">
            Featured Books
        </h2>

        <div class="featured-books-grid">

            <article class="book-card">

                <div class="book-card-image">
                    Book Image
                </div>

                <div class="book-card-content">

                    <h3 class="book-card-title">
                        [Book Name]
                    </h3>

                    <p class="book-card-author">
                        [Author]
                    </p>

                    <p class="book-card-price">
                        ₱[Price]
                    </p>

                    <a href="#" class="add-cart-button">
                        Add to Cart
                    </a>

                </div>

            </article>

            <article class="book-card">

                <div class="book-card-image">
                    Book Image
                </div>

                <div class="book-card-content">

                    <h3 class="book-card-title">
                        [Book Name]
                    </h3>

                    <p class="book-card-author">
                        [Author]
                    </p>

                    <p class="book-card-price">
                        ₱[Price]
                    </p>

                    <a href="#" class="add-cart-button">
                        Add to Cart
                    </a>

                </div>

            </article>

            <article class="book-card">

                <div class="book-card-image">
                    Book Image
                </div>

                <div class="book-card-content">

                    <h3 class="book-card-title">
                        [Book Name]
                    </h3>

                    <p class="book-card-author">
                        [Author]
                    </p>

                    <p class="book-card-price">
                        ₱[Price]
                    </p>

                    <a href="#" class="add-cart-button">
                        Add to Cart
                    </a>

                </div>

            </article>

            <article class="book-card">

                <div class="book-card-image">
                    Book Image
                </div>

                <div class="book-card-content">

                    <h3 class="book-card-title">
                        [Book Name]
                    </h3>

                    <p class="book-card-author">
                        [Author]
                    </p>

                    <p class="book-card-price">
                        ₱[Price]
                    </p>

                    <a href="#" class="add-cart-button">
                        Add to Cart
                    </a>

                </div>

            </article>

        </div>

    </div>

</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const slides = document.querySelectorAll('.hero-slide');
    const dots = document.querySelectorAll('.hero-dot');
    const previousButton = document.querySelector('.hero-prev');
    const nextButton = document.querySelector('.hero-next');

    let currentSlide = 0;
    let slideInterval;

    function showSlide(index) {
        slides.forEach(function (slide) {
            slide.classList.remove('active');
        });

        dots.forEach(function (dot) {
            dot.classList.remove('active');
        });

        slides[index].classList.add('active');
        dots[index].classList.add('active');

        currentSlide = index;
    }

    function nextSlide() {
        showSlide((currentSlide + 1) % slides.length);
    }

    function previousSlide() {
        showSlide((currentSlide - 1 + slides.length) % slides.length);
    }

    function startSlider() {
        slideInterval = setInterval(nextSlide, 5000);
    }

    function resetSlider() {
        clearInterval(slideInterval);
        startSlider();
    }

    nextButton.addEventListener('click', function () {
        nextSlide();
        resetSlider();
    });

    previousButton.addEventListener('click', function () {
        previousSlide();
        resetSlider();
    });

    dots.forEach(function (dot, index) {
        dot.addEventListener('click', function () {
            showSlide(index);
            resetSlider();
        });
    });

    startSlider();
});
</script>

<?php include 'includes/footer.php'; ?>