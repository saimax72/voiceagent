-- Read-aloud behaviour per agent: voice (spoken when the visitor used voice), always, never
ALTER TABLE agents ADD COLUMN speak_replies VARCHAR(10) NOT NULL DEFAULT 'voice' AFTER auto_speak;
UPDATE agents SET speak_replies = 'never' WHERE auto_speak = 0;
