(() => {
    const navbar = document.getElementById('navbar');

    if (navbar) {
        const updateNavbar = () => {
            navbar.classList.toggle('scrolled', window.scrollY > 20);
        };

        updateNavbar();
        window.addEventListener('scroll', updateNavbar, { passive: true });
    }

    const guideLinks = [...document.querySelectorAll('.guide-contents a[href^="#"]')];

    if (guideLinks.length > 0) {
        const sections = guideLinks.map((link) => document.getElementById(link.hash.slice(1)));
        const updateGuideLocation = () => {
            let current = 0;
            sections.forEach((section, index) => {
                if (section.getBoundingClientRect().top <= 160) current = index;
            });
            guideLinks.forEach((link, index) => {
                if (index === current) link.setAttribute('aria-current', 'location');
                else link.removeAttribute('aria-current');
            });
        };

        updateGuideLocation();
        window.addEventListener('scroll', updateGuideLocation, { passive: true });
    }
})();
