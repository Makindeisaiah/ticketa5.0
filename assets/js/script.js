
document.addEventListener("DOMContentLoaded", () => {
    /* MOBILE NAVIGATION */

    const toggle = document.getElementById("mobileMenuToggle");
    const navigation = document.getElementById("mainNavigation");

    if (toggle && navigation) {
        const closeMenu = () => {
            navigation.classList.remove("is-open");
            toggle.classList.remove("is-active");
            toggle.setAttribute("aria-expanded", "false");
            toggle.setAttribute("aria-label", "Open navigation");
        };

        toggle.addEventListener("click", () => {
            const open = toggle.getAttribute("aria-expanded") === "true";

            if (open) {
                closeMenu();
            } else {
                navigation.classList.add("is-open");
                toggle.classList.add("is-active");
                toggle.setAttribute("aria-expanded", "true");
                toggle.setAttribute("aria-label", "Close navigation");
            }
        });

        navigation.querySelectorAll("a").forEach(link => {
            link.addEventListener("click", closeMenu);
        });

        document.addEventListener("click", event => {
            if (!navigation.contains(event.target) && !toggle.contains(event.target)) {
                closeMenu();
            }
        });

        document.addEventListener("keydown", event => {
            if (event.key === "Escape") closeMenu();
        });

        window.addEventListener("resize", () => {
            if (window.innerWidth > 1000) closeMenu();
        });
    }

    /* ENGLISH / FRENCH */

    const translations = {
        en: {
            nav_browse: "Browse Events",
            nav_how: "How it works",
            nav_help: "Help",
            nav_login: "Login",
            nav_sell: "Sell Tickets",

            hero_eyebrow: "YOUR NEXT EXPERIENCE STARTS HERE",
            hero_title: "Find Events &amp; Buy<br>Tickets Easily",
            hero_description: "Discover concerts, festivals, parties and unforgettable experiences happening around you.",
            search_event_label: "Event",
            search_event_placeholder: "What event are you looking for?",
            search_location_label: "Location",
            search_date_label: "Date",
            all_locations: "All locations",
            search_button: "Search Events",
            hero_footnote: "Discover experiences. Connect with people. Make memories.",

            categories_label: "EXPLORE BY CATEGORY",
            categories_title: "Popular Categories",
            categories_description: "Explore events that match your interests.",
            view_all: "View all events",

            trending_label: "WHAT'S HAPPENING",
            trending_title: "Trending Events",
            trending_description: "Find your next unforgettable experience.",
            trending_empty_title: "Your next event is waiting",
            trending_empty_description: "Browse the events page to discover available listings.",
            browse_events: "Browse Events",

            how_label: "THE TICKETA EXPERIENCE",
            how_title: "Your next great experience, made easy",
            how_description: "Everything you need to discover and enjoy events, all in one place.",
            benefit_one_title: "Discover Events",
            benefit_one_description: "Explore concerts, festivals and experiences happening around you.",
            benefit_two_title: "Easy Ticket Selection",
            benefit_two_description: "Find the right event and choose the ticket option that suits you.",
            benefit_three_title: "Enjoy the Experience",
            benefit_three_description: "Get ready for memorable moments with friends, family and your community.",

            organizer_label: "FOR EVENT ORGANIZERS",
            organizer_title: "Make your event the next big thing.",
            organizer_description: "Reach more people and bring your event to a wider audience with Ticketa.",
            organizer_button: "Start Selling Tickets"
        },

        fr: {
            nav_browse: "Découvrir les événements",
            nav_how: "Comment ça marche",
            nav_help: "Aide",
            nav_login: "Connexion",
            nav_sell: "Vendre des billets",

            hero_eyebrow: "VOTRE PROCHAINE EXPÉRIENCE COMMENCE ICI",
            hero_title: "Trouvez des événements<br>et achetez vos billets",
            hero_description: "Découvrez des concerts, festivals, soirées et expériences inoubliables près de chez vous.",
            search_event_label: "Événement",
            search_event_placeholder: "Quel événement recherchez-vous ?",
            search_location_label: "Lieu",
            search_date_label: "Date",
            all_locations: "Tous les lieux",
            search_button: "Rechercher",
            hero_footnote: "Découvrez des expériences. Rencontrez des gens. Créez des souvenirs.",

            categories_label: "EXPLORER PAR CATÉGORIE",
            categories_title: "Catégories populaires",
            categories_description: "Découvrez des événements qui correspondent à vos envies.",
            view_all: "Voir tous les événements",

            trending_label: "À L'AFFICHE",
            trending_title: "Événements tendance",
            trending_description: "Trouvez votre prochaine expérience inoubliable.",
            trending_empty_title: "Votre prochain événement vous attend",
            trending_empty_description: "Consultez la page des événements pour découvrir les annonces disponibles.",
            browse_events: "Découvrir les événements",

            how_label: "L'EXPÉRIENCE TICKETA",
            how_title: "Votre prochaine sortie en toute simplicité",
            how_description: "Tout ce qu'il vous faut pour découvrir et profiter des événements, au même endroit.",
            benefit_one_title: "Découvrir des événements",
            benefit_one_description: "Explorez les concerts, festivals et expériences près de chez vous.",
            benefit_two_title: "Choisir vos billets facilement",
            benefit_two_description: "Trouvez le bon événement et choisissez le billet qui vous convient.",
            benefit_three_title: "Profiter de l'expérience",
            benefit_three_description: "Préparez-vous à vivre de beaux moments avec vos proches et votre communauté.",

            organizer_label: "POUR LES ORGANISATEURS",
            organizer_title: "Faites de votre événement un succès.",
            organizer_description: "Touchez plus de personnes et donnez plus de visibilité à votre événement avec Ticketa.",
            organizer_button: "Commencer à vendre des billets"
        }
    };

    const languageSelector = document.getElementById("languageSelector");

    function applyLanguage(language) {
        const dictionary = translations[language] || translations.en;

        document.documentElement.lang = language;

        document.querySelectorAll("[data-i18n]").forEach(element => {
            const key = element.dataset.i18n;

            if (Object.prototype.hasOwnProperty.call(dictionary, key)) {
                element.innerHTML = dictionary[key];
            }
        });

        document.querySelectorAll("[data-i18n-placeholder]").forEach(element => {
            const key = element.dataset.i18nPlaceholder;

            if (Object.prototype.hasOwnProperty.call(dictionary, key)) {
                element.placeholder = dictionary[key];
            }
        });

        document.querySelectorAll(".brand-name, .footer-brand-name").forEach(element => {
            element.textContent = "TICKETA";
        });
    }

    if (languageSelector) {
        languageSelector.addEventListener("change", () => {
            applyLanguage(languageSelector.value);
        });

        applyLanguage(languageSelector.value || "en");
    }

    /* TRIM HOMEPAGE SEARCH INPUT */

    const searchForm = document.querySelector(".home-search-form");

    if (searchForm) {
        searchForm.addEventListener("submit", () => {
            const searchInput = searchForm.querySelector('input[name="q"]');

            if (searchInput) {
                searchInput.value = searchInput.value.trim();
            }
        });
    }
});
