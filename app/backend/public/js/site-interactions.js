(() => {
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
                    el.style.transitionDelay = `${delay}ms`;
                }

                el.classList.add('is-visible');
                observer.unobserve(el);
            });
        }, { threshold: 0.14, rootMargin: '0px 0px -8% 0px' });

        revealables.forEach((el) => observer.observe(el));
    }

})();
