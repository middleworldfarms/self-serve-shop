// Fix for 262145 pound symbol issue
document.addEventListener('DOMContentLoaded', function() {
    // Function to recursively check and fix text nodes
    function fixPoundSymbols(node) {
        // Check text nodes
        if (node.nodeType === 3 && node.nodeValue && node.nodeValue.includes('262145')) {
            node.nodeValue = node.nodeValue.replace(/262145/g, '£');
        }
        
        // Recursively check child nodes
        if (node.childNodes) {
            for (var i = 0; i < node.childNodes.length; i++) {
                fixPoundSymbols(node.childNodes[i]);
            }
        }
    }
    
    // Apply fix to entire document
    fixPoundSymbols(document);
    
    // Run again after a short delay to catch any dynamically added content
    setTimeout(function() {
        fixPoundSymbols(document);
    }, 500);
});
