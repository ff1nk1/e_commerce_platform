package store

import "context"

// Batcher accumulates rows and flushes them through flushFn in a single call.
// It is not safe for concurrent use: the Kafka consumer drives it from one
// goroutine and flushes between message fetches.
type Batcher[T any] struct {
	max     int
	buf     []T
	flushFn func(ctx context.Context, rows []T) error
}

// NewBatcher returns a Batcher that flushes once max rows are buffered.
func NewBatcher[T any](max int, flushFn func(ctx context.Context, rows []T) error) *Batcher[T] {
	if max < 1 {
		max = 1
	}
	return &Batcher[T]{max: max, flushFn: flushFn}
}

// Add appends a row to the buffer.
func (b *Batcher[T]) Add(row T) { b.buf = append(b.buf, row) }

// AddAll appends several rows to the buffer.
func (b *Batcher[T]) AddAll(rows []T) { b.buf = append(b.buf, rows...) }

// Len reports the number of buffered rows.
func (b *Batcher[T]) Len() int { return len(b.buf) }

// Full reports whether the buffer reached its size limit.
func (b *Batcher[T]) Full() bool { return len(b.buf) >= b.max }

// Flush writes buffered rows in one call. On success the buffer is cleared; on
// error the rows are retained so the caller can retry (the target tables are
// ReplacingMergeTree keyed by their ORDER BY, so a re-insert deduplicates).
func (b *Batcher[T]) Flush(ctx context.Context) error {
	if len(b.buf) == 0 {
		return nil
	}
	if err := b.flushFn(ctx, b.buf); err != nil {
		return err
	}
	b.buf = b.buf[:0]
	return nil
}
