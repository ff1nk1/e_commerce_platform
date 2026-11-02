ALTER TABLE order_line_items
    ADD COLUMN IF NOT EXISTS category_slug String DEFAULT '';
