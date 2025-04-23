<?php
/**
 * Functions for retrieving products from WooCommerce
 */

/**
 * Get all available products for the self-serve shop
 */
function get_available_products() {
    try {
        $prefix = TABLE_PREFIX; // Use the table prefix from config.php
        $posts_table = $prefix . 'posts';
        $postmeta_table = $prefix . 'postmeta';
        
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
            DB_USER, 
            DB_PASS
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Query products with explicit price selection
        $stmt = $db->prepare("
            SELECT 
                p.ID, 
                p.post_title,
                price_meta.meta_value as price,
                thumbnail.meta_value as thumbnail
            FROM `{$posts_table}` p
            LEFT JOIN `{$postmeta_table}` price_meta ON p.ID = price_meta.post_id AND price_meta.meta_key = '_regular_price'
            LEFT JOIN `{$postmeta_table}` pm_available ON p.ID = pm_available.post_id AND pm_available.meta_key = '_self_serve_available'
            LEFT JOIN `{$postmeta_table}` thumbnail ON p.ID = thumbnail.post_id AND thumbnail.meta_key = '_thumbnail_id'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND (pm_available.meta_value = 'yes' OR pm_available.meta_value IS NULL)
            AND p.post_parent = 0
            ORDER BY p.post_title ASC
        ");
        $stmt->execute();
        
        $products = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Get the actual image URL from thumbnail ID
            $image_url = get_image_url($row['thumbnail']);
            
            // Make sure price is a valid numeric value
            $price = is_numeric($row['price']) ? floatval($row['price']) : 0;
            
            $products[] = [
                'id' => $row['ID'],
                'name' => $row['post_title'],
                'price' => $price,
                'image' => $image_url ? $image_url : 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png'
            ];
        }
        
        return $products;
    } catch (PDOException $e) {
        // For development, show the error
        error_log("Database error in get_available_products: " . $e->getMessage());
        
        // Return sample products (for demonstration)
        return get_sample_products();
    }
}

/**
 * Get details for a specific product by ID
 */
function get_product_details($product_id) {
    // Create a new database connection inside the function
    try {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
            DB_USER, 
            DB_PASS
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // First try the standalone DB table
        $stmt = $db->prepare("SELECT * FROM sss_products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            // Format the product details
            return [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => floatval($product['price']),
                'image' => $product['image'] ? $product['image'] : process_image_url('https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png')
            ];
        }

        // If not found in standalone table, fallback to WooCommerce
        $prefix = TABLE_PREFIX;
        $posts_table = $prefix . 'posts';
        $postmeta_table = $prefix . 'postmeta';

        $stmt = $db->prepare("
            SELECT 
                p.ID, 
                p.post_title,
                price_meta.meta_value as price,
                thumbnail.meta_value as thumbnail
            FROM `{$posts_table}` p
            LEFT JOIN `{$postmeta_table}` price_meta ON p.ID = price_meta.post_id AND price_meta.meta_key = '_regular_price'
            LEFT JOIN `{$postmeta_table}` thumbnail ON p.ID = thumbnail.post_id AND thumbnail.meta_key = '_thumbnail_id'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND p.ID = :product_id
        ");
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            // Get the actual image URL from thumbnail ID
            $image_url = get_image_url($row['thumbnail']);
            
            // Make sure price is a valid numeric value
            $price = is_numeric($row['price']) ? floatval($row['price']) : 0;
            
            return [
                'id' => $row['ID'],
                'name' => $row['post_title'],
                'price' => $price,
                'image' => $image_url ? $image_url : process_image_url('https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png')
            ];
        }
        
        // If product not found, return a default
        return [
            'id' => $product_id,
            'name' => 'Product Not Found',
            'price' => 0,
            'image' => process_image_url('https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png')
        ];
    } catch (PDOException $e) {
        error_log("Database error in get_product_details: " . $e->getMessage());
        
        // Return a default product
        return [
            'id' => $product_id,
            'name' => 'Product Not Found',
            'price' => 0,
            'image' => process_image_url('https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png')
        ];
    }
}

/**
 * Get image URL from attachment ID
 */
function get_image_url($attachment_id) {
    if (!$attachment_id) {
        return null;
    }
    
    try {
        $prefix = TABLE_PREFIX;
        $posts_table = $prefix . 'posts';
        
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
            DB_USER, 
            DB_PASS
        );
        
        $stmt = $db->prepare("
            SELECT guid
            FROM `{$posts_table}`
            WHERE ID = :attachment_id
        ");
        $stmt->bindParam(':attachment_id', $attachment_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            return $row['guid'];
        }
        
        return null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Sample products for demonstration (fallback)
 */
function get_sample_products() {
    return [
        [
            'id' => 1,
            'name' => 'Organic Carrots',
            'price' => 2.99,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png'
        ],
        [
            'id' => 2,
            'name' => 'Fresh Spinach',
            'price' => 3.49,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png'
        ],
        [
            'id' => 3,
            'name' => 'Local Potatoes',
            'price' => 4.99,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png'
        ],
        [
            'id' => 4,
            'name' => 'Organic Tomatoes',
            'price' => 3.99,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png'
        ],
        [
            'id' => 5,
            'name' => 'Fresh Cucumbers',
            'price' => 1.99,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png'
        ],
        [
            'id' => 6,
            'name' => 'Bell Peppers',
            'price' => 2.49,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png'
        ]
    ];
}

/**
 * Get all WooCommerce products for the admin interface
 */
function get_all_woocommerce_products() {
    try {
        $prefix = TABLE_PREFIX;
        $posts_table = $prefix . 'posts';
        $postmeta_table = $prefix . 'postmeta';
        
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
            DB_USER, 
            DB_PASS
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Query all published products
        $stmt = $db->prepare("
            SELECT 
                p.ID, 
                p.post_title,
                price_meta.meta_value as price,
                thumbnail.meta_value as thumbnail,
                IFNULL((SELECT 1 FROM `{$postmeta_table}` WHERE post_id = p.ID AND meta_key = '_self_serve_available' AND meta_value = 'yes'), 0) as available
            FROM `{$posts_table}` p
            LEFT JOIN `{$postmeta_table}` price_meta ON p.ID = price_meta.post_id AND price_meta.meta_key = '_regular_price'
            LEFT JOIN `{$postmeta_table}` thumbnail ON p.ID = thumbnail.post_id AND thumbnail.meta_key = '_thumbnail_id'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND p.post_parent = 0
            ORDER BY p.post_title ASC
        ");
        $stmt->execute();
        
        $products = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Get the actual image URL from thumbnail ID
            $image_url = get_image_url($row['thumbnail']);
            
            // Make sure price is a valid numeric value
            $price = is_numeric($row['price']) ? floatval($row['price']) : 0;
            
            $products[] = [
                'id' => $row['ID'],
                'name' => $row['post_title'],
                'price' => $price,
                'image' => $image_url ? $image_url : 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png',
                'available' => (bool) $row['available']
            ];
        }
        
        return $products;
    } catch (PDOException $e) {
        error_log("Database error in get_all_woocommerce_products: " . $e->getMessage());
        
        // Return sample products (for demonstration)
        return get_sample_admin_products();
    }
}

/**
 * Sample products for admin interface (fallback)
 */
function get_sample_admin_products() {
    return [
        [
            'id' => 1,
            'name' => 'Organic Carrots',
            'price' => 2.99,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png',
            'available' => true
        ],
        [
            'id' => 2,
            'name' => 'Fresh Spinach',
            'price' => 3.49,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png',
            'available' => true
        ],
        [
            'id' => 3,
            'name' => 'Local Potatoes',
            'price' => 4.99,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png',
            'available' => false
        ],
        [
            'id' => 4,
            'name' => 'Organic Tomatoes',
            'price' => 3.99,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png',
            'available' => true
        ],
        [
            'id' => 5,
            'name' => 'Fresh Cucumbers',
            'price' => 1.99,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png',
            'available' => false
        ],
        [
            'id' => 6,
            'name' => 'Bell Peppers',
            'price' => 2.49,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png',
            'available' => true
        ],
        [
            'id' => 7,
            'name' => 'Green Beans',
            'price' => 3.29,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png',
            'available' => false
        ],
        [
            'id' => 8,
            'name' => 'Butternut Squash',
            'price' => 3.79,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png',
            'available' => true
        ],
        [
            'id' => 9,
            'name' => 'Sweet Corn',
            'price' => 1.49,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png',
            'available' => false
        ],
        [
            'id' => 10,
            'name' => 'Red Onions',
            'price' => 1.29,
            'image' => 'https://middleworldfarms.org/wp-content/uploads/2024/12/cropped-cropped-Middle-World-Logo-Image-Green-PNG-FOR-SCREENS.png',
            'available' => true
        ]
    ];
}

?>