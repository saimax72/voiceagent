-- Cache of query embeddings so repeated visitor questions skip the embedding API round trip
CREATE TABLE IF NOT EXISTS embedding_cache (
  hash CHAR(40) NOT NULL PRIMARY KEY,
  model VARCHAR(80) NOT NULL,
  embedding MEDIUMBLOB NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_embedding_cache_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
