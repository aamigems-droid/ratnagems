document.addEventListener('DOMContentLoaded', function () {

    /**
     * Initializes the sticky header functionality.
     */
    function initializeStickyHeader() {
        const header = document.querySelector('#masthead');
        if (!header) return;

        const scrollObserver = new IntersectionObserver(
            ([e]) => e.target.classList.toggle('is-sticky', e.intersectionRatio < 1),
            { threshold: [1] }
        );
        scrollObserver.observe(header);
    }

    /**
     * Initializes the Mega Menu functionality for desktop.
     */
    function initializeMegaMenu() {
        if (window.innerWidth < 992) return;
        const menuItems = document.querySelectorAll('.menu-item-has-children.has-mega-menu');
        menuItems.forEach(item => {
            const megaMenu = item.querySelector('.mega-menu');
            if (megaMenu) {
                item.addEventListener('mouseenter', () => {
                    megaMenu.style.display = 'block';
                });
                item.addEventListener('mouseleave', () => {
                    megaMenu.style.display = 'none';
                });
            }
        });
    }

    /**
     * Initializes the main homepage slider.
     */
    function initializeFinalSlider() {
        const slider = document.querySelector('.final-slider');
        if (!slider) return;

        const track = slider.querySelector('.final-slider__track');
        const prevButton = slider.querySelector('.final-slider__arrow--prev');
        const nextButton = slider.querySelector('.final-slider__arrow--next');
        const dotsContainer = slider.querySelector('.final-slider__dots');
        if (!track || !prevButton || !nextButton || !dotsContainer) return;

        let slides = Array.from(track.children);
        if (slides.length <= 1) {
            prevButton.style.display = 'none';
            nextButton.style.display = 'none';
            dotsContainer.style.display = 'none';
            return;
        }

        const originalSlideCount = slides.length;
        let dots = [];
        let isTransitioning = false;

        for (let i = 0; i < originalSlideCount; i++) {
            const dot = document.createElement('button');
            dot.classList.add('final-slider__dot');
            dot.setAttribute('aria-label', `Go to slide ${i + 1}`);
            dotsContainer.appendChild(dot);
            dots.push(dot);
        }

        const firstClone = slides[0].cloneNode(true);
        const lastClone = slides[slides.length - 1].cloneNode(true);
        track.appendChild(firstClone);
        track.prepend(lastClone);
        slides = Array.from(track.children);

        const slideCount = slides.length;
        let currentIndex = 1;
        let slideWidth = track.clientWidth;
        let isDragging = false;
        let startPos = 0;
        let currentTranslate = -slideWidth;
        let prevTranslate = -slideWidth;
        let animationID;
        let autoplayInterval;

        track.style.transform = `translateX(${currentTranslate}px)`;

        function updateDots() {
            dots.forEach(dot => dot.classList.remove('active'));
            const realIndex = (currentIndex - 1 + originalSlideCount) % originalSlideCount;
            if (dots[realIndex]) dots[realIndex].classList.add('active');
        }

        function setPosition() {
            track.style.transform = `translateX(${currentTranslate}px)`;
        }

        function shiftSlide(direction) {
            if (isTransitioning) return;
            isTransitioning = true;
            track.style.transition = 'transform 0.5s ease-in-out';
            currentIndex += direction;
            currentTranslate = -currentIndex * slideWidth;
            setPosition();
        }

        function goToSlide(index) {
            if (isTransitioning) return;
            isTransitioning = true;
            track.style.transition = 'transform 0.5s ease-in-out';
            currentIndex = index + 1;
            currentTranslate = -currentIndex * slideWidth;
            setPosition();
        }

        function handleTransitionEnd() {
            if (currentIndex === 0) {
                track.style.transition = 'none';
                currentIndex = slideCount - 2;
                currentTranslate = -currentIndex * slideWidth;
                setPosition();
            }
            if (currentIndex === slideCount - 1) {
                track.style.transition = 'none';
                currentIndex = 1;
                currentTranslate = -currentIndex * slideWidth;
                setPosition();
            }
            updateDots();
            isTransitioning = false;
        }

        function startAutoplay() {
            stopAutoplay();
            autoplayInterval = setInterval(() => shiftSlide(1), 5000);
        }

        function stopAutoplay() {
            clearInterval(autoplayInterval);
        }

        function getPositionX(event) {
            return event.type.includes('mouse') ? event.pageX : event.touches[0].clientX;
        }

        function dragStart(event) {
            if (isTransitioning) return;
            isDragging = true;
            startPos = getPositionX(event);
            prevTranslate = currentTranslate;
            animationID = requestAnimationFrame(animation);
            track.style.transition = 'none';
            stopAutoplay();
        }

        function dragMove(event) {
            if (isDragging) {
                event.preventDefault();
                const currentPosition = getPositionX(event);
                currentTranslate = prevTranslate + (currentPosition - startPos);
            }
        }

        function dragEnd() {
            if (!isDragging) return;
            isDragging = false;
            cancelAnimationFrame(animationID);
            const movedBy = currentTranslate - prevTranslate;
            if (movedBy < -100) {
                shiftSlide(1);
            } else if (movedBy > 100) {
                shiftSlide(-1);
            } else {
                track.style.transition = 'transform 0.5s ease-in-out';
                currentTranslate = -currentIndex * slideWidth;
                setPosition();
            }
            startAutoplay();
        }

        function animation() {
            setPosition();
            if (isDragging) requestAnimationFrame(animation);
        }

        function handleResize() {
            slideWidth = track.clientWidth;
            track.style.transition = 'none';
            currentTranslate = -currentIndex * slideWidth;
            setPosition();
        }

        function handleVisibilityChange() {
            if (document.hidden) {
                stopAutoplay();
            } else {
                startAutoplay();
            }
        }

        nextButton.addEventListener('click', () => shiftSlide(1));
        prevButton.addEventListener('click', () => shiftSlide(-1));
        dots.forEach((dot, index) => dot.addEventListener('click', () => goToSlide(index)));
        track.addEventListener('transitionend', handleTransitionEnd);
        slider.addEventListener('mouseenter', stopAutoplay);
        slider.addEventListener('mouseleave', startAutoplay);
        window.addEventListener('resize', handleResize);
        document.addEventListener('visibilitychange', handleVisibilityChange);

        // Mouse + touch events
        track.addEventListener('mousedown', dragStart);
        track.addEventListener('touchstart', dragStart); // removed passive:true here
        track.addEventListener('mouseup', dragEnd);
        track.addEventListener('touchend', dragEnd);
        track.addEventListener('mouseleave', dragEnd);
        track.addEventListener('mousemove', dragMove);
        track.addEventListener('touchmove', dragMove, { passive: false });

        updateDots();
        startAutoplay();
    }

    /**
     * Initializes the Rudraksha toggle.
     */
    function initializeRudrakshaToggle() {
        const toggleButton = document.querySelector('#rudraksha-toggle-btn');
        const moreRudrakshaGrid = document.querySelector('#rudraksha-more');
        if (toggleButton && moreRudrakshaGrid) {
            toggleButton.addEventListener('click', () => {
                const isHidden = moreRudrakshaGrid.style.display === 'none' || moreRudrakshaGrid.style.display === '';
                const labelSpan = toggleButton.querySelector('span');
                if (isHidden) {
                    moreRudrakshaGrid.style.display = 'grid';
                    if (labelSpan) labelSpan.textContent = 'Show Less Rudraksha';
                    toggleButton.classList.add('open');
                } else {
                    moreRudrakshaGrid.style.display = 'none';
                    if (labelSpan) labelSpan.textContent = 'Show More Rudraksha';
                    toggleButton.classList.remove('open');
                }
            });
        }
    }

    /**
     * Initializes subscription form.
     */
    function initializeSubscriptionForm() {
        const subscribeForm = document.querySelector('#sg-subscription-form') || document.querySelector('.subscription-form');
        const messageContainer = document.querySelector('#sg-form-message-container');
        const formWrapper = document.querySelector('.subscription-form-wrapper');
        const messageTarget = messageContainer || formWrapper || (subscribeForm ? subscribeForm.parentNode : null);
        if (!subscribeForm || !messageTarget || typeof sg_ajax_obj === 'undefined' || !sg_ajax_obj.nonce) return;

        function displayFormMessage(message, type) {
            const existingMessage = messageTarget.querySelector('.form-message');
            if (existingMessage) {
                existingMessage.remove();
            }
            const messageElement = document.createElement('div');
            messageElement.className = `form-message ${type}`;
            messageElement.textContent = message;
            messageTarget.appendChild(messageElement);
            setTimeout(() => {
                if (messageElement) {
                    messageElement.style.opacity = '0';
                    setTimeout(() => messageElement.remove(), 500);
                }
            }, 4000);
        }

        subscribeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const form = e.target;
            const submitButton = form.querySelector('button');
            const originalButtonText = submitButton.textContent;
            const formData = new FormData(form);
            formData.append('action', 'add_new_subscriber');
            formData.append('security', sg_ajax_obj.nonce);

            submitButton.disabled = true;
            submitButton.textContent = 'Submitting...';

            fetch(sg_ajax_obj.ajax_url, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        displayFormMessage('Success! You are now subscribed.', 'success');
                        form.reset();
                    } else {
                        displayFormMessage(response.data || 'An error occurred. Please try again.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    displayFormMessage('A network error occurred. Please check your connection.', 'error');
                })
                .finally(() => {
                    setTimeout(() => {
                        submitButton.disabled = false;
                        submitButton.textContent = originalButtonText;
                    }, 1000);
                });
        });
    }

    /**
     * Initializes contact form.
     */
    function initializeContactForm() {
        const contactForm = document.querySelector('#ratnagems-contact-form') || document.querySelector('#sg-contact-form');
        if (!contactForm) return;
        const statusMessage = contactForm.querySelector('#form-status-message') || document.querySelector('#sg-form-status-container');
        if (!statusMessage) return;
        if (typeof sg_ajax_obj === 'undefined' || !sg_ajax_obj.nonce) return;

        contactForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const form = e.target;
            const submitButton = form.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.textContent;
            const formData = new FormData(form);
            formData.append('action', 'send_contact_message');
            formData.append('security', sg_ajax_obj.nonce);

            submitButton.disabled = true;
            submitButton.textContent = 'Sending...';
            statusMessage.style.display = 'none';
            statusMessage.classList.remove('success', 'error');

            fetch(sg_ajax_obj.ajax_url, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        statusMessage.textContent = response.data;
                        statusMessage.classList.add('success');
                        form.reset();
                    } else {
                        statusMessage.textContent = response.data;
                        statusMessage.classList.add('error');
                    }
                    statusMessage.style.display = 'block';
                    setTimeout(() => {
                        submitButton.disabled = false;
                        submitButton.textContent = originalButtonText;
                    }, 3000);
                })
                .catch(error => {
                    console.error('Error:', error);
                    statusMessage.textContent = 'An unexpected error occurred. Please try again.';
                    statusMessage.classList.add('error');
                    statusMessage.style.display = 'block';
                    setTimeout(() => {
                        submitButton.disabled = false;
                        submitButton.textContent = originalButtonText;
                    }, 3000);
                });
        });
    }

    /**
     * Initializes the contact page map facade.
     */
    function initializeContactMap() {
        const loadMapBtn = document.querySelector('#load-map-btn');
        const mapFacade = document.querySelector('#map-facade');
        const mapContainer = document.querySelector('#map-container');
        if (!loadMapBtn || !mapFacade || !mapContainer) return;

        loadMapBtn.addEventListener('click', () => {
            const iframe = document.createElement('iframe');
            iframe.src = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3672.16836757012!2d72.58740217531371!3d23.017589479177293!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x395e85b54095cc55%3A0x74f34a72e7dbd13e!2sSarfaraz%20Gems%20-best%20gemstone%20shop%20in%20Ahmedabad!5e0!3m2!1sen!2sin!4v1755545554838!5m2!1sen!2sin';
            iframe.width = '100%';
            iframe.height = '100%';
            iframe.style.border = '0';
            iframe.allowFullscreen = true;
            iframe.loading = 'lazy';
            iframe.title = 'Interactive Map for Sarfaraz Gems Store Location';
            iframe.referrerPolicy = 'no-referrer-when-downgrade';

            mapContainer.appendChild(iframe);
            mapFacade.style.display = 'none';
            mapContainer.style.display = 'block';
        }, { once: true });
    }

    // Initialize all features
    initializeFinalSlider();
    initializeRudrakshaToggle();
    initializeSubscriptionForm();
    initializeContactForm();
    initializeContactMap();
    initializeStickyHeader();
    initializeMegaMenu();

});
