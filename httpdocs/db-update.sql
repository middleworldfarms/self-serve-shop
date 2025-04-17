-- Add columns to orders table for payment tracking
ALTER TABLE orders ADD COLUMN payment_method VARCHAR(20) DEFAULT 'manual';
ALTER TABLE orders ADD COLUMN transaction_id VARCHAR(100) DEFAULT NULL;
ALTER TABLE orders ADD COLUMN payment_status VARCHAR(20) DEFAULT 'pending';

-- Add new settings for payment methods
INSERT INTO self_serve_settings (setting_name, setting_value) VALUES
('enable_square', '0'),
('square_app_id', ''),
('square_access_token', ''),
('square_location_id', ''),
('enable_apple_google', '0'),
('apple_merchant_id', ''),
('google_merchant_id', ''),
('stripe_webhook_secret', '');
