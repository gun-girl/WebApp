-- Create policy box settings in the settings table
-- The policy box will be displayed on the index page under the logo

-- Insert default policy title (multilingual support via settings key)
INSERT INTO settings (setting_key, setting_value) 
VALUES ('policy_box_title_en', 'The Golden Couch Manifesto')
ON DUPLICATE KEY UPDATE setting_value = 'The Golden Couch Manifesto';

INSERT INTO settings (setting_key, setting_value) 
VALUES ('policy_box_title_it', 'Il Manifesto del Divano d''oro')
ON DUPLICATE KEY UPDATE setting_value = 'Il Manifesto del Divano d''oro';

-- Insert default policy content (shared for all languages)
INSERT INTO settings (setting_key, setting_value) 
VALUES ('policy_box_content', '')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- Set visibility (1 = visible, 0 = hidden)
INSERT INTO settings (setting_key, setting_value) 
VALUES ('policy_box_visible', '1')
ON DUPLICATE KEY UPDATE setting_value = '1';
