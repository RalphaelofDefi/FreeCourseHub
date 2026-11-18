INSERT INTO categories (name, slug, description) VALUES
('Development', 'development', 'Programming, web development, mobile apps and software engineering.'),
('Business', 'business', 'Entrepreneurship, management, strategy and business growth.'),
('Design', 'design', 'Graphic design, UX, UI and creative skills.'),
('Marketing', 'marketing', 'Digital marketing, social media, SEO and branding.'),
('IT & Software', 'it-software', 'Networking, cloud, security and IT certifications.'),
('Personal Development', 'personal-development', 'Productivity, habits, communication and career growth.'),
('Photography', 'photography', 'Photography, video production and visual storytelling.'),
('Music', 'music', 'Music production, instruments and audio engineering.'),
('Health & Fitness', 'health-fitness', 'Wellness, fitness and nutrition.'),
('Finance & Accounting', 'finance-accounting', 'Accounting, investing and financial analysis.'),
('Office Productivity', 'office-productivity', 'Spreadsheets, documents and workplace tools.'),
('Teaching & Academics', 'teaching-academics', 'Teaching, language learning and academic subjects.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO course_sources (name, base_url, adapter, status) VALUES
('DiscUdemy', 'https://www.discudemy.com/', 'DiscUdemyAdapter', 'enabled'),
('CouponsCorpion', 'https://couponscorpion.com/', 'CouponScorpionAdapter', 'enabled'),
('Udemy Free Courses', 'https://www.udemy.com/courses/free/', 'UdemyFreeAdapter', 'enabled'),
('Real.discount', 'https://www.real.discount/courses', 'RealDiscountAdapter', 'enabled')
ON DUPLICATE KEY UPDATE base_url = VALUES(base_url), adapter = VALUES(adapter);

INSERT INTO system_settings (setting_key, setting_value) VALUES
('site_name', 'FreeCourse Hub'),
('site_description', 'Discover verified free Udemy courses curated from trusted sources every day.'),
('contact_email', 'support@example.com'),
('site_logo', ''),
('default_currency', 'NGN'),
('timezone', 'Africa/Lagos'),
('free_unlock_allowance', '5'),
('unlock_price', '200'),
('payments_enabled', '1'),
('free_access_enabled', '1'),
('paid_access_enabled', '1'),
('auto_collection_enabled', '1'),
('collection_interval_hours', '6'),
('verification_interval_hours', '12'),
('expiry_check_interval_hours', '6'),
('default_theme', 'light'),
('allow_user_theme_switch', '1'),
('collection_last_run', ''),
('verification_last_run', ''),
('expiry_last_run', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
