-- One-time migration to add roles to users
ALTER TABLE `users`
  ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'user' AFTER `password_hash`;

-- Optionally promote an initial admin (uncomment and set the desired user id or email-based update)
-- UPDATE `users` SET `role`='admin' WHERE `email`='admin@example.com';
