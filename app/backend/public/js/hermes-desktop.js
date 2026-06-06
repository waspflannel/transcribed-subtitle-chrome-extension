(() => {
    const navbar = document.getElementById('navbar');

    if (navbar) {
        const updateNavbar = () => {
            navbar.classList.toggle('scrolled', window.scrollY > 20);
        };

        updateNavbar();
        window.addEventListener('scroll', updateNavbar, { passive: true });
    }

    const scrambleButton = document.querySelector('[data-scramble-target]');

    if (scrambleButton) {
        const target = scrambleButton.getAttribute('data-scramble-target') || 'Download Now';
        const chars = ' !#$%&/\\-_=+|<>~:*';
        let frame = 0;
        const totalFrames = target.length + 18;

        const interval = window.setInterval(() => {
            const locked = Math.max(0, frame - 18);
            const next = target
                .split('')
                .map((letter, index) => {
                    if (index < locked || frame >= totalFrames) {
                        return letter;
                    }

                    return chars[Math.floor(Math.random() * chars.length)];
                })
                .join('');

            scrambleButton.textContent = next;
            frame += 1;

            if (frame > totalFrames) {
                scrambleButton.textContent = target;
                window.clearInterval(interval);
            }
        }, 40);
    }

    const featureRows = document.querySelectorAll('.hermes-feature-row, .da-feature');

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });

        featureRows.forEach((row) => observer.observe(row));
    } else {
        featureRows.forEach((row) => row.classList.add('is-visible'));
    }
})();
