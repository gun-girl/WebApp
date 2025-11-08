-- Adds optional season and episode fields to vote_details
ALTER TABLE vote_details
  ADD COLUMN season_number SMALLINT NULL AFTER category,
  ADD COLUMN episode_number SMALLINT NULL AFTER season_number;
