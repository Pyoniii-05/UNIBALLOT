document.addEventListener("DOMContentLoaded", function () {
    const drawer = document.getElementById('drawer');

    // Safety check
    if (!drawer) return;

    // 1. Check if user navigated here (clicked a link)
    const navigationEntry = performance.getEntriesByType("navigation")[0];

    if (navigationEntry && navigationEntry.type === 'navigate') {
        
        // --- STEP 1: OPEN INSTANTLY (No Animation) ---
        
        // Temporarily turn off the CSS transition so it doesn't slide out
        drawer.style.transition = 'none';
        
        // Add the open class immediately
        drawer.classList.add('open');
        
        // Force the browser to apply the change instantly (Reflow)
        void drawer.offsetWidth;

        // Turn the CSS transition back on (so it can animate when closing)
        drawer.style.transition = ''; 

        // --- STEP 2: CLOSE SMOOTHLY AFTER 1 SECOND ---
        
        setTimeout(() => {
            drawer.classList.remove('open');
        }, 100); // 1000ms = 1 second
    }
});