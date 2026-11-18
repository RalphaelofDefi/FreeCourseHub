-- Adds the two strict $0.00 course sources introduced on 2026-11-13.
-- Safe to run more than once on existing installations.
INSERT INTO course_sources (name, base_url, adapter, status) VALUES
('Udemy Free Courses', 'https://www.udemy.com/courses/free/', 'UdemyFreeAdapter', 'enabled'),
('Real.discount', 'https://www.real.discount/courses', 'RealDiscountAdapter', 'enabled')
ON DUPLICATE KEY UPDATE base_url = VALUES(base_url), adapter = VALUES(adapter);
