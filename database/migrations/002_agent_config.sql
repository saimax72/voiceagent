-- Agent configuration: tags, custom system prompt, timezone, languages, LLM choice, voice style, interruptibility

ALTER TABLE agents
  ADD COLUMN tags VARCHAR(255) DEFAULT NULL AFTER description,
  ADD COLUMN prompt_mode VARCHAR(10) NOT NULL DEFAULT 'guided' AFTER instructions,
  ADD COLUMN system_prompt TEXT DEFAULT NULL AFTER prompt_mode,
  ADD COLUMN timezone VARCHAR(60) DEFAULT NULL AFTER language,
  ADD COLUMN additional_languages VARCHAR(255) DEFAULT NULL AFTER timezone,
  ADD COLUMN language_settings LONGTEXT DEFAULT NULL AFTER additional_languages,
  ADD COLUMN llm_provider VARCHAR(20) DEFAULT NULL AFTER llm_model,
  ADD COLUMN voice_response_length VARCHAR(10) NOT NULL DEFAULT 'short' AFTER response_length,
  ADD COLUMN voice_settings LONGTEXT DEFAULT NULL AFTER tts_speed,
  ADD COLUMN interruptible TINYINT(1) NOT NULL DEFAULT 1 AFTER auto_speak;
