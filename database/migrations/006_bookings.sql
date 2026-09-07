-- Appointment booking: per-agent settings, the appointments themselves and linked external calendars.
ALTER TABLE agents
  ADD COLUMN booking_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER lead_notify_email,
  ADD COLUMN booking_type VARCHAR(40) NOT NULL DEFAULT 'general' AFTER booking_enabled,
  ADD COLUMN booking_settings LONGTEXT NULL AFTER booking_type;

CREATE TABLE IF NOT EXISTS appointments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NULL,
  reference VARCHAR(16) NOT NULL,
  service VARCHAR(160) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  customer_name VARCHAR(160) NULL,
  customer_email VARCHAR(190) NULL,
  customer_phone VARCHAR(60) NULL,
  answers LONGTEXT NULL,
  notes TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
  source VARCHAR(20) NOT NULL DEFAULT 'chat',
  manage_token VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_reference (reference),
  KEY idx_tenant_start (tenant_id, starts_at),
  KEY idx_agent_start (agent_id, starts_at, status),
  KEY idx_manage (manage_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_links (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  ics_url TEXT NOT NULL,
  busy_json LONGTEXT NULL,
  last_synced_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_agent (agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Count bookings alongside the other daily metrics
ALTER TABLE analytics_daily ADD COLUMN bookings INT UNSIGNED NOT NULL DEFAULT 0 AFTER leads;
