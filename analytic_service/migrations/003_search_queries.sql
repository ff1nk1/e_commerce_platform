CREATE TABLE IF NOT EXISTS search_queries
(
    query String,
    results_count UInt32,
    filters String,
    sort LowCardinality(String),
    occurred_at DateTime64(3, 'UTC'),
    kafka_topic LowCardinality(String),
    kafka_partition Int32,
    kafka_offset Int64
)
ENGINE = ReplacingMergeTree
ORDER BY (kafka_topic, kafka_partition, kafka_offset);
