CREATE TABLE IF NOT EXISTS order_funnel_events
(
    order_id UInt64,
    user_id UInt64,
    event_type LowCardinality(String),
    total_amount Decimal(12, 2),
    reason String,
    occurred_at DateTime64(3, 'UTC'),
    kafka_topic LowCardinality(String),
    kafka_partition Int32,
    kafka_offset Int64
)
ENGINE = ReplacingMergeTree
ORDER BY (order_id, event_type);
