-- VoiceAgent initial schema (MySQL 5.7+ / MariaDB 10.3+)

CREATE TABLE IF NOT EXISTS tenants (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  website_url VARCHAR(500) DEFAULT NULL,
  plan_key VARCHAR(40) NOT NULL DEFAULT 'free',
  status ENUM('active','suspended','cancelled') NOT NULL DEFAULT 'active',
  stripe_customer_id VARCHAR(80) DEFAULT NULL,
  stripe_subscription_id VARCHAR(80) DEFAULT NULL,
  subscription_status VARCHAR(40) DEFAULT NULL,
  billing_cycle VARCHAR(10) DEFAULT NULL,
  trial_ends_at DATETIME DEFAULT NULL,
  current_period_end DATETIME DEFAULT NULL,
  settings LONGTEXT DEFAULT NULL,
  onboarding_completed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_tenants_slug (slug),
  KEY idx_tenants_plan (plan_key),
  KEY idx_tenants_stripe_customer (stripe_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','admin','member') NOT NULL DEFAULT 'owner',
  is_super_admin TINYINT(1) NOT NULL DEFAULT 0,
  email_verified_at DATETIME DEFAULT NULL,
  verification_token VARCHAR(80) DEFAULT NULL,
  remember_selector VARCHAR(40) DEFAULT NULL,
  remember_hash CHAR(64) DEFAULT NULL,
  timezone VARCHAR(60) DEFAULT 'UTC',
  last_login_at DATETIME DEFAULT NULL,
  last_login_ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_tenant (tenant_id),
  KEY idx_users_remember (remember_selector)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_password_resets_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_key VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  price_monthly DECIMAL(8,2) NOT NULL DEFAULT 0,
  price_yearly DECIMAL(8,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  stripe_price_monthly VARCHAR(80) DEFAULT NULL,
  stripe_price_yearly VARCHAR(80) DEFAULT NULL,
  limits LONGTEXT DEFAULT NULL,
  features LONGTEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_plans_key (plan_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  public_id CHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  description VARCHAR(500) DEFAULT NULL,
  website_url VARCHAR(500) DEFAULT NULL,
  business_name VARCHAR(160) DEFAULT NULL,
  persona VARCHAR(40) NOT NULL DEFAULT 'friendly',
  instructions TEXT DEFAULT NULL,
  language VARCHAR(10) NOT NULL DEFAULT 'auto',
  llm_model VARCHAR(80) DEFAULT NULL,
  effort VARCHAR(10) NOT NULL DEFAULT 'low',
  response_length VARCHAR(10) NOT NULL DEFAULT 'medium',
  voice_enabled TINYINT(1) NOT NULL DEFAULT 1,
  tts_provider VARCHAR(20) NOT NULL DEFAULT 'auto',
  tts_voice VARCHAR(80) NOT NULL DEFAULT 'alloy',
  tts_speed DECIMAL(3,2) NOT NULL DEFAULT 1.00,
  stt_provider VARCHAR(20) NOT NULL DEFAULT 'auto',
  auto_speak TINYINT(1) NOT NULL DEFAULT 1,
  greeting_message TEXT DEFAULT NULL,
  fallback_message TEXT DEFAULT NULL,
  lead_capture_enabled TINYINT(1) NOT NULL DEFAULT 1,
  lead_fields VARCHAR(255) NOT NULL DEFAULT 'name,email,phone,message',
  lead_instructions TEXT DEFAULT NULL,
  lead_notify_email VARCHAR(190) DEFAULT NULL,
  allowed_domains TEXT DEFAULT NULL,
  widget_config LONGTEXT DEFAULT NULL,
  conversations_count INT UNSIGNED NOT NULL DEFAULT 0,
  messages_count INT UNSIGNED NOT NULL DEFAULT 0,
  leads_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_trained_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_agents_public (public_id),
  KEY idx_agents_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_sources (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL,
  type ENUM('website','url','file','faq','text') NOT NULL,
  title VARCHAR(255) NOT NULL,
  url VARCHAR(1000) DEFAULT NULL,
  file_path VARCHAR(500) DEFAULT NULL,
  file_name VARCHAR(255) DEFAULT NULL,
  file_size INT UNSIGNED DEFAULT NULL,
  mime VARCHAR(100) DEFAULT NULL,
  status ENUM('pending','processing','ready','error') NOT NULL DEFAULT 'pending',
  error_message TEXT DEFAULT NULL,
  settings LONGTEXT DEFAULT NULL,
  stats LONGTEXT DEFAULT NULL,
  last_synced_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_sources_agent (agent_id),
  KEY idx_sources_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL,
  source_id INT UNSIGNED NOT NULL,
  title VARCHAR(500) NOT NULL,
  url VARCHAR(1000) DEFAULT NULL,
  url_hash CHAR(40) DEFAULT NULL,
  content MEDIUMTEXT DEFAULT NULL,
  content_hash CHAR(40) DEFAULT NULL,
  char_count INT UNSIGNED NOT NULL DEFAULT 0,
  chunk_count INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','indexed','error','skipped') NOT NULL DEFAULT 'pending',
  error_message VARCHAR(500) DEFAULT NULL,
  meta LONGTEXT DEFAULT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_documents_agent (agent_id, status),
  KEY idx_documents_source (source_id),
  KEY idx_documents_url (agent_id, url_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_chunks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  chunk_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  heading VARCHAR(300) DEFAULT NULL,
  content TEXT NOT NULL,
  token_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  embedding MEDIUMBLOB DEFAULT NULL,
  embedding_model VARCHAR(80) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_chunks_agent (agent_id),
  KEY idx_chunks_document (document_id),
  FULLTEXT KEY ft_chunks_content (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crawl_urls (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  url VARCHAR(1000) NOT NULL,
  url_hash CHAR(40) NOT NULL,
  depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('queued','done','failed','skipped') NOT NULL DEFAULT 'queued',
  http_status SMALLINT DEFAULT NULL,
  error VARCHAR(255) DEFAULT NULL,
  document_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_crawl_job_url (job_id, url_hash),
  KEY idx_crawl_job_status (job_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL DEFAULT 0,
  type VARCHAR(40) NOT NULL,
  payload LONGTEXT DEFAULT NULL,
  status ENUM('queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  progress_text VARCHAR(255) DEFAULT NULL,
  result LONGTEXT DEFAULT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT DEFAULT NULL,
  run_after DATETIME NOT NULL,
  locked_at DATETIME DEFAULT NULL,
  locked_by VARCHAR(80) DEFAULT NULL,
  heartbeat_at DATETIME DEFAULT NULL,
  started_at DATETIME DEFAULT NULL,
  finished_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_jobs_status (status, run_after),
  KEY idx_jobs_tenant (tenant_id, created_at),
  KEY idx_jobs_agent (agent_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL,
  public_id CHAR(32) NOT NULL,
  visitor_id VARCHAR(64) DEFAULT NULL,
  channel ENUM('text','voice','mixed') NOT NULL DEFAULT 'text',
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  title VARCHAR(255) DEFAULT NULL,
  message_count INT UNSIGNED NOT NULL DEFAULT 0,
  voice_message_count INT UNSIGNED NOT NULL DEFAULT 0,
  has_lead TINYINT(1) NOT NULL DEFAULT 0,
  has_unanswered TINYINT(1) NOT NULL DEFAULT 0,
  rating TINYINT DEFAULT NULL,
  page_url VARCHAR(1000) DEFAULT NULL,
  referrer VARCHAR(1000) DEFAULT NULL,
  user_agent VARCHAR(500) DEFAULT NULL,
  ip_hash CHAR(64) DEFAULT NULL,
  country VARCHAR(2) DEFAULT NULL,
  device VARCHAR(20) DEFAULT NULL,
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  last_message_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_conversations_public (public_id),
  KEY idx_conversations_agent (agent_id, started_at),
  KEY idx_conversations_tenant (tenant_id, started_at),
  KEY idx_conversations_visitor (agent_id, visitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NOT NULL,
  role ENUM('user','assistant','system') NOT NULL,
  content MEDIUMTEXT NOT NULL,
  modality ENUM('text','voice') NOT NULL DEFAULT 'text',
  sources LONGTEXT DEFAULT NULL,
  meta LONGTEXT DEFAULT NULL,
  tokens_input INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_output INT UNSIGNED NOT NULL DEFAULT 0,
  latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
  is_unanswered TINYINT(1) NOT NULL DEFAULT 0,
  feedback TINYINT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_messages_conversation (conversation_id, id),
  KEY idx_messages_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED DEFAULT NULL,
  name VARCHAR(160) DEFAULT NULL,
  email VARCHAR(190) DEFAULT NULL,
  phone VARCHAR(60) DEFAULT NULL,
  message TEXT DEFAULT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'chat',
  status ENUM('new','contacted','qualified','closed','spam') NOT NULL DEFAULT 'new',
  notes TEXT DEFAULT NULL,
  page_url VARCHAR(1000) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_leads_tenant (tenant_id, created_at),
  KEY idx_leads_agent (agent_id, status),
  KEY idx_leads_conversation (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unanswered_questions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED DEFAULT NULL,
  message_id BIGINT UNSIGNED DEFAULT NULL,
  question TEXT NOT NULL,
  question_hash CHAR(40) NOT NULL,
  answer_given TEXT DEFAULT NULL,
  status ENUM('open','resolved','ignored') NOT NULL DEFAULT 'open',
  resolution TEXT DEFAULT NULL,
  resolved_document_id INT UNSIGNED DEFAULT NULL,
  occurrences INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_unanswered_agent (agent_id, status),
  KEY idx_unanswered_hash (agent_id, question_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL DEFAULT 0,
  period CHAR(7) NOT NULL,
  metric VARCHAR(40) NOT NULL,
  value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_usage (tenant_id, agent_id, period, metric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytics_daily (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  conversations INT UNSIGNED NOT NULL DEFAULT 0,
  messages INT UNSIGNED NOT NULL DEFAULT 0,
  voice_messages INT UNSIGNED NOT NULL DEFAULT 0,
  leads INT UNSIGNED NOT NULL DEFAULT 0,
  unanswered INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_input BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tokens_output BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_analytics_day (agent_id, day),
  KEY idx_analytics_tenant (tenant_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value LONGTEXT DEFAULT NULL,
  is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  rl_key VARCHAR(64) NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  reset_at INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED DEFAULT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) DEFAULT NULL,
  entity_id INT UNSIGNED DEFAULT NULL,
  details LONGTEXT DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_activity_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED DEFAULT NULL,
  stripe_event_id VARCHAR(80) NOT NULL,
  type VARCHAR(80) NOT NULL,
  payload LONGTEXT DEFAULT NULL,
  processed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_billing_event (stripe_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visitors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL,
  visitor_id VARCHAR(64) NOT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  conversations INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_visitor (agent_id, visitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
