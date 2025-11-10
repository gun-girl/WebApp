-- Add competition_year to votes table
ALTER TABLE votes ADD COLUMN competition_year YEAR NOT NULL DEFAULT 2025;

-- Optional: add index for faster filtering
CREATE INDEX idx_votes_competition_year ON votes (competition_year);
