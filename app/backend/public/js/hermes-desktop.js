(() => {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // --- Scroll-aware navbar: transparent over hero, frosted on scroll ---
    const navbar = document.getElementById('navbar');

    if (navbar) {
        const updateNavbar = () => {
            navbar.classList.toggle('scrolled', window.scrollY > 20);
        };

        updateNavbar();
        window.addEventListener('scroll', updateNavbar, { passive: true });
    }

    // --- Scroll-reveal: fade + rise on entry, with optional stagger ---
    const revealables = document.querySelectorAll('[data-reveal], .da-feature');

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

    // --- Terminal-style text scramble on primary CTA(s) ---
    if (!reduceMotion) {
        const chars = ' !#$%&/\\-_=+|<>~:*';

        document.querySelectorAll('[data-scramble-target]').forEach((el) => {
            const target = el.getAttribute('data-scramble-target') || el.textContent.trim();
            let frame = 0;
            const totalFrames = target.length + 18;

            const interval = window.setInterval(() => {
                const locked = Math.max(0, frame - 18);

                el.textContent = target
                    .split('')
                    .map((letter, index) => {
                        if (letter === ' ') {
                            return ' ';
                        }

                        if (index < locked || frame >= totalFrames) {
                            return letter;
                        }

                        return chars[Math.floor(Math.random() * chars.length)];
                    })
                    .join('');

                frame += 1;

                if (frame > totalFrames) {
                    el.textContent = target;
                    window.clearInterval(interval);
                }
            }, 42);
        });
    }
})();
