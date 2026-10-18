
<?php
$pageTitle = "TICKETA | Find Events & Buy Tickets Easily";
$pageStyles = [];

require __DIR__ . '/includes/header.php';

$categories = [
    ['title' => 'Concert', 'image' => 'assets/images/category-concert.jpg', 'query' => 'concert'],
    ['title' => 'Tech & Startups', 'image' => 'assets/images/category-tech.jpg', 'query' => 'technology'],
    ['title' => 'Comedy', 'image' => 'assets/images/category-comedy.jpg', 'query' => 'comedy'],
    ['title' => 'Festivals', 'image' => 'assets/images/category-festival.jpg', 'query' => 'festival'],
];
?>

<main class="home-page">

    <!-- HERO -->
    <section class="home-hero" id="home">
        <div class="home-hero-shade"></div>

        <div class="home-container home-hero-inner">
            <div class="home-hero-copy">
                <p class="home-eyebrow" data-i18n="hero_eyebrow">
                    YOUR NEXT EXPERIENCE STARTS HERE
                </p>

                <h1 data-i18n="hero_title">
                    Find Events &amp; Buy<br>
                    Tickets Easily
                </h1>

                <p class="home-hero-description" data-i18n="hero_description">
                    Discover concerts, festivals, parties and unforgettable experiences happening around you.
                </p>
            </div>

            <form action="events.php" method="get" class="home-search-form">
                <label class="home-search-field">
                    <span class="home-field-icon" aria-hidden="true">⌕</span>
                    <span class="home-field-content">
                        <span class="home-field-label" data-i18n="search_event_label">Event</span>
                        <input
                            type="search"
                            name="q"
                            placeholder="What event are you looking for?"
                            data-i18n-placeholder="search_event_placeholder"
                        >
                    </span>
                </label>

                <label class="home-search-field">
                    <span class="home-field-icon" aria-hidden="true">⌖</span>
                    <span class="home-field-content">
                        <span class="home-field-label" data-i18n="search_location_label">Location</span>
                        <select name="city">
                            <option value="" data-i18n="all_locations">All locations</option>
                            <option value="Abidjan">Abidjan</option>
                            <option value="Bouaké">Bouaké</option>
                            <option value="Yamoussoukro">Yamoussoukro</option>
                            <option value="Grand-Bassam">Grand-Bassam</option>
                        </select>
                    </span>
                </label>

                <label class="home-search-field home-date-field">
                    <span class="home-field-icon" aria-hidden="true">▦</span>
                    <span class="home-field-content">
                        <span class="home-field-label" data-i18n="search_date_label">Date</span>
                        <input type="date" name="date" aria-label="Event date">
                    </span>
                </label>

                <button class="home-search-button" type="submit" data-i18n="search_button">
                    Search Events
                </button>
            </form>

            <p class="home-hero-footnote" data-i18n="hero_footnote">
                Discover experiences. Connect with people. Make memories.
            </p>
        </div>
    </section>

    <!-- POPULAR CATEGORIES -->
    <section class="home-section home-categories" id="categories">
        <div class="home-container">
            <div class="home-section-heading">
                <div>
                    <p class="home-section-label" data-i18n="categories_label">EXPLORE BY CATEGORY</p>
                    <h2 data-i18n="categories_title">Popular Categories</h2>
                    <p class="home-section-description" data-i18n="categories_description">
                        Explore events that match your interests.
                    </p>
                </div>

                <a class="home-view-all" href="events.php">
                    <span data-i18n="view_all">View all events</span>
                    <span aria-hidden="true">→</span>
                </a>
            </div>

            <div class="home-category-grid">
                <?php foreach ($categories as $category): ?>
                    <a
                        class="home-category-card"
                        href="events.php?category=<?php echo rawurlencode($category['query']); ?>"
                    >
                        <div class="home-category-image">
                            <img
                                src="<?php echo htmlspecialchars($category['image'], ENT_QUOTES, 'UTF-8'); ?>"
                                alt="<?php echo htmlspecialchars($category['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                loading="lazy"
                            >
                            <span class="home-category-arrow" aria-hidden="true">↗</span>
                        </div>

                        <div class="home-category-caption">
                            <h3><?php echo htmlspecialchars($category['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                            <span aria-hidden="true">→</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- TRENDING EVENTS -->
    <section class="home-section home-trending" id="trending">
        <div class="home-container">
            <div class="home-section-heading">
                <div>
                    <p class="home-section-label" data-i18n="trending_label">WHAT'S HAPPENING</p>
                    <h2 data-i18n="trending_title">Trending Events</h2>
                    <p class="home-section-description" data-i18n="trending_description">
                        Find your next unforgettable experience.
                    </p>
                </div>

                <a class="home-view-all" href="events.php">
                    <span data-i18n="view_all">View all events</span>
                    <span aria-hidden="true">→</span>
                </a>
            </div>

            <div class="home-trending-empty">
                <div class="home-empty-symbol" aria-hidden="true">✦</div>
                <h3 data-i18n="trending_empty_title">Your next event is waiting</h3>
                <p data-i18n="trending_empty_description">
                    Browse the events page to discover available listings.
                </p>
                <a href="events.php" class="home-primary-button" data-i18n="browse_events">
                    Browse Events
                </a>
            </div>
        </div>
    </section>

    <!-- HOW IT WORKS / TRUST -->
    <section class="home-section home-how" id="how-it-works">
        <div class="home-container">
            <div class="home-centered-heading">
                <p class="home-section-label" data-i18n="how_label">THE TICKETA EXPERIENCE</p>
                <h2 data-i18n="how_title">Your next great experience, made easy</h2>
                <p class="home-section-description" data-i18n="how_description">
                    Everything you need to discover and enjoy events, all in one place.
                </p>
            </div>

            <div class="home-benefits-grid">
                <article class="home-benefit-card">
                    <div class="home-benefit-icon" aria-hidden="true">⌕</div>
                    <h3 data-i18n="benefit_one_title">Discover Events</h3>
                    <p data-i18n="benefit_one_description">
                        Explore concerts, festivals and experiences happening around you.
                    </p>
                </article>

                <article class="home-benefit-card">
                    <div class="home-benefit-icon" aria-hidden="true">▤</div>
                    <h3 data-i18n="benefit_two_title">Easy Ticket Selection</h3>
                    <p data-i18n="benefit_two_description">
                        Find the right event and choose the ticket option that suits you.
                    </p>
                </article>

                <article class="home-benefit-card">
                    <div class="home-benefit-icon" aria-hidden="true">✓</div>
                    <h3 data-i18n="benefit_three_title">Enjoy the Experience</h3>
                    <p data-i18n="benefit_three_description">
                        Get ready for memorable moments with friends, family and your community.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <!-- ORGANIZER CTA -->
    <section class="home-organizer-section">
        <div class="home-container">
            <div class="home-organizer-banner">
                <div class="home-organizer-background" aria-hidden="true"></div>
                <div class="home-organizer-shade"></div>

                <div class="home-organizer-copy">
                    <p class="home-section-label" data-i18n="organizer_label">FOR EVENT ORGANIZERS</p>
                    <h2 data-i18n="organizer_title">Make your event the next big thing.</h2>
                    <p data-i18n="organizer_description">
                        Reach more people and bring your event to a wider audience with Ticketa.
                    </p>
                    <a href="organizer/register.php" class="home-organizer-button">
                        <span data-i18n="organizer_button">Start Selling Tickets</span>
                        <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>
        </div>
    </section>

</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
