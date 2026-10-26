<?php
$pageTitle = "TICKETA | Find Events & Buy Tickets Easily";
$pageStyles = [];

// Load the same database used by the organizer dashboard.
require_once __DIR__ . '/config/database.php';

$trendingEvents = [];
$eventsLoadError = false;

try {
    // Only show published events that have not ended yet.
    $eventsStmt = $pdo->prepare(
        "SELECT
            e.id,
            e.title,
            e.slug,
            e.description,
            e.event_date,
            e.event_end_date,
            e.venue,
            e.city,
            e.image_url,
            c.name AS category_name,
            COALESCE((
                SELECT MIN(tt.price)
                FROM ticket_types tt
                WHERE tt.event_id = e.id
                  AND tt.is_active = 1
            ), 0) AS starting_price,
            COALESCE((
                SELECT SUM(tt.quantity_total)
                FROM ticket_types tt
                WHERE tt.event_id = e.id
                  AND tt.is_active = 1
            ), 0) AS total_capacity,
            COALESCE((
                SELECT SUM(tt.quantity_sold)
                FROM ticket_types tt
                WHERE tt.event_id = e.id
                  AND tt.is_active = 1
            ), 0) AS total_sold
         FROM events e
         LEFT JOIN categories c ON c.id = e.category_id
         WHERE e.status = 'published'
           AND (
                e.event_end_date IS NULL AND e.event_date >= NOW()
                OR e.event_end_date IS NOT NULL AND e.event_end_date >= NOW()
           )
         ORDER BY e.event_date ASC, e.id DESC
         LIMIT 6"
    );
    $eventsStmt->execute();
    $trendingEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('Ticketa homepage event query failed: ' . $exception->getMessage());
    $eventsLoadError = true;
}

function ticketaHomeEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ticketaHomeDate($value): string
{
    if (empty($value)) {
        return 'Date to be announced';
    }

    try {
        return (new DateTime((string) $value))->format('D, d M Y · H:i');
    } catch (Throwable $exception) {
        return 'Date to be announced';
    }
}

function ticketaHomeImageUrl($value): string
{
    $image = trim((string) ($value ?? ''));

    if ($image === '') {
        return '';
    }

    // Keep absolute URLs and root-relative URLs as supplied.
    if (preg_match('~^(?:https?://|/)~i', $image)) {
        return $image;
    }

    // Uploaded Ticketa event images are stored relative to the project root.
    return $image;
}

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

            <?php if ($eventsLoadError): ?>
                <div class="home-trending-empty">
                    <div class="home-empty-symbol" aria-hidden="true">!</div>
                    <h3>Events are temporarily unavailable</h3>
                    <p>Please refresh the page in a moment. If the problem continues, check the database connection.</p>
                    <a href="events.php" class="home-primary-button">Browse Events</a>
                </div>
            <?php elseif (!$trendingEvents): ?>
                <div class="home-trending-empty">
                    <div class="home-empty-symbol" aria-hidden="true">✦</div>
                    <h3 data-i18n="trending_empty_title">Your next event is waiting</h3>
                    <p data-i18n="trending_empty_description">
                        There are no upcoming published events yet. Check back soon or browse all events.
                    </p>
                    <a href="events.php" class="home-primary-button" data-i18n="browse_events">
                        Browse Events
                    </a>
                </div>
            <?php else: ?>
                <div class="ticketa-event-grid">
                    <?php foreach ($trendingEvents as $event): ?>
                        <?php
                            $eventImage = ticketaHomeImageUrl($event['image_url'] ?? '');
                            $eventTitle = (string) ($event['title'] ?? 'Untitled event');
                            $eventSlug = trim((string) ($event['slug'] ?? ''));
                            $eventHref = 'event-details.php?' . http_build_query(['id' => (int) $event['id']]);
                            $totalCapacity = (int) ($event['total_capacity'] ?? 0);
                            $totalSold = (int) ($event['total_sold'] ?? 0);
                            $isSoldOut = $totalCapacity > 0 && $totalSold >= $totalCapacity;
                            $eventLocation = trim((string) ($event['venue'] ?? ''));
                            $eventCity = trim((string) ($event['city'] ?? ''));
                            if ($eventLocation === '') {
                                $eventLocation = $eventCity !== '' ? $eventCity : 'Location to be announced';
                            } elseif ($eventCity !== '') {
                                $eventLocation .= ', ' . $eventCity;
                            }
                        ?>
                        <article class="ticketa-event-card">
                            <a class="ticketa-event-card-image" href="<?= ticketaHomeEscape($eventHref) ?>" aria-label="View <?= ticketaHomeEscape($eventTitle) ?>">
                                <?php if ($eventImage !== ''): ?>
                                    <img
                                        src="<?= ticketaHomeEscape($eventImage) ?>"
                                        alt="<?= ticketaHomeEscape($eventTitle) ?>"
                                        loading="lazy"
                                        onerror="this.style.display='none';this.parentElement.classList.add('image-unavailable');"
                                    >
                                <?php else: ?>
                                    <span class="ticketa-event-image-placeholder" aria-hidden="true">✦</span>
                                <?php endif; ?>
                                <span class="ticketa-event-category"><?= ticketaHomeEscape($event['category_name'] ?? 'Event') ?></span>
                                <?php if ($isSoldOut): ?>
                                    <span class="ticketa-event-sold-out" role="status">SOLD OUT</span>
                                <?php endif; ?>
                            </a>
                            <div class="ticketa-event-card-body">
                                <p class="ticketa-event-date"><?= ticketaHomeEscape(ticketaHomeDate($event['event_date'] ?? null)) ?></p>
                                <h3><a href="<?= ticketaHomeEscape($eventHref) ?>"><?= ticketaHomeEscape($eventTitle) ?></a></h3>
                                <p class="ticketa-event-location"><span aria-hidden="true">⌖</span> <?= ticketaHomeEscape($eventLocation) ?></p>
                                <div class="ticketa-event-card-bottom">
                                    <span class="ticketa-event-price<?= $isSoldOut ? ' ticketa-event-price-sold-out' : '' ?>">
                                        <?php if ($isSoldOut): ?>
                                            Sold Out
                                        <?php elseif ((float) ($event['starting_price'] ?? 0) > 0): ?>
                                            From <?= number_format((float) $event['starting_price'], 0, ',', ' ') ?> FCFA
                                        <?php else: ?>
                                            Free / price TBA
                                        <?php endif; ?>
                                    </span>
                                    <a class="ticketa-event-open<?= $isSoldOut ? ' ticketa-event-open-sold-out' : '' ?>" href="<?= ticketaHomeEscape($eventHref) ?>" aria-label="<?= $isSoldOut ? 'View sold-out event details' : 'View event details' ?>">→</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <style>
                .ticketa-event-grid {
                    display: grid;
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                    gap: 22px;
                    margin-top: 28px;
                }
                .ticketa-event-card {
                    min-width: 0;
                    overflow: hidden;
                    border: 1px solid #e9eaf0;
                    border-radius: 16px;
                    background: #fff;
                    box-shadow: 0 8px 28px rgba(20, 25, 50, .06);
                    transition: transform .2s ease, box-shadow .2s ease;
                }
                .ticketa-event-card:hover {
                    transform: translateY(-4px);
                    box-shadow: 0 14px 32px rgba(20, 25, 50, .1);
                }
                .ticketa-event-card-image {
                    position: relative;
                    display: grid;
                    height: 210px;
                    overflow: hidden;
                    place-items: center;
                    background: linear-gradient(135deg, #e7fbf6, #eeebff);
                    text-decoration: none;
                }
                .ticketa-event-card-image img {
                    display: block;
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                }
                .ticketa-event-card-image.image-unavailable img { display: none; }
                .ticketa-event-image-placeholder { color: #10b99b; font-size: 44px; }
                .ticketa-event-category {
                    position: absolute;
                    left: 12px;
                    bottom: 12px;
                    max-width: calc(100% - 24px);
                    overflow: hidden;
                    padding: 7px 10px;
                    border-radius: 20px;
                    background: rgba(255,255,255,.94);
                    color: #25283a;
                    font-size: 11px;
                    font-weight: 700;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }
                .ticketa-event-sold-out {
                    position: absolute;
                    top: 12px;
                    right: 12px;
                    z-index: 2;
                    padding: 8px 11px;
                    border-radius: 6px;
                    background: #c62828;
                    color: #fff;
                    font-size: 11px;
                    font-weight: 800;
                    letter-spacing: .5px;
                    box-shadow: 0 3px 10px rgba(0, 0, 0, .18);
                }
                .ticketa-event-card-body { padding: 18px; }
                .ticketa-event-date { margin: 0 0 9px; color: #0ca98d; font-size: 12px; font-weight: 700; }
                .ticketa-event-card-body h3 { margin: 0 0 10px; font-size: 18px; line-height: 1.4; }
                .ticketa-event-card-body h3 a { color: #171c2e; text-decoration: none; }
                .ticketa-event-card-body h3 a:hover { color: #08ad91; }
                .ticketa-event-location { display: flex; align-items: flex-start; gap: 7px; margin: 0; color: #777d8d; font-size: 12px; line-height: 1.6; }
                .ticketa-event-card-bottom { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #eff0f4; }
                .ticketa-event-price { color: #202438; font-size: 13px; font-weight: 750; }
                .ticketa-event-open { display: grid; width: 34px; height: 34px; flex: 0 0 34px; place-items: center; border-radius: 50%; background: #10c6a4; color: #fff; font-size: 19px; text-decoration: none; }
                .ticketa-event-price-sold-out { color: #b42318; }
                .ticketa-event-open-sold-out { background: #8b9099; }
                @media (max-width: 900px) { .ticketa-event-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
                @media (max-width: 560px) { .ticketa-event-grid { grid-template-columns: 1fr; gap: 16px; } .ticketa-event-card-image { height: 220px; } }
            </style>
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
