CREATE TABLE IF NOT EXISTS product_dim
(
    product_id UInt64,
    store_id UInt64,
    store_name String,
    category_id UInt64,
    category_name String,
    category_slug String,
    kafka_topic LowCardinality(String),
    kafka_partition Int32,
    kafka_offset Int64
)
ENGINE = ReplacingMergeTree
ORDER BY (product_id);
