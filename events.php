
<?php
function escapeHtml($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$search   = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');
$city     = trim($_GET['city'] ?? 'Abidjan');
$date     = trim($_GET['date'] ?? '');
$price    = trim($_GET['price'] ?? '');
$sort     = trim($_GET['sort'] ?? 'trending');

/*
 * Temporary layout preview data only.
 * Replace these examples with real MySQL event records.
 */
$events = [
    [
        'title' => 'Live Music Night',
        'date' => 'Nov 21',
        'time' => '19:00',
        'venue' => 'Palais de la Culture, Abidjan',
        'price' => 'From 10,000 FCFA',
        'image' => 'assets/images/event-music.jpg'
    ],
    [
        'title' => 'Technology Innovation Meetup',
        'date' => 'Nov 25',
        'time' => '10:00',
        'venue' => 'Cocody, Abidjan',
        'price' => 'Free',
        'image' => 'assets/images/event-tech.jpg'
    ],
    [
        'title' => 'Comedy Night',
        'date' => 'Nov 28',
        'time' => '20:00',
        'venue' => 'Marcory, Abidjan',
        'price' => 'From 5,000 FCFA',
        'image' => 'assets/images/event-comedy.jpg'
    ],
    [
        'title' => 'Cultural Festival',
        'date' => 'Dec 05',
        'time' => '16:00',
        'venue' => 'Grand-Bassam, Côte d’Ivoire',
        'price' => 'From 7,500 FCFA',
        'image' => 'assets/images/event-festival.jpg'
    ]
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Browse Events | TICKETA</title>
    <meta name="description" content="Discover events across Côte d’Ivoire with TICKETA.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Shared homepage stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- Events page styles only: header/footer styles stay in style.css -->
    <style>
        .browse-events-page {
            width: 100%;
            max-width: 1440px;
            margin: 0 auto;
            padding: 115px 4% 80px;
            color: #171717;
            background: #fff;
        }

        .browse-events-heading {
            margin-bottom: 28px;
        }

        .browse-events-heading h1 {
            margin: 0 0 12px;
            font-size: clamp(30px, 3vw, 42px);
            line-height: 1.2;
            font-weight: 600;
            letter-spacing: -1px;
        }

        .browse-events-heading p {
            margin: 0;
            color: #444;
            font-size: clamp(17px, 2vw, 22px);
        }

        .browse-search-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 170px;
            gap: 24px;
            margin-bottom: 30px;
        }

        .browse-search-input {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 0;
            height: 60px;
            padding: 0 20px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 3px 5px rgb(0 0 0 / 12%);
        }

        .browse-search-input svg {
            width: 23px;
            height: 23px;
            flex-shrink: 0;
        }

        .browse-search-input input {
            width: 100%;
            min-width: 0;
            border: 0;
            outline: 0;
            background: transparent;
            color: #111;
            font: inherit;
            font-size: 15px;
        }

        .browse-search-button,
        .browse-apply-button {
            min-height: 46px;
            padding: 10px 18px;
            border: 0;
            border-radius: 6px;
            background: #16b99f;
            color: #fff;
            font: inherit;
            cursor: pointer;
            transition: background .2s ease;
        }

        .browse-search-button {
            min-height: 60px;
        }

        .browse-search-button:hover,
        .browse-apply-button:hover {
            background: #109d88;
        }

        .browse-filters {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 34px;
        }

        .browse-filter {
            max-width: 100%;
            min-height: 45px;
            padding: 0 30px 0 13px;
            border: 1px solid #999;
            border-radius: 5px;
            background: #fff;
            color: #222;
            font: inherit;
            font-size: 14px;
            cursor: pointer;
        }

        .browse-sort {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-left: auto;
            font-size: 14px;
        }

        .browse-results-count {
            margin: 0 0 24px;
            color: #333;
            font-size: 14px;
        }

        .browse-demo-notice {
            margin: 0 0 22px;
            padding: 12px 15px;
            border-left: 4px solid #16b99f;
            background: #effaf7;
            color: #24584f;
            font-size: 13px;
            line-height: 1.5;
        }

        .browse-events-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 60px 28px;
        }

        .browse-event-card {
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
            border: 1px solid #bbb;
            border-radius: 12px;
            background: #fff;
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .browse-event-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 22px rgb(0 0 0 / 10%);
        }

        .browse-event-image {
            display: block;
            width: 100%;
            aspect-ratio: 3 / 3.7;
            object-fit: cover;
            background: #f0f2f2;
        }

        .browse-event-content {
            display: flex;
            flex: 1;
            flex-direction: column;
            padding: 12px 9px 10px;
        }

        .browse-event-title {
            margin: 0 0 6px;
            color: #111;
            font-size: 17px;
            font-weight: 600;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .browse-event-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 5px 14px;
            margin-bottom: 6px;
            color: #333;
            font-size: 13px;
        }

        .browse-event-venue {
            margin: 0 0 15px;
            color: #777;
            font-size: 12px;
            line-height: 1.5;
        }

        .browse-event-price {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 42px;
            width: 100%;
            margin-top: auto;
            padding: 8px;
            border-radius: 5px;
            background: #16b99f;
            color: #fff;
            font-size: 14px;
            font-weight: 500;
            text-align: center;
        }

        .browse-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 9px;
            margin-top: 75px;
        }

        .browse-pagination span {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            min-width: 38px;
            min-height: 38px;
            padding: 0 10px;
            border-radius: 5px;
            color: #222;
            font-size: 14px;
        }

        .browse-pagination .current {
            background: #16b99f;
            color: #fff;
        }

        @media (max-width: 1050px) {
            .browse-events-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 38px 22px;
            }
        }

        @media (max-width: 800px) {
            .browse-events-page {
                padding-top: 105px;
            }

            .browse-events-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 30px 16px;
            }

            .browse-sort {
                width: 100%;
                margin-left: 0;
            }

            .browse-filter {
                flex: 1 1 40%;
                min-width: 0;
            }
        }

        @media (max-width: 520px) {
            .browse-events-page {
                padding: 100px 5% 45px;
            }

            .browse-search-form {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .browse-search-button {
                width: 100%;
            }

            .browse-filters {
                gap: 10px;
            }

            .browse-filter {
                padding-inline: 9px 22px;
                font-size: 12px;
            }

            .browse-event-title {
                font-size: 15px;
            }

            .browse-event-meta {
                gap: 4px 8px;
                font-size: 12px;
            }

            .browse-event-content {
                padding: 10px 7px 8px;
            }

            .browse-event-price {
                font-size: 12px;
            }

            .browse-pagination {
                gap: 4px;
                margin-top: 45px;
            }

            .browse-pagination span {
                min-width: 32px;
                min-height: 34px;
                padding-inline: 7px;
            }
        }

        @media (max-width: 360px) {
            .browse-events-grid {
                grid-template-columns: 1fr;
            }

            .browse-event-image {
                aspect-ratio: 4 / 3;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .browse-event-card {
                transition: none;
            }
        }
    </style>
</head>

<body>

    <!-- EXACT SHARED HOMEPAGE HEADER -->
    <?php require __DIR__ . '/includes/header.php'; ?>

    <main class="browse-events-page">

        <section class="browse-events-heading">
            <h1>Browse Events</h1>
            <p>Find events happening near you</p>
        </section>

        <!-- Search -->
        <form action="events.php" method="GET" class="browse-search-form">
            <div class="browse-search-input">
                <svg viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2"
                     aria-hidden="true">
                    <circle cx="11" cy="11" r="7"></circle>
                    <path d="M16 16L21 21"></path>
                </svg>

                <input
                    type="search"
                    name="q"
                    value="<?= escapeHtml($search) ?>"
                    placeholder="Search events or artists"
                    aria-label="Search events or artists"
                >
            </div>

            <button type="submit" class="browse-search-button">Search</button>

            <input type="hidden" name="category" value="<?= escapeHtml($category) ?>">
            <input type="hidden" name="city" value="<?= escapeHtml($city) ?>">
            <input type="hidden" name="date" value="<?= escapeHtml($date) ?>">
            <input type="hidden" name="price" value="<?= escapeHtml($price) ?>">
            <input type="hidden" name="sort" value="<?= escapeHtml($sort) ?>">
        </form>

        <!-- Filters -->
        <form action="events.php" method="GET" class="browse-filters">
            <input type="hidden" name="q" value="<?= escapeHtml($search) ?>">

            <select name="category" class="browse-filter" aria-label="Category">
                <option value="">All Categories</option>
                <option value="music" <?= $category === 'music' ? 'selected' : '' ?>>Music &amp; Concerts</option>
                <option value="technology" <?= $category === 'technology' ? 'selected' : '' ?>>Technology</option>
                <option value="comedy" <?= $category === 'comedy' ? 'selected' : '' ?>>Comedy</option>
                <option value="festival" <?= $category === 'festival' ? 'selected' : '' ?>>Festivals</option>
                <option value="business" <?= $category === 'business' ? 'selected' : '' ?>>Business</option>
            </select>

            <select name="city" class="browse-filter" aria-label="Location">
                <option value="">All Locations</option>
                <option value="Abidjan" <?= $city === 'Abidjan' ? 'selected' : '' ?>>Abidjan, Côte d’Ivoire</option>
                <option value="Cocody" <?= $city === 'Cocody' ? 'selected' : '' ?>>Cocody</option>
                <option value="Marcory" <?= $city === 'Marcory' ? 'selected' : '' ?>>Marcory</option>
                <option value="Yopougon" <?= $city === 'Yopougon' ? 'selected' : '' ?>>Yopougon</option>
                <option value="Grand-Bassam" <?= $city === 'Grand-Bassam' ? 'selected' : '' ?>>Grand-Bassam</option>
            </select>

            <select name="date" class="browse-filter" aria-label="Date">
                <option value="">All Dates</option>
                <option value="today" <?= $date === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="weekend" <?= $date === 'weekend' ? 'selected' : '' ?>>This Weekend</option>
                <option value="month" <?= $date === 'month' ? 'selected' : '' ?>>This Month</option>
            </select>

            <select name="price" class="browse-filter" aria-label="Price">
                <option value="">Any Price</option>
                <option value="free" <?= $price === 'free' ? 'selected' : '' ?>>Free</option>
                <option value="under5000" <?= $price === 'under5000' ? 'selected' : '' ?>>Under 5,000 FCFA</option>
                <option value="under10000" <?= $price === 'under10000' ? 'selected' : '' ?>>Under 10,000 FCFA</option>
            </select>

            <div class="browse-sort">
                <label for="sortEvents">Sort By:</label>
                <select name="sort" id="sortEvents" class="browse-filter">
                    <option value="trending" <?= $sort === 'trending' ? 'selected' : '' ?>>Trending</option>
                    <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>Date</option>
                    <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: Low to High</option>
                </select>
            </div>

            <button type="submit" class="browse-apply-button">Apply Filters</button>
        </form>

        <p class="browse-demo-notice">
            Preview mode: these cards are examples for testing the design,
            not confirmed live event listings.
        </p>

        <p class="browse-results-count">
            Showing <?= count($events) ?> demo event cards
        </p>

        <!-- Event grid -->
        <section class="browse-events-grid" aria-label="Browse events">
            <?php foreach ($events as $event): ?>
                <article class="browse-event-card">
                    <img
                        src="<?= escapeHtml($event['image']) ?>"
                        alt="<?= escapeHtml($event['title']) ?> poster"
                        class="browse-event-image"
                        loading="lazy"
                    >

                    <div class="browse-event-content">
                        <h2 class="browse-event-title">
                            <?= escapeHtml($event['title']) ?>
                        </h2>

                        <div class="browse-event-meta">
                            <span><?= escapeHtml($event['date']) ?></span>
                            <span><?= escapeHtml($event['time']) ?></span>
                        </div>

                        <p class="browse-event-venue">
                            <?= escapeHtml($event['venue']) ?>
                        </p>

                        <span class="browse-event-price">
                            <?= escapeHtml($event['price']) ?>
                        </span>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <!-- Visual pagination preview -->
        <nav class="browse-pagination" aria-label="Event pagination">
            <span>‹ Previous</span>
            <span class="current" aria-current="page">1</span>
            <span>2</span>
            <span>3</span>
            <span>4</span>
            <span>5</span>
            <span>Next ›</span>
        </nav>

    </main>

    <!-- EXACT SHARED HOMEPAGE FOOTER -->
    <?php require __DIR__ . '/includes/footer.php'; ?>

    <!-- Shared homepage JavaScript -->
    <script src="assets/js/script.js"></script>

    <script>
        const sortSelect = document.getElementById('sortEvents');

        if (sortSelect) {
            sortSelect.addEventListener('change', function () {
                this.form.requestSubmit();
            });
        }
    </script>

</body>
</html>
