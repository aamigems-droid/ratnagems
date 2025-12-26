/**
 * Ratna Gems - FAQ Accordion
 * Global script for FAQ functionality on product pages and FAQ page
 * 
 * @package Ratna Gems
 * @version 1.0.0
 */
(function () {
    'use strict';

    function initFaqAccordion() {
        var containers = document.querySelectorAll('.ratna-faq-container');
        
        if (!containers.length) return;

        containers.forEach(function (container) {
            // Skip if already initialized
            if (container.dataset.faqInit === 'true') return;
            container.dataset.faqInit = 'true';

            container.addEventListener('click', function (e) {
                var btn = e.target.closest('.ratna-faq-question');
                if (!btn) return;

                var item = btn.closest('.ratna-faq-item');
                var answer = item.querySelector('.ratna-faq-answer');
                var icon = btn.querySelector('.ratna-faq-icon');
                var isOpen = item.classList.contains('active');

                // Close all other FAQs in this container
                container.querySelectorAll('.ratna-faq-item.active').forEach(function (openItem) {
                    if (openItem !== item) {
                        closeItem(openItem);
                    }
                });

                // Toggle current item
                if (isOpen) {
                    closeItem(item);
                } else {
                    openItem(item);
                }
            });
        });

        // Handle defined aria-controls (for FAQ page with IDs)
        function getAnswer(btn) {
            var controlsId = btn.getAttribute('aria-controls');
            if (controlsId) {
                return document.getElementById(controlsId);
            }
            return btn.closest('.ratna-faq-item').querySelector('.ratna-faq-answer');
        }

        function closeItem(item) {
            if (!item) return;
            var btn = item.querySelector('.ratna-faq-question');
            var answer = getAnswer(btn);
            var icon = btn.querySelector('.ratna-faq-icon');

            item.classList.remove('active');
            btn.setAttribute('aria-expanded', 'false');
            if (answer) answer.style.maxHeight = null;
            if (icon) icon.textContent = '+';
        }

        function openItem(item) {
            if (!item) return;
            var btn = item.querySelector('.ratna-faq-question');
            var answer = getAnswer(btn);
            var icon = btn.querySelector('.ratna-faq-icon');

            item.classList.add('active');
            btn.setAttribute('aria-expanded', 'true');
            if (answer) answer.style.maxHeight = answer.scrollHeight + 'px';
            if (icon) icon.textContent = '−';
        }

        // Deep link support for FAQ page
        if (window.location.hash && window.location.hash.startsWith('#faq-q')) {
            var targetBtn = document.getElementById(window.location.hash.substring(1));
            if (targetBtn) {
                var targetItem = targetBtn.closest('.ratna-faq-item');
                var targetSection = targetBtn.closest('.ratna-faq-section');
                
                // Show section if hidden
                if (targetSection && targetSection.hidden) {
                    targetSection.hidden = false;
                }
                
                // Open the target FAQ
                if (targetItem) {
                    setTimeout(function() {
                        openItem(targetItem);
                        targetBtn.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 100);
                }
            }
        }
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFaqAccordion);
    } else {
        initFaqAccordion();
    }

    // Expose for manual initialization if needed
    window.ratnaGemsInitFaqAccordion = initFaqAccordion;

})();