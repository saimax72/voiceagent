-- The Business plan advertises "Unlimited AI agents" but was seeded with a hard cap of 100,
-- so the dashboard showed "1 / 100". Zero is the convention for unlimited everywhere else.
UPDATE plans SET limits = JSON_SET(limits, '$.agents', 0), updated_at = UTC_TIMESTAMP()
  WHERE plan_key = 'business' AND CAST(JSON_EXTRACT(limits, '$.agents') AS UNSIGNED) = 100;
