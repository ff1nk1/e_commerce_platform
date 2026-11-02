package kafka

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"time"

	"analytic_service/internal/store"

	"github.com/segmentio/kafka-go"
	"golang.org/x/sync/errgroup"
)

// EventStore is the single ingest port the consumers write through.
type EventStore interface {
	InsertUserRegistrations(ctx context.Context, rows []store.UserRegistration) error
	InsertOrderFunnelEvents(ctx context.Context, rows []store.OrderFunnelEvent) error
	InsertOrderLineItems(ctx context.Context, rows []store.OrderLineItem) error
	InsertProductDims(ctx context.Context, rows []store.ProductDim) error
	InsertSearchQueries(ctx context.Context, rows []store.SearchQuery) error
	LookupProductDim(ctx context.Context, productID uint64) (store.ProductDim, bool, error)
}

// Config tunes the consumer runtime.
type Config struct {
	Brokers       []string
	BatchMaxRows  int
	FlushInterval time.Duration
	RetryBackoff  time.Duration
	DLQSuffix     string
}

func (c Config) withDefaults() Config {
	if c.BatchMaxRows < 1 {
		c.BatchMaxRows = 500
	}
	if c.FlushInterval <= 0 {
		c.FlushInterval = time.Second
	}
	if c.RetryBackoff <= 0 {
		c.RetryBackoff = time.Second
	}
	if c.DLQSuffix == "" {
		c.DLQSuffix = ".dlq"
	}
	return c
}

// messageReader is the subset of *kafka.Reader the consumer loop needs. It is
// an interface so tests can drive the commit/DLQ ordering without a broker.
type messageReader interface {
	FetchMessage(ctx context.Context) (kafka.Message, error)
	CommitMessages(ctx context.Context, msgs ...kafka.Message) error
	Close() error
}

// readerFactory builds a messageReader; tests swap it for a fake.
type readerFactory func(cfg kafka.ReaderConfig) messageReader

func defaultReaderFactory(cfg kafka.ReaderConfig) messageReader { return kafka.NewReader(cfg) }

// Consumers runs the four analytic consumer groups, buffering rows into
// microbatches and committing Kafka offsets only after the batched write to
// ClickHouse succeeded. Poison-pill messages are diverted to a DLQ so a single
// bad payload cannot stall a partition.
type Consumers struct {
	cfg     Config
	store   EventStore
	dlq     DLQPublisher
	log     *slog.Logger
	newRead readerFactory
}

// NewConsumers wires the consumers. A nil DLQ publisher uses a kafka-go writer
// against the same brokers.
func NewConsumers(cfg Config, st EventStore, dlq DLQPublisher, log *slog.Logger) *Consumers {
	if log == nil {
		log = slog.Default()
	}
	cfg = cfg.withDefaults()
	if dlq == nil {
		dlq = NewKafkaDLQ(cfg.Brokers)
	}
	return &Consumers{cfg: cfg, store: st, dlq: dlq, log: log, newRead: defaultReaderFactory}
}

// Close releases the DLQ producer.
func (c *Consumers) Close() error {
	if c.dlq == nil {
		return nil
	}
	return c.dlq.Close()
}

// consumerSink buffers a topic's rows and knows whether the buffer is full.
type consumerSink interface {
	handle(ctx context.Context, msg kafka.Message) error
	full() bool
	flush(ctx context.Context) error
}

type subscription struct {
	group  string
	topics []string
	sink   consumerSink
}

func (c *Consumers) Run(ctx context.Context) error {
	subs := []subscription{
		{group: "analytic-service-users-v3", topics: []string{"user.events"}, sink: newUserSink(c.store, c.cfg.BatchMaxRows)},
		{group: "analytic-service-orders-v5", topics: []string{"order.events"}, sink: newOrderSink(c.store, c.cfg.BatchMaxRows, c.log)},
		{group: "analytic-service-search-v3", topics: []string{"search.events"}, sink: newSearchSink(c.store, c.cfg.BatchMaxRows)},
		{group: "analytic-service-products-v1", topics: []string{"product.events"}, sink: newProductSink(c.store, c.cfg.BatchMaxRows)},
	}

	g, ctx := errgroup.WithContext(ctx)
	for _, sub := range subs {
		g.Go(func() error {
			return c.runReader(ctx, sub.group, sub.topics, sub.sink)
		})
	}
	return g.Wait()
}

const maxKafkaMessageBytes = 10e6 // 10 MiB

type fetchResult struct {
	msg kafka.Message
	err error
}

func (c *Consumers) runReader(ctx context.Context, groupID string, topics []string, sink consumerSink) error {
	cfg := kafka.ReaderConfig{
		Brokers:        c.cfg.Brokers,
		GroupID:        groupID,
		MinBytes:       1,
		MaxBytes:       maxKafkaMessageBytes,
		MaxWait:        time.Second,
		StartOffset:    kafka.FirstOffset,
		CommitInterval: 0, // committed manually, only after a successful batch flush
	}
	if len(topics) == 1 {
		cfg.Topic = topics[0]
	} else {
		cfg.GroupTopics = topics
	}

	r := c.newRead(cfg)
	defer func() { _ = r.Close() }()

	c.log.Info("kafka consumer started", "group", groupID, "topics", topics)

	// Fetch on a separate goroutine so the flush ticker can fire while the
	// broker has nothing to deliver. The unbuffered channel bounds prefetch to
	// one message, keeping checkpointing in order.
	fetchCh := make(chan fetchResult)
	go func() {
		for {
			msg, err := r.FetchMessage(ctx)
			select {
			case fetchCh <- fetchResult{msg: msg, err: err}:
			case <-ctx.Done():
				return
			}
			if err != nil {
				return
			}
		}
	}()

	ticker := time.NewTicker(c.cfg.FlushInterval)
	defer ticker.Stop()

	var committable []kafka.Message

	// flushAndCommit writes buffered rows, then commits every message accounted
	// for since the last commit. Offsets only advance after the write succeeded.
	flushAndCommit := func() error {
		if len(committable) == 0 && !sink.full() {
			return nil
		}
		if err := sink.flush(ctx); err != nil {
			return err
		}
		if len(committable) > 0 {
			if err := r.CommitMessages(ctx, committable...); err != nil {
				return fmt.Errorf("%s commit: %w", groupID, err)
			}
			committable = committable[:0]
		}
		return nil
	}

	// retry runs fn until it succeeds or the context is cancelled, backing off
	// between attempts. Returns false when the context ended.
	retry := func(what string, fn func() error) bool {
		for {
			err := fn()
			if err == nil {
				return true
			}
			c.log.Error("retrying", "group", groupID, "stage", what, "err", err)
			select {
			case <-ctx.Done():
				return false
			case <-time.After(c.cfg.RetryBackoff):
			}
		}
	}

	// finalFlush drains the buffer on shutdown using a fresh context, since ctx
	// is already cancelled.
	defer func() {
		flushCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		if err := sink.flush(flushCtx); err != nil {
			c.log.Error("final flush failed", "group", groupID, "err", err)
			return
		}
		if len(committable) > 0 {
			if err := r.CommitMessages(flushCtx, committable...); err != nil {
				c.log.Error("final commit failed", "group", groupID, "err", err)
			}
		}
	}()

	for {
		if sink.full() {
			if !retry("flush", flushAndCommit) {
				return nil
			}
			continue
		}

		select {
		case <-ctx.Done():
			return nil
		case <-ticker.C:
			if !retry("flush", flushAndCommit) {
				return nil
			}
		case fr := <-fetchCh:
			if fr.err != nil {
				if ctx.Err() != nil {
					return nil
				}
				return fmt.Errorf("%s fetch: %w", groupID, fr.err)
			}

			err := sink.handle(ctx, fr.msg)
			switch {
			case err == nil:
				committable = append(committable, fr.msg)
			case isPermanent(err):
				c.log.Warn("poison message, moving to dlq",
					"group", groupID, "topic", fr.msg.Topic,
					"partition", fr.msg.Partition, "offset", fr.msg.Offset, "err", err)
				if !retry("dlq", func() error {
					return c.dlq.Publish(ctx, fr.msg.Topic+c.cfg.DLQSuffix, fr.msg, err)
				}) {
					return nil
				}
				// Persist previously buffered rows before advancing the offset
				// past this message, then commit past the poisoned message.
				if !retry("flush", func() error { return sink.flush(ctx) }) {
					return nil
				}
				committable = append(committable, fr.msg)
				if !retry("commit", func() error {
					if err := r.CommitMessages(ctx, committable...); err != nil {
						return fmt.Errorf("%s commit: %w", groupID, err)
					}
					committable = committable[:0]
					return nil
				}) {
					return nil
				}
			default:
				// Transient (network / ClickHouse) failure: retry the same
				// message rather than fetching the next one.
				if !retry("handle", func() error { return sink.handle(ctx, fr.msg) }) {
					return nil
				}
				committable = append(committable, fr.msg)
			}
		}
	}
}

// decodeEvent unmarshals a payload into a typed event. ok=false means the
// payload was empty (nothing to record); a non-nil error is permanent (the
// message is unparseable and belongs in the DLQ).
func decodeEvent(msg kafka.Message, v any) (ok bool, err error) {
	if len(msg.Value) == 0 {
		return false, nil
	}
	if err := json.Unmarshal(msg.Value, v); err != nil {
		return false, Permanentf("invalid json: %w", err)
	}
	return true, nil
}
