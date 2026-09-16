(() => {
    document.documentElement.classList.add('has-js');

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const navbar = document.getElementById('navbar');

    if (navbar) {
        const updateNavbar = () => {
            navbar.classList.toggle('scrolled', window.scrollY > 20);
        };

        updateNavbar();
        window.addEventListener('scroll', updateNavbar, { passive: true });
    }

    const revealables = document.querySelectorAll('[data-reveal]');

    if (reduceMotion || !('IntersectionObserver' in window)) {
        revealables.forEach((el) => el.classList.add('is-visible'));
    } else {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                const el = entry.target;
                const delay = el.getAttribute('data-reveal-delay');

                if (delay) {
                    el.style.setProperty('--reveal-delay', `${delay}ms`);
                }

                el.classList.add('is-visible');
                observer.unobserve(el);
            });
        }, { threshold: 0, rootMargin: '0px 0px -24px 0px' });

        revealables.forEach((el) => observer.observe(el));
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

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.getAttribute('data-confirm') || 'Are you sure?';

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

})();
