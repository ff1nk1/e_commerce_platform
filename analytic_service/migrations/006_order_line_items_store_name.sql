ALTER TABLE order_line_items
    ADD COLUMN IF NOT EXISTS store_name String DEFAULT '';
