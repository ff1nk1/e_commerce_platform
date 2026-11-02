CREATE TABLE IF NOT EXISTS order_line_items
(
    order_id UInt64,
    product_id UInt64,
    store_id UInt64,
    category_id UInt64,
    quantity UInt32,
    unit_price Decimal(12, 2),
    occurred_at DateTime64(3, 'UTC'),
    kafka_topic LowCardinality(String),
    kafka_partition Int32,
    kafka_offset Int64
)
ENGINE = ReplacingMergeTree
ORDER BY (order_id, product_id, store_id);
