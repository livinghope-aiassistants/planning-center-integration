/**
 * Planning Center Carousel Functionality
 * Handles carousel navigation for both Events and Groups
 * Version: 1.0.4
 */

(function() {
    'use strict';
    
    /**
     * Initialize carousel functionality
     */
    function initCarousel(carouselWrapper) {
        const container = carouselWrapper.querySelector('.pc-events-container, .pc-groups-container');
        const track = carouselWrapper.querySelector('.pc-carousel-track, .pc-groups-carousel-track');
        const prevBtn = carouselWrapper.querySelector('.pc-carousel-prev');
        const nextBtn = carouselWrapper.querySelector('.pc-carousel-next');
        const indicators = carouselWrapper.querySelector('.pc-carousel-indicators');
        
        if (!container || !track || !prevBtn || !nextBtn) {
            return;
        }
        
        // Determine cards per view from container class
        let cardsPerView = 1;
        if (container.classList.contains('pc-carousel-1') || container.classList.contains('pc-carousel-1')) {
            cardsPerView = 1;
        } else if (container.classList.contains('pc-carousel-2')) {
            cardsPerView = 2;
        } else if (container.classList.contains('pc-carousel-3')) {
            cardsPerView = 3;
        }
        
        const cards = track.querySelectorAll('.pc-event-card, .pc-group-card');
        const totalCards = cards.length;
        let currentIndex = 0;
        
        // Calculate total pages
        const totalPages = Math.ceil(totalCards / cardsPerView);
        
        // Create indicators if container exists
        if (indicators && totalPages > 1) {
            for (let i = 0; i < totalPages; i++) {
                const dot = document.createElement('div');
                dot.className = 'pc-carousel-indicator';
                if (i === 0) dot.classList.add('active');
                dot.addEventListener('click', () => goToPage(i));
                indicators.appendChild(dot);
            }
        }
        
        /**
         * Update carousel position
         */
        function updateCarousel() {
            const cardWidth = cards[0].offsetWidth;
            const gap = parseInt(getComputedStyle(track).gap) || 20; // Updated default from 25
            const offset = -(currentIndex * cardsPerView * (cardWidth + gap));
            
            track.style.transform = `translateX(${offset}px)`;
            
            // Update button states
            prevBtn.classList.toggle('disabled', currentIndex === 0);
            nextBtn.classList.toggle('disabled', currentIndex >= totalPages - 1);
            
            // Update indicators
            if (indicators) {
                const dots = indicators.querySelectorAll('.pc-carousel-indicator');
                dots.forEach((dot, index) => {
                    dot.classList.toggle('active', index === currentIndex);
                });
            }
        }
        
        /**
         * Go to specific page
         */
        function goToPage(pageIndex) {
            currentIndex = Math.max(0, Math.min(pageIndex, totalPages - 1));
            updateCarousel();
        }
        
        /**
         * Navigate to previous page
         */
        function goToPrev() {
            if (currentIndex > 0) {
                currentIndex--;
                updateCarousel();
            }
        }
        
        /**
         * Navigate to next page
         */
        function goToNext() {
            if (currentIndex < totalPages - 1) {
                currentIndex++;
                updateCarousel();
            }
        }
        
        // Event listeners
        prevBtn.addEventListener('click', goToPrev);
        nextBtn.addEventListener('click', goToNext);
        
        // Keyboard navigation
        carouselWrapper.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') {
                goToPrev();
            } else if (e.key === 'ArrowRight') {
                goToNext();
            }
        });
        
        // Touch/swipe support
        let touchStartX = 0;
        let touchEndX = 0;
        
        track.addEventListener('touchstart', (e) => {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });
        
        track.addEventListener('touchend', (e) => {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, { passive: true });
        
        function handleSwipe() {
            const swipeThreshold = 50;
            const diff = touchStartX - touchEndX;
            
            if (Math.abs(diff) > swipeThreshold) {
                if (diff > 0) {
                    // Swiped left - go to next
                    goToNext();
                } else {
                    // Swiped right - go to previous
                    goToPrev();
                }
            }
        }
        
        // Handle window resize
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                // Recalculate on window resize
                updateCarousel();
            }, 250);
        });
        
        // Initialize
        updateCarousel();
    }
    
    /**
     * Initialize all carousels on page
     */
    function initAllCarousels() {
        // Initialize events carousels
        const eventCarousels = document.querySelectorAll('.pc-events-carousel-wrapper');
        eventCarousels.forEach(carousel => initCarousel(carousel));
        
        // Initialize groups carousels
        const groupCarousels = document.querySelectorAll('.pc-groups-carousel-wrapper');
        groupCarousels.forEach(carousel => initCarousel(carousel));
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllCarousels);
    } else {
        initAllCarousels();
    }
    
})();
