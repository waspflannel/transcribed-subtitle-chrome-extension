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

    const preview = document.querySelector('.product-demo');

    if (preview) {
        const tabs = [...preview.querySelectorAll('[role="tab"]')];
        const selectPreview = (tab) => {
            tabs.forEach((item) => {
                const selected = item === tab;
                item.setAttribute('aria-selected', String(selected));
                item.tabIndex = selected ? 0 : -1;
                document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
            });
        };

        tabs.forEach((tab, index) => {
            const panel = document.getElementById(tab.getAttribute('aria-controls'));
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tab.id);
            panel.tabIndex = 0;
            tab.addEventListener('click', () => selectPreview(tab));
            tab.addEventListener('keydown', (event) => {
                let next;
                if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = tabs.length - 1;
                if (next === undefined) return;
                event.preventDefault();
                selectPreview(tabs[next]);
                tabs[next].focus();
            });
        });

        preview.querySelector('[role="tablist"]').hidden = false;
        selectPreview(tabs[0]);

        preview.querySelectorAll('[data-preview-toggle]').forEach((button) => {
            const target = document.getElementById(button.getAttribute('aria-controls'));
            const setExpanded = (expanded) => {
                target.hidden = !expanded;
                button.setAttribute('aria-expanded', String(expanded));
                if (button.dataset.openLabel) {
                    button.textContent = expanded ? button.dataset.closeLabel : button.dataset.openLabel;
                }
            };
            button.hidden = false;
            setExpanded(target.id === 'preview-subtitles' || target.id === 'preview-translation');
            button.addEventListener('click', () => setExpanded(target.hidden));
        });

        document.querySelectorAll('[data-preview-link]').forEach((link) => {
            link.addEventListener('click', () => {
                const tab = document.getElementById(`preview-tab-${link.dataset.previewLink}`);
                selectPreview(tab);
                requestAnimationFrame(() => tab.focus({ preventScroll: true }));
            });
        });
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
