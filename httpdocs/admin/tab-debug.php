<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tab Debugging Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .tabs { display: flex; border-bottom: 1px solid #ccc; margin-bottom: 20px; }
        .tab { padding: 10px 20px; cursor: pointer; background: #f0f0f0; margin-right: 5px; border-radius: 5px 5px 0 0; }
        .tab.active { background: #fff; border: 1px solid #ccc; border-bottom: none; }
        .tab-content { display: none; padding: 20px; border: 1px solid #ccc; border-top: none; }
        .tab-content.active { display: block; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow: auto; }
    </style>
</head>
<body>
    <h1>Tab Navigation Debugging</h1>
    
    <div class="tabs">
        <div class="tab active" data-tab="tab1">Tab 1</div>
        <div class="tab" data-tab="tab2">Tab 2</div>
        <div class="tab" data-tab="tab3">Tab 3</div>
    </div>
    
    <div class="tab-contents">
        <div id="tab1" class="tab-content active">
            <h2>Tab 1 Content</h2>
            <p>This is the content for tab 1.</p>
        </div>
        <div id="tab2" class="tab-content">
            <h2>Tab 2 Content</h2>
            <p>This is the content for tab 2.</p>
        </div>
        <div id="tab3" class="tab-content">
            <h2>Tab 3 Content</h2>
            <p>This is the content for tab 3.</p>
        </div>
    </div>
    
    <div class="debug">
        <h2>Debug Information:</h2>
        <pre id="debug-output"></pre>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const debugOutput = document.getElementById('debug-output');
            let log = '';
            
            // Log function that outputs to our debug area
            function debugLog(message) {
                log += message + '\n';
                debugOutput.textContent = log;
                console.log(message);
            }
            
            // Get all elements
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            debugLog('Found ' + tabs.length + ' tabs');
            tabs.forEach(tab => {
                debugLog('Tab: ' + tab.textContent.trim() + ' with data-tab=' + tab.getAttribute('data-tab'));
            });
            
            debugLog('Found ' + tabContents.length + ' content divs');
            tabContents.forEach(content => {
                debugLog('Content div with id=' + content.id);
            });
            
            // Add click events
            tabs.forEach(tab => {
                tab.addEventListener('click', function(e) {
                    debugLog('Clicked: ' + this.textContent.trim());
                    
                    // Remove active class from all tabs
                    tabs.forEach(t => {
                        t.classList.remove('active');
                        debugLog('Removed active class from ' + t.textContent.trim());
                    });
                    
                    // Add active class to clicked tab
                    this.classList.add('active');
                    debugLog('Added active class to ' + this.textContent.trim());
                    
                    // Hide all tab contents
                    tabContents.forEach(content => {
                        content.classList.remove('active');
                        content.style.display = 'none';
                        debugLog('Hide content: ' + content.id);
                    });
                    
                    // Show the selected tab content
                    const tabId = this.getAttribute('data-tab');
                    debugLog('Looking for tab content with id: ' + tabId);
                    
                    const selectedContent = document.getElementById(tabId);
                    if (selectedContent) {
                        selectedContent.classList.add('active');
                        selectedContent.style.display = 'block';
                        debugLog('Showing content for id: ' + tabId);
                    } else {
                        debugLog('ERROR: Could not find content with id: ' + tabId);
                    }
                });
            });
            
            debugLog('Setup complete');
        });
    </script>
</body>
</html>
