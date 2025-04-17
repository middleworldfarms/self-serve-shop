document.addEventListener('DOMContentLoaded', function() {
    // Simple tab functionality
    function initTabs() {
        console.log("Initializing tabs...");
        var tabLinks = document.querySelectorAll('.settings-tab');
        
        if (tabLinks.length === 0) {
            console.log("No tab links found");
            return; // Exit if no tabs found
        }
        
        // Add click handlers to all tabs
        tabLinks.forEach(function(link) {
            console.log("Adding handler to tab:", link.textContent);
            
            link.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Extract tab name from href
                var href = this.getAttribute('href');
                var tabName = href.split('tab=')[1];
                
                if (!tabName) {
                    console.error("Could not determine tab name from:", href);
                    return;
                }
                
                console.log("Switching to tab:", tabName);
                
                // Update URL without page reload
                history.pushState(null, '', '?tab=' + tabName);
                
                // Remove active class from all tabs and content
                tabLinks.forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                    content.style.display = 'none';
                });
                
                // Add active class to clicked tab
                this.classList.add('active');
                
                // Show corresponding content
                var contentId = tabName + '-tab';
                var contentElement = document.getElementById(contentId);
                
                if (contentElement) {
                    contentElement.classList.add('active');
                    contentElement.style.display = 'block';
                    console.log("Activated content:", contentId);
                } else {
                    console.error("Could not find tab content:", contentId);
                }
            });
        });
        
        // Make sure initial active tab is shown
        var activeTab = document.querySelector('.tab-content.active');
        if (activeTab) {
            activeTab.style.display = 'block';
        }
    }
    
    // Run initialization
    initTabs();
});