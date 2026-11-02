package store

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"time"

	"github.com/ClickHouse/clickhouse-go/v2/lib/driver"
	"github.com/shopspring/decimal"
)

// Store is the ClickHouse-backed implementation of the analytic read and
// ingest ports. All methods bound their ClickHouse calls with queryTimeout so
// a slow/hung server cannot block a request or consumer indefinitely.
type Store struct {
	conn         driver.Conn
	queryTimeout time.Duration
}

func New(conn driver.Conn) *Store {
	return &Store{conn: conn, queryTimeout: 5 * time.Second}
}

// SetQueryTimeout overrides the per-call ClickHouse timeout (from config).
func (s *Store) SetQueryTimeout(d time.Duration) {
	if d > 0 {
		s.queryTimeout = d
	}
}

func (s *Store) withQuery(ctx context.Context) (context.Context, context.CancelFunc) {
	return context.WithTimeout(ctx, s.queryTimeout)
}

func (s *Store) Ping(ctx context.Context) error {
	ctx, cancel := s.withQuery(ctx)
	defer cancel()
	return s.conn.Ping(ctx)
}

func appendTimeRange(q string, args []any, col string, from, to *time.Time) (string, []any) {
	if from != nil {
		q += fmt.Sprintf(" AND %s >= ?", col)
		args = append(args, *from)
	}
	if to != nil {
		q += fmt.Sprintf(" AND %s < ?", col)
		args = append(args, *to)
	}
	return q, args
}

func conversionsFromFunnel(funnel OrderFunnel) OrderConversions {
	var conv OrderConversions
	createdCount := float64(funnel.Created)
	if createdCount > 0 {
		paidCount := float64(funnel.Paid)
		cancelledCount := float64(funnel.Cancelled)
		conv.CreatedToPaid = paidCount / createdCount
		conv.CreatedToCancelled = cancelledCount / createdCount
	}
	return conv
}

// --- batch inserts (one ClickHouse insert per buffered batch) ---

func (s *Store) InsertUserRegistrations(ctx context.Context, rows []UserRegistration) error {
	if len(rows) == 0 {
		return nil
	}
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	batch, err := s.conn.PrepareBatch(ctx, `
		INSERT INTO user_registrations
			(user_id, email, name, registered_at, kafka_topic, kafka_partition, kafka_offset)
	`)
	if err != nil {
		return fmt.Errorf("prepare user batch: %w", err)
	}
	for _, row := range rows {
		if err := batch.Append(
			row.UserID,
			row.Email,
			row.Name,
			row.RegisteredAt,
			row.KafkaTopic,
			row.KafkaPartition,
			row.KafkaOffset,
		); err != nil {
			return fmt.Errorf("append user: %w", err)
		}
	}
	return batch.Send()
}

func (s *Store) InsertOrderFunnelEvents(ctx context.Context, rows []OrderFunnelEvent) error {
	if len(rows) == 0 {
		return nil
	}
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	batch, err := s.conn.PrepareBatch(ctx, `
		INSERT INTO order_funnel_events
			(order_id, user_id, event_type, total_amount, reason, occurred_at, kafka_topic, kafka_partition, kafka_offset)
	`)
	if err != nil {
		return fmt.Errorf("prepare order batch: %w", err)
	}
	for _, row := range rows {
		if err := batch.Append(
			row.OrderID,
			row.UserID,
			row.EventType,
			row.TotalAmount,
			row.Reason,
			row.OccurredAt,
			row.KafkaTopic,
			row.KafkaPartition,
			row.KafkaOffset,
		); err != nil {
			return fmt.Errorf("append order: %w", err)
		}
	}
	return batch.Send()
}

func (s *Store) InsertOrderLineItems(ctx context.Context, rows []OrderLineItem) error {
	if len(rows) == 0 {
		return nil
	}
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	batch, err := s.conn.PrepareBatch(ctx, `
		INSERT INTO order_line_items
			(order_id, product_id, store_id, store_name, category_id, category_name, category_slug, quantity, unit_price, occurred_at, kafka_topic, kafka_partition, kafka_offset)
	`)
	if err != nil {
		return fmt.Errorf("prepare line items batch: %w", err)
	}
	for _, row := range rows {
		if err := batch.Append(
			row.OrderID,
			row.ProductID,
			row.StoreID,
			row.StoreName,
			row.CategoryID,
			row.CategoryName,
			row.CategorySlug,
			row.Quantity,
			row.UnitPrice,
			row.OccurredAt,
			row.KafkaTopic,
			row.KafkaPartition,
			row.KafkaOffset,
		); err != nil {
			return fmt.Errorf("append line item: %w", err)
		}
	}
	return batch.Send()
}

func (s *Store) InsertProductDims(ctx context.Context, rows []ProductDim) error {
	if len(rows) == 0 {
		return nil
	}
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	batch, err := s.conn.PrepareBatch(ctx, `
		INSERT INTO product_dim
			(product_id, store_id, store_name, category_id, category_name, category_slug, kafka_topic, kafka_partition, kafka_offset)
	`)
	if err != nil {
		return fmt.Errorf("prepare product_dim batch: %w", err)
	}
	for _, row := range rows {
		if err := batch.Append(
			row.ProductID,
			row.StoreID,
			row.StoreName,
			row.CategoryID,
			row.CategoryName,
			row.CategorySlug,
			row.KafkaTopic,
			row.KafkaPartition,
			row.KafkaOffset,
		); err != nil {
			return fmt.Errorf("append product_dim: %w", err)
		}
	}
	return batch.Send()
}

func (s *Store) InsertSearchQueries(ctx context.Context, rows []SearchQuery) error {
	if len(rows) == 0 {
		return nil
	}
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	batch, err := s.conn.PrepareBatch(ctx, `
		INSERT INTO search_queries
			(query, results_count, filters, sort, occurred_at, kafka_topic, kafka_partition, kafka_offset)
	`)
	if err != nil {
		return fmt.Errorf("prepare search batch: %w", err)
	}
	for _, row := range rows {
		if err := batch.Append(
			row.Query,
			row.ResultsCount,
			row.Filters,
			row.Sort,
			row.OccurredAt,
			row.KafkaTopic,
			row.KafkaPartition,
			row.KafkaOffset,
		); err != nil {
			return fmt.Errorf("append search: %w", err)
		}
	}
	return batch.Send()
}

func (s *Store) LookupProductDim(ctx context.Context, productID uint64) (ProductDim, bool, error) {
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	var out ProductDim
	err := s.conn.QueryRow(ctx, `
		SELECT product_id, store_id, store_name, category_id, category_name, category_slug
		FROM product_dim FINAL
		WHERE product_id = ?
		LIMIT 1
	`, productID).Scan(
		&out.ProductID,
		&out.StoreID,
		&out.StoreName,
		&out.CategoryID,
		&out.CategoryName,
		&out.CategorySlug,
	)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return out, false, nil
		}
		return out, false, fmt.Errorf("lookup product_dim: %w", err)
	}
	return out, out.ProductID != 0, nil
}

// DecodeAmount parses a money string into a decimal, defaulting to zero on
// malformed input. Kept here so handlers never touch raw money strings.
func DecodeAmount(raw string) decimal.Decimal {
	d, err := decimal.NewFromString(raw)
	if err != nil {
		return decimal.Zero
	}
	return d
}
