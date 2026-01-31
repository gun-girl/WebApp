<?php
// Update policy box titles in the database
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helper.php';

// Update titles
set_setting('policy_box_title_en', 'The Golden Couch Manifesto');
set_setting('policy_box_title_it', 'Il Manifesto del Divano d\'oro');

echo "Policy box titles updated successfully!\n";
echo "English: The Golden Couch Manifesto\n";
echo "Italian: Il Manifesto del Divano d'oro\n";
