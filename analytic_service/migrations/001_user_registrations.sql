CREATE TABLE IF NOT EXISTS user_registrations
(
    user_id UInt64,
    email String,
    name String,
    registered_at DateTime64(3, 'UTC'),
    kafka_topic LowCardinality(String),
    kafka_partition Int32,
    kafka_offset Int64
)
ENGINE = ReplacingMergeTree
ORDER BY (user_id);
